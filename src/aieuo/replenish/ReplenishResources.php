<?php

namespace aieuo\replenish;

use aieuo\formAPI\CustomForm;
use aieuo\formAPI\element\Button;
use aieuo\formAPI\element\NumberInput;
use aieuo\formAPI\element\Toggle;
use aieuo\formAPI\ListForm;
use aieuo\mineflow\flowItem\FlowItemFactory;
use aieuo\replenish\mineflow\action\ReplenishResource;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\TaskHandler;
use pocketmine\Server;
use pocketmine\utils\Config;
use aieuo\mineflow\utils\Language as MineflowLanguage;

class ReplenishResources extends PluginBase implements Listener {

    /** @var TaskHandler|null */
    private $taskHandler;

    /* @var Config */
    private $setting;

    /* @var ReplenishResourcesAPI */
    private $api;

    /** @var string[] */
    private $break;

    /** @var Position[] */
    private $pos1;
    /** @var Position[] */
    private $pos2;

    /** @var array */
    private $tap;

    /** @var float[][] */
    private $time;

    /** @noinspection ReturnTypeCanBeDeclaredInspection */
    public function onLoad() {
        if (Server::getInstance()->getPluginManager()->getPlugin("Mineflow") !== null) {
            $this->registerMineflowActions();
        }
    }

    /** @noinspection ReturnTypeCanBeDeclaredInspection */
    public function onEnable() {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        if (!file_exists($this->getDataFolder())) @mkdir($this->getDataFolder(), 0721, true);
        $this->setting = new Config($this->getDataFolder()."setting.yml", Config::YAML, [
            "enable-wait" => true,
            "wait" => 60,
            "sneak" => true,
            "announcement" => false,
            "enable-count" => true,
            "count" => 0,
            "check-inside" => true,
            "period" => 1,
            "tick-place" => 100,
            "enable-auto-replenish" => true,
            "auto-replenish-time" => 3600,
            "auto-replenish-resources" => [],
        ]);
        $this->setting->save();

        $this->checkConfig();

        $this->api = new ReplenishResourcesAPI($this, $this->getConfig(), $this->setting);

        if ($this->setting->get("enable-auto-replenish")) {
            $time = (float)$this->setting->get("auto-replenish-time", 60) * 20;
            $this->startAutoReplenishTask($time);
        }

        if (Server::getInstance()->getPluginManager()->getPlugin("Mineflow") !== null) {
            $this->registerMineflowLanguage();
        }
    }

    /** @noinspection ReturnTypeCanBeDeclaredInspection */
    public function onDisable() {
        $this->setting->save();
    }

    public function registerMineflowLanguage(): void {
        foreach ($this->getResources() as $resource) {
            $filenames = explode(".", $resource->getFilename());
            if (($filenames[1] ?? "") === "ini") {
                MineflowLanguage::add(parse_ini_file($resource->getPathname()), $filenames[0]);
            }
        }
    }

    public function registerMineflowActions(): void {
        FlowItemFactory::register(new ReplenishResource());
    }

    public function checkConfig(): void {
        if (version_compare("2.4.0", $this->setting->get("version", ""), "<=")) return;
        $version = $this->getDescription()->getVersion();
        $this->setting->set("version", $version);
        $resources = [];
        foreach ($this->getConfig()->getAll() as $place => $resource) {
            if (isset($resource["id"]["id"]) or isset($resource["id"]["damage"])) {
                $resource["id"] = [
                    [
                        "id" => $resource["id"]["id"],
                        "damage" => $resource["id"]["damage"],
                        "per" => 1,
                    ]
                ];
            }
            $resources[$place] = $resource;
        }
        $this->getConfig()->setAll($resources);
        $this->getConfig()->save();
    }

    public function startAutoReplenishTask(int $time): void {
        if ($time === 0) {
            if ($this->taskHandler instanceof TaskHandler and !$this->taskHandler->isCancelled()) {
                $this->getScheduler()->cancelTask($this->taskHandler->getTaskId());
            }
            $this->taskHandler = null;
            return;
        }

        if ($this->taskHandler instanceof TaskHandler and !$this->taskHandler->isCancelled()) {
            if ($time === $this->taskHandler->getPeriod()) return;
            $this->getScheduler()->cancelTask($this->taskHandler->getTaskId());
        }

        $this->taskHandler = $this->getScheduler()->scheduleRepeatingTask(new AutoReplenishTask(), $time);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$command->testPermission($sender)) return false;

