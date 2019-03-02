<?php

namespace aieuo;

use pocketmine\scheduler\Task;

class AutoReplenishTask extends Task {
    public function __construct(ReplenishResources $owner) {
        $this->owner = $owner;
    }

    public function onRun(int $currentTick) {
        $owner = $this->owner;
        foreach ($owner->getAutoReplenishResources() as $place) {
            if(!$owner->config->exists($place)) continue;

            if($owner->setting->get("announcement")) {
                $owner->getServer()->broadcastMessage("資源(".$place.")の補充を行います");
            }
            $owner->setBlocks($owner->config->get($place));
        }
    }
}