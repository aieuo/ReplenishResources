<?php

namespace aieuo\replenish;

use pocketmine\Server;
use pocketmine\world\Position;
use pocketmine\scheduler\Task;

class AutoReplenishTask extends Task {
    public function onRun(): void {
        $api = ReplenishResourcesAPI::getInstance();
        foreach ($api->getAutoReplenishResources() as $place) {
            if ($api->getSetting()->get("announcement")) {
                $api->getOwner()->getServer()->broadcastMessage("資源(".$place.")の補充を行います");
            }
            $pos = explode(",", $place);
            $api->replenish(new Position((int)$pos[0], (int)$pos[1], (int)$pos[2], Server::getInstance()->getWorldManager()->getWorldByName($pos[3])));
        }
    }
}