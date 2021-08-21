<?php

namespace aieuo\replenish;

use aieuo\mineflow\formAPI\CustomForm;
use aieuo\mineflow\formAPI\element\Button;
use aieuo\mineflow\formAPI\element\NumberInput;
use aieuo\mineflow\formAPI\element\Toggle;
use aieuo\mineflow\formAPI\ListForm;
use pocketmine\Player;

class SettingForm {

    private ReplenishResources $owner;

    public function __construct(ReplenishResources $owner) {
        $this->owner = $owner;
    }

    public function sendSettingForm(Player $player, array $messages = []): void {
        if (!$this->owner->getSetting()->get("sneak")) {
            $sneak = "[スニーク] 今は§cOFF§r (スニークしなくても反応します)";
        } else {
            $sneak = "[スニーク] 今は§bON§r (スニークしないと反応しません)";
        }
        if (!$this->owner->getSetting()->get("announcement")) {
            $announcement = "[アナウンス] 今は§cOFF§r (補充時にみんなに知らせません)";
        } else {
            $announcement = "[アナウンス] 今は§bON§r (補充時にみんなに知らせます)";
        }
        if (($time = (float)$this->owner->getSetting()->get("wait")) <= 0 or !$this->owner->getSetting()->get("enable-wait")) {
            $wait = "[連続補充の制限] 今は§cOFF§r (連続補充を制限しません)";
        } else {
            $wait = "[連続補充の制限] 今は§bON§r (同じ看板のタップを".$time."秒間制限します)";
        }
        if (($check = (int)$this->owner->getSetting()->get("count")) === -1 or !$this->owner->getSetting()->get("enable-count")) {
            $count = "[残さずに掘る] 今は§cOFF§r (ブロックが残っていても補充します)";
        } else {
            $count = "[残さずに掘る] 今は§bON§r (残っているブロックが".$check."個以下の時だけ補充します)";
        }
        if (!$this->owner->getSetting()->get("check-inside")) {
            $inside = "[資源内のプレイヤー確認] 今は§cOFF§r (資源内にプレイヤーがいても補充します)";
        } else {
            $inside = "[資源内のプレイヤー確認] 今は§bON§r (資源内にプレイヤーがいると補充しません)";
        }
        if (!$this->owner->getSetting()->get("enable-auto-replenish")) {
            $autoReplenish = "[自動補充] 今は§cOFF§r (設定した資源を定期的に補充しません)";
        } else {
            $autoReplenish = "[自動補充] 今は§bON§r (設定した資源を".$this->owner->getSetting()->get("auto-replenish-time")."に1回補充します)";
        }
        $place = "[補充] ".$this->owner->getSetting()->get("tick-place", 100)."ブロック置いて".$this->owner->getSetting()->get("period")."tick待つ";

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
                    if (!$this->owner->getSetting()->get("sneak")) {
                        $this->owner->getSetting()->set("sneak", true);
                        $message = "スニークをオンにしました";
                    } else {
                        $this->owner->getSetting()->set("sneak", false);
                        $message = "スニークをオフにしました";
                    }
                    break;
                case 1:
                    if (!$this->owner->getSetting()->get("announcement")) {
                        $this->owner->getSetting()->set("announcement", true);
                        $message = "アナウンスをオンにしました";
                    } else {
                        $this->owner->getSetting()->set("announcement", false);
                        $message = "アナウンスをオフにしました";
                    }
                    break;
                case 2:
                    if (!$this->owner->getSetting()->get("enable-wait")) {
                        $this->sendWaitSettingForm($player);
                        return;
                    }

                    $this->owner->getSetting()->set("enable-wait", false);
                    $message = "連続補充の制限をオフにしました";
                    break;
                case 3:
                    if (!$this->owner->getSetting()->get("enable-count")) {
                        $this->sendCountSettingForm($player);
                        return;
                    }

                    $this->owner->getSetting()->set("enable-count", false);
                    $message = "ブロックが残っていても補充するようにしました";
                    break;
                case 4:
                    if (!$this->owner->getSetting()->get("check-inside")) {
                        $this->owner->getSetting()->set("check-inside", true);
                        $message = "資源内にプレイヤーがいると補充できないようにしました";
                    } else {
                        $this->owner->getSetting()->set("check-inside", false);
                        $message = "資源内にプレイヤーがいても補充できるようにしました";
                    }
                    break;
                case 5:
                    $this->sendPlaceSettingForm($player);
                    return;
                case 6:
                    if (!$this->owner->getSetting()->get("enable-auto-replenish")) {
                        $this->sendAutoReplenishSettingForm($player);
                        return;
                    }

                    $this->owner->getSetting()->set("enable-auto-replenish", false);
                    $this->owner->startAutoReplenishTask(0);
                    $message = "自動補充をしないようにしました";
                    break;
                default:
                    return;
            }
            $this->owner->getSetting()->save();
            $player->sendMessage($message);
            $this->sendSettingForm($player, [$message]);
        })->addMessages($messages)->show($player);
    }

    public function sendWaitSettingForm(Player $player, array $default = [], array $errors = []): void {
        (new CustomForm("設定 > 連続補充の制限"))->setContents([
            new NumberInput("制限する秒数を入力してください", "1秒より長く設定してください", $default[0] ?? (string)$this->owner->getSetting()->get("wait"), true, 1),
            new Toggle("初期値に戻す (60秒)", $default[1] ?? false),
        ])->onReceive(function (Player $player, array $data) {
            if ($data[1]) $data[0] = 60;

            $this->owner->getSetting()->set("enable-wait", true);
            $this->owner->getSetting()->set("wait", (float)$data[0]);
            $this->owner->getSetting()->save();

            $player->sendMessage("連続補充の制限を".(float)$data[0]."秒に設定しました");
            $this->sendSettingForm($player, ["連続補充の制限を".(float)$data[0]."秒に設定しました"]);
        })->addErrors($errors)->show($player);
    }

    public function sendCountSettingForm(Player $player, array $default = [], array $errors = []): void {
        (new CustomForm("設定 > 残さずに掘る"))->setContents([
            new NumberInput("残っていてもいいブロックの数を入力してください", "0個以上で設定してください", $default[0] ?? (string)$this->owner->getSetting()->get("count"), true, 0),
            new Toggle("初期値に戻す (0個)", $default[1] ?? false),
        ])->onReceive(function (Player $player, array $data) {
            if ($data[1]) $data[0] = 0;

            $this->owner->getSetting()->set("enable-count", true);
            $this->owner->getSetting()->set("count", (int)$data[0]);
            $this->owner->getSetting()->save();

            $player->sendMessage("残っているブロックの数が".(int)$data[0]."個以下の時だけ補充するようにしました");
            $this->sendSettingForm($player, ["残っているブロックの数が".(int)$data[0]."個以下の時だけ補充するようにしました"]);
        })->addErrors($errors)->show($player);
    }

    public function sendPlaceSettingForm(Player $player, array $default = [], array $errors = []): void {
        (new CustomForm("設定 > 補充"))->setContents([
            new NumberInput("一度に置くブロックの数", "1以上で設定してください", $default[0] ?? (string)$this->owner->getSetting()->get("tick-place"), true, 1),
            new NumberInput("ブロックを置いてから待機するtick数", "1以上で設定してください", $default[1] ?? (string)$this->owner->getSetting()->get("period"), true, 1),
            new Toggle("初期値に戻す (100個, 1tick)"),
        ])->onReceive(function (Player $player, array $data) {
            if ($data[2]) {
                $data[0] = 100;
                $data[1] = 1;
            }

            $this->owner->getSetting()->set("tick-place", (int)$data[0]);
            $this->owner->getSetting()->set("period", (int)$data[1]);
            $this->owner->getSetting()->save();

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
            new NumberInput("補充する間隔を秒で入力してください", "1秒以上で設定してください", $default[0] ?? (string)$this->owner->getSetting()->get("auto-replenish-time"), true, 1),
            new Toggle("初期値に戻す (3600秒)"),
        ])->onReceive(function (Player $player, array $data) {
            if ($data[1]) $data[0] = 3600;

            $this->owner->getSetting()->set("enable-auto-replenish", true);
            $this->owner->getSetting()->set("auto-replenish-time", (float)$data[0]);
            $this->owner->getSetting()->save();

            $this->owner->startAutoReplenishTask((float)$data[0] * 20);
            $player->sendMessage("自動補充の間隔を".(float)$data[0]."秒に設定しました");
            $this->sendSettingForm($player, ["自動補充の間隔を".(float)$data[0]."秒に設定しました"]);
        })->addErrors($errors)->show($player);
    }
}