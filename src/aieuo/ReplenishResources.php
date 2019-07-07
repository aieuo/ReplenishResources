<?php
namespace aieuo;

use pocketmine\Player;
use pocketmine\block\Block;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\math\Vector3;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\scheduler\TaskHandler;

class ReplenishResources extends PluginBase implements Listener {

    private $taskHandler = null;

    public function onEnable(){
        $this->getServer()->getPluginManager()->registerEvents($this,$this);
        if(!file_exists($this->getDataFolder())) @mkdir($this->getDataFolder(), 0721, true);
        $this->config = new Config($this->getDataFolder()."config.yml", Config::YAML);
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

        $this->api = new ReplenishResourcesAPI($this, $this->config, $this->setting);

        if($this->setting->get("enable-auto-replenish")) {
            $time = (float)$this->setting->get("auto-replenish-time", 60) * 20;
            $this->startAutoReplenishTask($time);
        }

        $this->formIds = [
            "settings" => mt_rand(0, 99999999),
            "wait" => mt_rand(0, 99999999),
            "count" => mt_rand(0, 99999999),
            "place" => mt_rand(0, 99999999),
            "autoreplenish" => mt_rand(0, 99999999),
        ];
    }

    public function onDisable() {
        $this->setting->save();
    }

    public function checkConfig() {
        if(version_compare("4.2.0", $this->setting->get("version", ""), "<=")) return;
        $version = $this->getDescription()->getVersion();
        $this->setting->set("version", $version);
        $resources = [];
        foreach($this->config->getAll() as $place => $resource) {
            if(isset($resource["id"]["id"]) or isset($resource["id"]["damage"])) {
                $resource["id"] = [[
                    "id" => $resource["id"]["id"],
                    "damage" => $resource["id"]["damage"],
                    "per" => 1,
                ]];
            }
            $resources[$place] = $resource;
        }
        $this->config->setAll($resources);
        $this->config->save();
    }