        if (!($sender instanceof Player)) {
            $sender->sendMessage("コンソールからは使用できません");
            return true;
        }
        if (!isset($args[0])) {
            $sender->sendMessage("Usage: /reso <pos1 | pos2 | add | del | change | cancel | auto | setting>");
            return true;
        }

        $name = $sender->getName();
        switch ($args[0]) {
            case 'pos1':
            case 'pos2':
                $this->break[$name] = $args[0];
                $sender->sendMessage("ブロックを壊してください");
                break;
            case 'add':
                if (!isset($args[1])) {
                    $sender->sendMessage("Usage: /reso add <id>");
                    return true;
                }
                if (!isset($this->pos1[$name]) or !isset($this->pos2[$name])) {
                    $sender->sendMessage("まず/reso pos1と/reso pos2で範囲を設定してください");
                    return true;
                }
                if ($this->pos1[$name]->level->getFolderName() !== $this->pos2[$name]->level->getFolderName()) {
                    $sender->sendMessage("pos1とpos2は同じワールドに設定してください");
                    return true;
                }
                $this->tap[$name] = ["type" => "add", "id" => $args[1]];
                $sender->sendMessage("追加する看板をタップしてください");
                break;
            case 'del':
                $this->tap[$name] = ["type" => "del"];
                $sender->sendMessage("削除する看板をタップしてください");
                break;
            case "change":
                if (!isset($args[1])) {
                    $sender->sendMessage("Usage: /reso change <id>");
                    return true;
                }
                $this->tap[$name] = ["type" => "change", "id" => $args[1]];
                $sender->sendMessage("変更する看板をタップしてください");
                break;
            case 'cancel':
                unset($this->pos1[$name], $this->pos2[$name], $this->tap[$name], $this->break[$name]);
                $sender->sendMessage("キャンセルしました");
                break;
            case 'auto':
                if (!isset($args[1])) {
                    $sender->sendMessage("Usage: /reso auto <add | del>");
                    return true;
                }
                switch ($args[1]) {
                    case 'add':
                        $this->tap[$name] = ["type" => "auto_add"];
                        $sender->sendMessage("追加する看板をタップしてください");
                        break;
                    case 'del':
                        $this->tap[$name] = ["type" => "auto_del"];
                        $sender->sendMessage("削除する看板をタップしてください");
                        break;
                    default:
                        $sender->sendMessage("Usage: /reso auto <add | del>");
                        break;
                }
                break;
            case 'setting':
                $this->sendSettingForm($sender);
                break;
            default:
                $sender->sendMessage("Usage: /reso <pos1 | pos2 | add | del | change | cancel | auto | setting>");
                break;
        }
        return true;
    }

    public function onBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $name = $player->getName();
        if (isset($this->break[$name])) {
            $event->setCancelled();
            $type = $this->break[$name];
            switch ($type) {
                case 'pos1':
                case 'pos2':
                    $this->{$type}[$name] = $block;
                    $player->sendMessage($type."を設定しました (".$block->x.",".$block->y.",".$block->z.",".$block->level->getFolderName().")");
                    break;
            }
            unset($this->break[$name]);
            return;
        }

        if ((($block->getId() === 63 or $block->getId() === 68) and !$event->isCancelled()) and $this->api->existsResource($block)) {
            if (!$player->isOp()) {
                $player->sendMessage("§cこの看板は壊せません");
                $event->setCancelled();
                return;
            }
            $player->sendMessage("補充看板を壊しました");
            $this->api->removeResource($block);
        }
    }

    public function onTouch(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $name = $player->getName();

        if ($block->getId() !== 63 and $block->getId() !== 68) return;

        if (isset($this->tap[$name])) {
            $event->setCancelled();
            switch ($this->tap[$name]["type"]) {
                case 'add':
                    $ids = array_map(function ($id2) {
                        $ids2 = explode(":", $id2);
                        return ["id" => $ids2[0], "damage" => $ids2[1] ?? 0, "per" => $ids2[2] ?? 100];
                    }, explode(",", $this->tap[$name]["id"]));
                    $this->api->addResource($block, $this->pos1[$name], $this->pos2[$name], $ids);
                    $player->sendMessage("追加しました");
                    break;
                case 'change':
                    if (!$this->api->existsResource($block)) {
                        $player->sendMessage("その場所にはまだ追加されていません");
                        return;
                    }
                    $ids = array_map(function ($id2) {
                        $ids2 = explode(":", $id2);
                        return ["id" => $ids2[0], "damage" => $ids2[1] ?? 0, "per" => $ids2[2] ?? 100];
                    }, explode(",", $this->tap[$name]["id"]));
                    $this->api->updateResource($block, "id", $ids);
                    $player->sendMessage("変更しました");
                    break;
                case 'del':
                    if ($this->api->removeResource($block)) {
                        $player->sendMessage("削除しました");
                    } else {
                        $player->sendMessage("その場所には登録されていません");
                    }
                    break;
                case 'auto_add':
                    if (!$this->api->existsResource($block)) {
                        $player->sendMessage("それは補充看板ではありません");
                        return;
                    }
                    if (!$this->api->addAutoReplenishResource($block)) {
                        $player->sendMessage("すでに追加されています");
                        return;
                    }
                    $player->sendMessage("追加しました");
                    if (!$this->setting->get("enable-auto-replenish")) $player->sendMessage("§e自動補充がオフになっています。/reso settingでオンにしてください");
                    break;
                case 'auto_del':
                    if (!$this->api->removeAutoReplenishResource($block)) {
                        $player->sendMessage("まだ追加されていません");
                        return;
                    }
                    $player->sendMessage("削除しました");
                    break;
            }
            unset($this->tap[$name]);
            return;
        }

        if (!$this->api->existsResource($block)) return;

        $place = $block->x.",".$block->y.",".$block->z.",".$block->level->getFolderName();

        if ($this->setting->get("sneak", false) and !$player->isSneaking()) {
            $player->sendMessage("スニークしながらタップすると補充します");
            return;
        }
        if ($this->setting->get("enable-wait", false) and (float)$this->setting->get("wait") > 0) {
            $time = $this->checkTime($player->getName(), $place);
            if ($time !== true) {
                $player->sendMessage($this->setting->get("wait")."秒以内に使用しています\nあと".round($time, 1)."秒お待ちください");
                return;
            }
        }
        $resource = $this->api->getResource($block);
        if ($this->setting->get("check-inside", false)) {
            $players = $player->level->getPlayers();
            $inside = false;
            foreach ($players as $p) {
                if ($resource["level"] === $p->level->getFolderName() and $resource["startx"] <= floor($p->x) and floor($p->x) <= $resource["endx"] and $resource["starty"] <= floor($p->y) and floor($p->y) <= $resource["endy"] and $resource["startz"] <= floor($p->z) and floor($p->z) <= $resource["endz"]) {
                    $p->sendTip("§e".$name."があなたのいる資源を補充しようとしています");
                    $inside = true;
                }
            }
            if ($inside) {
                $player->sendMessage("資源内にプレイヤーがいるため補充できません");
                return;
            }
        }
        $allow = (int)$this->setting->get("count");
        if ($this->setting->get("enable-count", false) and $allow >= 0) {
            $count = $this->countBlocks($resource);
            if ($count > $allow) {
                $player->sendMessage("まだブロックが残っています (".($count - $allow).")");
                return;
            }
        }
        if ($this->setting->get("announcement")) $this->getServer()->broadcastMessage($name."さんが資源(".$place.")の補充を行います");
        $this->api->replenish($block);
    }

    public function checkTime(string $name, string $type) {
        if (!isset($this->time[$name][$type])) {
            $this->time[$name][$type] = microtime(true);
            return true;
        }
        $time = microtime(true) - $this->time[$name][$type];
        if ($time <= (float)$this->setting->get("wait")) {
            return (float)$this->setting->get("wait") - $time;
        }
        $this->time[$name][$type] = microtime(true);
        return true;
    }

    public function countBlocks(array $data): int {
        $sx = $data["startx"];
        $sy = $data["starty"];
        $sz = $data["startz"];
        $ex = $data["endx"];
        $ey = $data["endy"];
        $ez = $data["endz"];
        $level = $this->getServer()->getLevelByName($data["level"]);
        if ($level === null) return 0;

        $count = 0;
        for ($x = $sx; $x <= $ex; $x++) {
            for ($y = $sy; $y <= $ey; $y++) {
                for ($z = $sz; $z <= $ez; $z++) {
                    $block = $level->getBlock(new Vector3($x, $y, $z));
                    if ($block->getId() !== 0) $count++;
                }
            }
        }
        return $count;
    }

    public function sendSettingForm(Player $player, array $messages = []): void {
        if (!$this->setting->get("sneak")) {
            $sneak = "[スニーク] 今は§cOFF§r (スニークしなくても反応します)";
        } else {
            $sneak = "[スニーク] 今は§bON§r (スニークしないと反応しません)";
        }
        if (!$this->setting->get("announcement")) {
            $announcement = "[アナウンス] 今は§cOFF§r (補充時にみんなに知らせません)";
        } else {
            $announcement = "[アナウンス] 今は§bON§r (補充時にみんなに知らせます)";
        }
        if (($time = (float)$this->setting->get("wait")) <= 0 or !$this->setting->get("enable-wait")) {
            $wait = "[連続補充の制限] 今は§cOFF§r (連続補充を制限しません)";
        } else {
            $wait = "[連続補充の制限] 今は§bON§r (同じ看板のタップを".$time."秒間制限します)";
        }
        if (($check = (int)$this->setting->get("count")) === -1 or !$this->setting->get("enable-count")) {
            $count = "[残さずに掘る] 今は§cOFF§r (ブロックが残っていても補充します)";
        } else {
            $count = "[残さずに掘る] 今は§bON§r (残っているブロックが".$check."個以下の時だけ補充します)";
        }
        if (!$this->setting->get("check-inside")) {
            $inside = "[資源内のプレイヤー確認] 今は§cOFF§r (資源内にプレイヤーがいても補充します)";
        } else {
            $inside = "[資源内のプレイヤー確認] 今は§bON§r (資源内にプレイヤーがいると補充しません)";
        }
        if (!$this->setting->get("enable-auto-replenish")) {
            $autoReplenish = "[自動補充] 今は§cOFF§r (設定した資源を定期的に補充しません)";
        } else {
            $autoReplenish = "[自動補充] 今は§bON§r (設定した資源を".$this->setting->get("auto-replenish-time")."に1回補充します)";
        }
        $place = "[補充] ".$this->setting->get("tick-place", 100)."ブロック置いて".$this->setting->get("period")."tick待つ";

        (new ListForm("設定"))->setContent("§7ボタンを押してください")->setButtons([
                new Button($sneak),
                new Button($announcement),
                new Button($wait),
                new Button($count),
                new Button($inside),
                new Button($place),
                new Button($autoReplenish),
                new Button("終了"),
            ])->onReceive(function (Player $player, int $data) {
                switch ($data) {
                    case 0:
                        if (!$this->setting->get("sneak")) {
                            $this->setting->set("sneak", true);
                            $message = "スニークをオンにしました";
                        } else {
                            $this->setting->set("sneak", false);
                            $message = "スニークをオフにしました";
                        }
                        break;
                    case 1:
                        if (!$this->setting->get("announcement")) {
                            $this->setting->set("announcement", true);
                            $message = "アナウンスをオンにしました";
                        } else {
                            $this->setting->set("announcement", false);
                            $message = "アナウンスをオフにしました";
                        }
                        break;
                    case 2:
                        if (!$this->setting->get("enable-wait")) {
                            $this->sendWaitSettingForm($player);
                            return;
                        }

                        $this->setting->set("enable-wait", false);
                        $message = "連続補充の制限をオフにしました";
                        break;
                    case 3:
                        if (!$this->setting->get("enable-count")) {
                            $this->sendCountSettingForm($player);
                            return;
                        }

                        $this->setting->set("enable-count", false);
                        $message = "ブロックが残っていても補充するようにしました";
                        break;
                    case 4:
                        if (!$this->setting->get("check-inside")) {
                            $this->setting->set("check-inside", true);
                            $message = "資源内にプレイヤーがいると補充できないようにしました";
                        } else {
                            $this->setting->set("check-inside", false);
                            $message = "資源内にプレイヤーがいても補充できるようにしました";
                        }
                        break;
                    case 5:
                        $this->sendPlaceSettingForm($player);
                        return;
                    case 6:
                        if (!$this->setting->get("enable-auto-replenish")) {
                            $this->sendAutoReplenishSettingForm($player);
                            return;
                        }

                        $this->setting->set("enable-auto-replenish", false);
                        $this->startAutoReplenishTask(0);
                        $message = "自動補充をしないようにしました";
                        break;
                    default:
                        return;
                }
                $player->sendMessage($message);
                $this->sendSettingForm($player, [$message]);
            })->addMessages($messages)->show($player);
    }

    public function sendWaitSettingForm(Player $player, array $default = [], array $errors = []): void {
        (new CustomForm("設定 > 連続補充の制限"))->setContents([
                new NumberInput("制限する秒数を入力してください", "1秒より長く設定してください", $default[0] ?? (string)$this->setting->get("wait"), true, 1),
                new Toggle("初期値に戻す (60秒)", $default[1] ?? false),
            ])->onReceive(function (Player $player, array $data) {
                if ($data[1]) $data[0] = 60;
                $this->setting->set("enable-wait", true);
                $this->setting->set("wait", (float)$data[0]);
                $player->sendMessage("連続補充の制限を".(float)$data[0]."秒に設定しました");
                $this->sendSettingForm($player, ["連続補充の制限を".(float)$data[0]."秒に設定しました"]);
            })->addErrors($errors)->show($player);
    }

    public function sendCountSettingForm(Player $player, array $default = [], array $errors = []): void {
        (new CustomForm("設定 > 残さずに掘る"))->setContents([
                new NumberInput("残っていてもいいブロックの数を入力してください", "0個以上で設定してください", $default[0] ?? (string)$this->setting->get("count"), true, 0),
                new Toggle("初期値に戻す (0個)", $default[1] ?? false),
            ])->onReceive(function (Player $player, array $data) {
                if ($data[1]) $data[0] = 0;
                $this->setting->set("enable-count", true);
                $this->setting->set("count", (int)$data[0]);
                $player->sendMessage("残っているブロックの数が".(int)$data[0]."個以下の時だけ補充するようにしました");
                $this->sendSettingForm($player, ["残っているブロックの数が".(int)$data[0]."個以下の時だけ補充するようにしました"]);
            })->addErrors($errors)->show($player);
    }

    public function sendPlaceSettingForm(Player $player, array $default = [], array $errors = []): void {
        (new CustomForm("設定 > 補充"))->setContents([
                new NumberInput("一度に置くブロックの数", "1以上で設定してください", $default[0] ?? (string)$this->setting->get("tick-place"), true, 1),
                new NumberInput("ブロックを置いてから待機するtick数", "1以上で設定してください", $default[1] ?? (string)$this->setting->get("period"), true, 1),
                new Toggle("初期値に戻す (100個, 1tick)"),
            ])->onReceive(function (Player $player, array $data) {
                if ($data[2]) {
                    $data[0] = 100;
                    $data[1] = 1;
                }

                $this->setting->set("tick-place", (int)$data[0]);
                $this->setting->set("period", (int)$data[1]);
                $player->sendMessage("一度に置くブロックに数を".(int)$data[0]."個にしました");
                $player->sendMessage("ブロックを置いてから待機する".(int)$data[1]."数を1にしました");
                $this->sendSettingForm($player, [
                    "一度に置くブロックに数を".(int)$data[0]."個にしました",
                    "ブロックを置いてから待機する".(int)$data[1]."数を1にしました"
                ]);
            })->addErrors($errors)->show($player);
    }

    public function sendAutoReplenishSettingForm(Player $player, array $default = [], array $errors = []): void {
        (new CustomForm("設定 > 自動補充"))->setContents([
                new NumberInput("補充する間隔を秒で入力してください", "1秒以上で設定してください", $default[0] ?? (string)$this->setting->get("auto-replenish-time"), true, 1),
                new Toggle("初期値に戻す (3600秒)"),
            ])->onReceive(function (Player $player, array $data) {
                if ($data[1]) $data[0] = 3600;
                $this->setting->set("enable-auto-replenish", true);
                $this->setting->set("auto-replenish-time", (float)$data[0]);
                $this->startAutoReplenishTask((float)$data[0] * 20);
                $player->sendMessage("自動補充の間隔を".(float)$data[0]."秒に設定しました");
                $this->sendSettingForm($player, ["自動補充の間隔を".(float)$data[0]."秒に設定しました"]);
            })->addErrors($errors)->show($player);
    }
}