<?php

namespace aieuo;

use pocketmine\scheduler\Task;
use pocketmine\level\Position;

class AutoReplenishTask extends Task {
    public function __construct(ReplenishResourcesAPI $api) {
        $this->api = $api;
    }

    public function onRun(int $currentTick) {
        $api = $this->api;
        foreach ($api->getAutoReplenishResources() as $place) {
            if($api->setting->get("announcement")) {
                $api->getOwner()->getServer()->broadcastMessage("資源(".$place.")の補充を行います");
            }
            $pos = explode(",", $place);
            $api->replenish(new Position((int)$pos[0], (int)$pos[1], (int)$pos[2], $api->getOwner()->getServer()->getLevelByName($pos[3])));
        }
    }
}