    public function startAutoReplenishTask($time) {
        if($time === 0) {
            if($this->taskHandler instanceof TaskHandler and !$this->taskHandler->isCancelled())
                $this->getScheduler()->cancelTask($this->taskHandler->getTaskId());
            $this->taskHandler = null;
            return;
        }
        if($this->taskHandler instanceof TaskHandler and !$this->taskHandler->isCancelled()) {
            if($time === $this->taskHandler->getPeriod()) return;
            $this->getScheduler()->cancelTask($this->taskHandler->getTaskId());
        }
        $task = new AutoReplenishTask($this->api);
        $handler = $this->getScheduler()->scheduleRepeatingTask($task, $time);
        $this->taskHandler = $handler;
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(!$sender->isOp()) return false;
        if(!($sender instanceof Player)){
            $sender->sendMessage("コンソールからは使用できません");
            return true;
        }
        if(!isset($args[0])) {
            $sender->sendMessage("/reso <pos1 | pos2 | add | del | change | cancel | auto | setting>");
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
                if(!isset($args[1])) {
                    $sender->sendMessage("/reso add <id>");
                    return true;
                }
                if(!isset($this->pos1[$name]) or !isset($this->pos2[$name])){
                    $sender->sendMessage("まず/reso pos1と/reso pos2で範囲を設定してください");
                    return true;
                }
                if($this->pos1[$name]->level->getFolderName() != $this->pos2[$name]->level->getFolderName()) {
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
                if(!isset($args[1])) {
                    $sender->sendMessage("/reso change <id>");
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
                if(!isset($args[1])) {
                    $sender->sendMessage("/reso auto <add | del>");
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
                        $sender->sendMessage("/reso auto <add | del>");
                        break;
                }
                break;
            case 'setting':
                $this->sendSettingForm($sender);
                break;
            default:
                $sender->sendMessage("/reso <pos1 | pos2 | add | del | change | cancel | auto | setting>");
                break;
        }
        return true;
    }

    public function onBreak(BlockBreakEvent $event){
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $name = $player->getName();
        if(isset($this->break[$name])){
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

        if(($block->getId() == 63 or $block->getId() == 68) and !$event->isCancelled()) {
            if($this->api->existsResource($block)) {
                if(!$player->isOp()) {
                    $player->sendMessage("§cこの看板は壊せません");
                    $event->setCancelled();
                    return;
                }
                $player->sendMessage("補充看板を壊しました");
                $this->api->removeResource($place);
            }
        }
    }

    public function onTouch(PlayerInteractEvent $event) {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $name = $player->getName();

        if($block->getId() == 63 or $block->getId() == 68) {
            if(isset($this->tap[$name])) {
                $event->setCancelled();
                switch ($this->tap[$name]["type"]) {
                    case 'add':
                        $ids = array_map(function($id2) {
                            $ids2 = explode(":", $id2);
                            if(!isset($ids2[1])) $ids2[1] = 0;
                            if(!isset($ids2[2])) $ids2[2] = 100;
                            return ["id" => $ids2[0], "damage" => $ids2[1], "per" => $ids2[2]];
                        }, explode(",", $this->tap[$name]["id"]));
                        $this->api->addResource($block, $this->pos1[$name], $this->pos2[$name], $ids);
                        $player->sendMessage("追加しました");
                        break;
                    case 'change':
                        if(($resource = $this->api->getResource($block)) === null) {
                            $player->sendMessage("その場所にはまだ追加されていません");
                            return;
                        }
                        $ids = array_map(function($id2) {
                            $ids2 = explode(":", $id2);
                            if(!isset($ids2[1])) $ids2[1] = 0;
                            if(!isset($ids2[2])) $ids2[2] = 1;
                            return ["id" => $ids2[0], "damage" => $ids2[1], "per" => $ids2[2]];
                        }, explode(",", $this->tap[$name]["id"]));
                        $this->api->updateResource($block, "id", $ids);
                        $player->sendMessage("変更しました");
                        break;
                    case 'del':
                        if($this->api->removeResource($block)) {
                            $player->sendMessage("削除しました");
                        } else {
                            $player->sendMessage("その場所には登録されていません");
                        }
                        break;
                    case 'auto_add':
                        if(!$this->api->existsResource($block)) {
                            $player->sendMessage("それは補充看板ではありません");
                            return true;
                        }
                        if(!$this->api->addAutoReplenishResource($block)) {
                            $player->sendMessage("すでに追加されています");
                            return true;
                        }
                        $player->sendMessage("追加しました");
                        if(!$this->setting->get("enable-auto-replenish")) $player->sendMessage("§e自動補充がオフになっています。/reso settingでオンにしてください");
                        break;
                    case 'auto_del':
                        if(!$this->api->removeAutoReplenishResource($block)) {
                            $player->sendMessage("まだ追加されていません");
                            return true;
                        }
                        $player->sendMessage("削除しました");
                        break;
                }
                unset($this->tap[$name]);
                return;
            }

            if(!$this->api->existsResource($block)) return;

            $place = $block->x.",".$block->y.",".$block->z.",".$block->level->getFolderName();

            if($this->setting->get("sneak", false) and !$player->isSneaking()) {
                $player->sendMessage("スニークしながらタップすると補充します");
                return;
            }
            if($this->setting->get("enable-wait", false) and (float)$this->setting->get("wait") > 0) {
                $time = $this->checkTime($player->getName(), $place);
                if($time !== true) {
                    $player->sendMessage($this->setting->get("wait")."秒以内に使用しています\nあと".round($time, 1)."秒お待ちください");
                    return;
                }
            }
            $resource = $this->api->getResource($block);
            if($this->setting->get("check-inside", false)) {
                $players = $player->level->getPlayers();
                $inside = false;
                foreach($players as $p) {
                    if($resource["level"] == $p->level->getFolderName()
                        and $resource["startx"] <= floor($p->x) and floor($p->x) <= $resource["endx"]
                        and $resource["starty"] <= floor($p->y) and floor($p->y) <= $resource["endy"]
                        and $resource["startz"] <= floor($p->z) and floor($p->z) <= $resource["endz"]
                    ) {
                        $p->sendTip("§e".$name."があなたのいる資源を補充しようとしています");
                        $inside = true;
                    }
                }
                if($inside) {
                    $player->sendMessage("資源内にプレイヤーがいるため補充できません");
                    return;
                }
            }
            $allow = (int)$this->setting->get("count");
            $count = $this->countBlocks($resource);
            if($this->setting->get("enable-count", false) and $allow >= 0 and $count > $allow){
                $player->sendMessage("まだブロックが残っています (".($count - $allow).")");
                return;
            }
            if($this->setting->get("announcement")) $this->getServer()->broadcastMessage($name."さんが資源(".$place.")の補充を行います");
            $this->api->replenish($block);
        }
    }

    public function checkTime($name, $type) {
        if(!isset($this->time[$name][$type])) {
            $this->time[$name][$type] = microtime(true);
            return true;
        }
        $time = microtime(true) -$this->time[$name][$type];
        if($time <= (float)$this->setting->get("wait")) {
            return (float)$this->setting->get("wait") - $time;
        }
        $this->time[$name][$type] = microtime(true);
        return true;
    }

    public function countBlocks($datas){
        $sx = $datas["startx"];
        $sy = $datas["starty"];
        $sz = $datas["startz"];
        $ex = $datas["endx"];
        $ey = $datas["endy"];
        $ez = $datas["endz"];
        $level = $this->getServer()->getLevelByName($datas["level"]);
        $count = 0;
        for ($x = $sx; $x <= $ex; $x++) {
            for ($y = $sy; $y <= $ey; $y++) {
                for ($z = $sz; $z <= $ez; $z++) {
                    $block = $level->getBlock(new Vector3($x,$y,$z));
                    if($block->getId() !== 0) $count ++;
                }
            }
        }
        return $count;
    }

    public function sendSettingForm($player) {
        if(!$this->setting->get("sneak")) {
            $sneak = "[スニーク] 今は§cOFF§r (スニークしなくても反応します)";
        } else {
            $sneak = "[スニーク] 今は§bON§r (スニークしないと反応しません)";
        }
        if(!$this->setting->get("announcement")) {
            $announcement = "[アナウンス] 今は§cOFF§r (補充時にみんなに知らせません)";
        } else {
            $announcement = "[アナウンス] 今は§bON§r (補充時にみんなに知らせます)";
        }
        if(($time = (float)$this->setting->get("wait")) <= 0 or !$this->setting->get("enable-wait")) {
            $wait = "[連続補充の制限] 今は§cOFF§r (連続補充を制限しません)";
        } else {
            $wait = "[連続補充の制限] 今は§bON§r (同じ看板のタップを".$time."秒間制限します)";
        }
        if(($check = (int)$this->setting->get("count")) === -1 or !$this->setting->get("enable-count")) {
            $count = "[残さずに掘る] 今は§cOFF§r (ブロックが残っていても補充します)";
        } else {
            $count = "[残さずに掘る] 今は§bON§r (残っているブロックが".$check."個以下の時だけ補充します)";
        }
        if(!$this->setting->get("check-inside")) {
            $inside = "[資源内のプレイヤー確認] 今は§cOFF§r (資源内にプレイヤーがいても補充します)";
        } else {
            $inside = "[資源内のプレイヤー確認] 今は§bON§r (資源内にプレイヤーがいると補充しません)";
        }
        if(!$this->setting->get("enable-auto-replenish")) {
            $autoreplenish = "[自動補充] 今は§cOFF§r (設定した資源を定期的に補充しません)";
        } else {
            $autoreplenish = "[自動補充] 今は§bON§r (設定した資源を".$this->setting->get("auto-replenish-time")."に1回補充します)";
        }
        $place = "[補充] ".$this->setting->get("tick-place", 100)."ブロック置いて".$this->setting->get("period")."tick待つ";
        $data = [
            "type" => "form",
            "title" => "設定",
            "content" => "§7ボタンを押してください",
            "buttons" => [
                ["text" => $sneak],
                ["text" => $announcement],
                ["text" => $wait],
                ["text" => $count],
                ["text" => $inside],
                ["text" => $place],
                ["text" => $autoreplenish],
                ["text" => "終了"],
            ]
        ];
        $this->sendForm($player, $data, $this->formIds["settings"]);
    }

    public function sendWaitSettingForm($player, $mes = "", $default = null) {
        $data = [
            "type" => "custom_form",
            "title" => "設定 > 連続補充の制限",
            "content" => [
                [
                    "type" => "input",
                    "text" => "制限する秒数を入力してください".($mes === "" ? "" : "\n".$mes),
                    "default" => $default ?? (string)$this->setting->get("wait"),
                    "placeholder" => "1秒より長く設定してください"
                ],
                [
                    "type" => "toggle",
                    "text" => "初期値に戻す (60秒)"
                ],
            ]
        ];
        $this->sendForm($player, $data, $this->formIds["wait"]);
    }

    public function sendCountSettingForm($player, $mes = "", $default = null) {
        $data = [
            "type" => "custom_form",
            "title" => "設定 > 残さずに掘る",
            "content" => [
                [
                    "type" => "input",
                    "text" => "残っていてもいいブロックの数を入力してください".($mes === "" ? "" : "\n".$mes),
                    "default" => $default ?? (string)$this->setting->get("count"),
                    "placeholder" => "0個以上で設定してください"
                ],
                [
                    "type" => "toggle",
                    "text" => "初期値に戻す (0個)"
                ],
            ]
        ];
        $this->sendForm($player, $data, $this->formIds["count"]);
    }

    public function sendPlaceSettingForm($player, $mes1 = "", $mes2 = "", $default1 = null, $default2 = null) {
        $data = [
            "type" => "custom_form",
            "title" => "設定 > 補充",
            "content" => [
                [
                    "type" => "input",
                    "text" => "一度に置くブロックの数".($mes1 === "" ? "" : "\n".$mes1),
                    "default" => $default1 ?? (string)$this->setting->get("tick-place"),
                    "placeholder" => "1以上で設定してください"
                ],
                [
                    "type" => "input",
                    "text" => "ブロックを置いてから待機するtick数".($mes2 === "" ? "" : "\n".$mes2),
                    "default" => $default2 ?? (string)$this->setting->get("period"),
                    "placeholder" => "1以上で設定してください"
                ],
                [
                    "type" => "toggle",
                    "text" => "初期値に戻す (100個, 1tick)"
                ],
            ]
        ];
        $this->sendForm($player, $data, $this->formIds["place"]);
    }

    public function sendAutoReplenishSettingForm($player, $mes = "", $default = null) {
        $data = [
            "type" => "custom_form",
            "title" => "設定 > 自動補充",
            "content" => [
                [
                    "type" => "input",
                    "text" => "補充する間隔を秒で入力してください\n設定は再起動後反映されます".($mes === "" ? "" : "\n".$mes),
                    "default" => $default ?? (string)$this->setting->get("auto-replenish-time"),
                    "placeholder" => "1秒以上で設定してください"
                ],
                [
                    "type" => "toggle",
                    "text" => "初期値に戻す (3600秒)"
                ],
            ]
        ];
        $this->sendForm($player, $data, $this->formIds["autoreplenish"]);
    }

    public function Receive(DataPacketReceiveEvent $event){
        $pk = $event->getPacket();
        $player = $event->getPlayer();
        $name = $player->getName();
        if($pk instanceof ModalFormResponsePacket){
            if($pk->formId === $this->formIds["settings"]) {
                $data = json_decode($pk->formData);
                if($data === null) return;
                switch($data) {
                    case 0:
                        if(!$this->setting->get("sneak")) {
                            $this->setting->set("sneak", true);
                            $player->sendMessage("スニークをオンにしました");
                        } else {
                            $this->setting->set("sneak", false);
                            $player->sendMessage("スニークをオフにしました");
                        }
                        break;
                    case 1:
                        if(!$this->setting->get("announcement")) {
                            $this->setting->set("announcement", true);
                            $player->sendMessage("アナウンスをオンにしました");
                        } else {
                            $this->setting->set("announcement", false);
                            $player->sendMessage("アナウンスをオフにしました");
                        }
                        break;
                    case 2:
                        if(!$this->setting->get("enable-wait")) {
                            $this->sendWaitSettingForm($player);
                            return;
                        } else {
                            $this->setting->set("enable-wait", false);
                            $player->sendMessage("連続補充の制限をオフにしました");
                        }
                        break;
                    case 3:
                        if(!$this->setting->get("enable-count")) {
                            $this->sendCountSettingForm($player);
                            return;
                        } else {
                            $this->setting->set("enable-count", false);
                            $player->sendMessage("ブロックが残っていても補充するようにしました");
                        }
                        break;
                    case 4:
                        if(!$this->setting->get("check-inside")) {
                            $this->setting->set("check-inside", true);
                            $player->sendMessage("資源内にプレイヤーがいると補充できないようにしました");
                        } else {
                            $this->setting->set("check-inside", false);
                            $player->sendMessage("資源内にプレイヤーがいても補充できるようにしました");
                        }
                        break;
                    case 5:
                        $this->sendPlaceSettingForm($player);
                        return;
                    case 6:
                        if(!$this->setting->get("enable-auto-replenish")) {
                            $this->sendAutoReplenishSettingForm($player);
                            return;
                        } else {
                            $this->setting->set("enable-auto-replenish", false);
                            $this->startAutoReplenishTask(0);
                            $player->sendMessage("自動補充をしないようにしました");
                        }
                        break;
                    case 7:
                        return;
                }
                $this->sendSettingForm($player);
            } elseif($pk->formId === $this->formIds["wait"]) {
                $data = json_decode($pk->formData);
                if($data === null) return;
                if($data[1]) $data[0] = 60;
                if($data[0] === "") {
                    $this->sendWaitSettingForm($player, "§c必要事項を記入してください§f");
                    return;
                }
                if((float)$data[0] < 1) {
                    $this->sendWaitSettingForm($player, "§c1より大きい数を入力してください§f", $data[0]);
                    return;
                }
                $this->setting->set("enable-wait", true);
                $this->setting->set("wait", (float)$data[0]);
                $player->sendMessage("連続補充の制限を".floatval($data[0])."秒に設定しました");
                $this->sendSettingForm($player);
            } elseif($pk->formId === $this->formIds["count"]) {
                $data = json_decode($pk->formData);
                if($data === null) return;
                if($data[1]) $data[0] = 0;
                if($data[0] === "") {
                    $this->sendWaitSettingForm($player, "§c必要事項を記入してください§f");
                    return;
                }
                if((int)$data[0] < 0) {
                    $this->sendWaitSettingForm($player, "§c0以上で入力してください§f", $data[0]);
                    return;
                }
                $this->setting->set("enable-count", true);
                $this->setting->set("count", (int)$data[0]);
                $player->sendMessage("残っているブロックの数が".(int)$data[0]."個以下の時だけ補充するようにしました");
                $this->sendSettingForm($player);
            } elseif($pk->formId === $this->formIds["place"]) {
                $data = json_decode($pk->formData);
                if($data === null) return;
                if($data[2]) {
                    $data[0] = 100;
                    $data[1] = 1;
                }
                $errors = ["", ""];
                if((int)$data[0] < 1) $error[0] = "§c1以上で入力してください§f";
                if((int)$data[1] < 1) $error[1] = "§c1以上で入力してください§f";
                if($data[0] === "") $error[0] = "§c必要事項を記入してください§f";
                if($data[1] === "") $error[1] = "§c必要事項を記入してください§f";
                if($errors[0] !== "" or $errors[1] !== "") {
                    $this->sendWaitSettingForm($player, $error[0], $error[1],
                        $error[0] == "§c必要事項を記入してください§f" ? null : $data[0], $error[1] == "§c必要事項を記入してください§f" ? null : $data[1]);
                    return;
                }
                $this->setting->set("tick-place", (int)$data[0]);
                $this->setting->set("period", (int)$data[1]);
                $player->sendMessage("一度に置くブロックに数を".(int)$data[0]."個にしました");
                $player->sendMessage("ブロックを置いてから待機する".(int)$data[1]."数を1にしました");
                $this->sendSettingForm($player);
            } elseif($pk->formId === $this->formIds["autoreplenish"]) {
                $data = json_decode($pk->formData);
                if($data === null) return;
                if($data[1]) $data[0] = 3600;
                if($data[0] === "") {
                    $this->sendWaitSettingForm($player, "§c必要事項を記入してください§f");
                    return;
                }
                if((float)$data[0] < 1) {
                    $this->sendWaitSettingForm($player, "§c1より大きい数を入力してください§f", $data[0]);
                    return;
                }
                $this->setting->set("enable-auto-replenish", true);
                $this->setting->set("auto-replenish-time", (float)$data[0]);
                $this->startAutoReplenishTask((float)$data[0] * 20);
                $player->sendMessage("自動補充の間隔を".(float)$data[0]."秒に設定しました");
                $this->sendSettingForm($player);
            }
        }
    }

    public function sendForm($player, $form, $id) {
        $json = json_encode($form, JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING | JSON_UNESCAPED_UNICODE);
        $pk = new ModalFormRequestPacket();
        $pk->formId = $id;
        $pk->formData = $json;
        $player->dataPacket($pk);
    }
}