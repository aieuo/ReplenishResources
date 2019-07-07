<?php
namespace aieuo;

use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\scheduler\Task;

class ReplenishResourcesAPI {
    private static $instance;

    public function __construct($owner, $resources, $setting) {
        $this->owner = $owner;
        $this->resources = $resources;
        $this->setting = $setting;
        self::$instance = $this;
    }

    public static function getInstance() {
        return self::$instance;
    }

    public function getOwner() {
        return $this->owner;
    }

    public function getResources() {
        return $this->resources;
    }

    public function existsResource(Position $pos) {
        return $this->resources->exists($pos->x.",".$pos->y.",".$pos->z.",".$pos->level->getFolderName());
    }

    public function getResource(Position $pos) {
        return $this->resources->get($pos->x.",".$pos->y.",".$pos->z.",".$pos->level->getFolderName(), null);
    }

    public function addResource(Position $signpos, Position $pos1, Position $pos2, array $ids) {
        $this->resources->set($signpos->x.",".$signpos->y.",".$signpos->z.",".$signpos->level->getFolderName(), [
            "startx" => min($pos1->x, $pos2->x),
            "starty" => min($pos1->y, $pos2->y),
            "startz" => min($pos1->z, $pos2->z),
            "endx" => max($pos1->x, $pos2->x),
            "endy" => max($pos1->y, $pos2->y),
            "endz" => max($pos1->z, $pos2->z),
            "level" => $pos1->level->getFolderName(),
            "id" => $ids,
        ]);
        $this->resources->save();
    }

    public function updateResource(Position $pos, string $key, $data) {
        $resource = $this->getResource($pos);
        if($resource === null) return false;
        $resource[$key] = $data;
        $this->resources->set($pos->x.",".$pos->y.",".$pos->z.",".$pos->level->getFolderName(), $resource);
        $this->resources->save();
    }

    public function removeResource(Position $pos) {
        if(!$this->existsResource($pos)) return false;
        $this->resources->remove($pos->x.",".$pos->y.",".$pos->z.",".$pos->level->getFolderName());
        $this->resources->save();
        return true;
    }

    public function getAutoReplenishResources() {
        return $this->setting->get("auto-replenish-resources", []);
    }

    public function addAutoReplenishResource(Position $pos) {
        $resources = $this->getAutoReplenishResources();
        $add = $pos->x.",".$pos->y.",".$pos->z.",".$pos->level->getFolderName();
        if(in_array($add, $resources)) return false;
        $this->setting->set("auto-replenish-resources", array_merge($resources, [$add]));
        $this->setting->save();
        return true;
    }

    public function removeAutoReplenishResource(Position $pos) {
        $resources = $this->getAutoReplenishResources();
        $remove = $pos->x.",".$pos->y.",".$pos->z.",".$pos->level->getFolderName();
        if(!in_array($remove, $resources)) return false;
        $resources = array_diff($resources, [$remove]);
        $resources = array_values($resources);
        $this->setting->set("auto-replenish-resources", $resources);
        $this->setting->save();
        return true;
    }

    public function replenish(Position $pos){
        $resource = $this->getResource($pos);
        if($resource === null) return;
        $ids = [];
        $total = 0;
        foreach($resource["id"] as $id) {
            $min = $total + 1;
            $total += abs((int)$id["per"]);
            $ids[] = ["id" => $id["id"], "damage" => $id["damage"], "min" => $min, "max" => $total];
        }
        $this->setBlocks(new Position($resource["startx"], $resource["starty"], $resource["startz"], $this->getOwner()->getServer()->getLevelByName($resource["level"])),
            new Vector3($resource["endx"], $resource["endy"], $resource["endz"]), $ids, $total, $this->setting->get("tick-place", 100), $this->setting->get("period", 1));
    }

    public function setBlocks($pos1, $pos2, $ids, $total, $limit, $period, $i = -1, $j = 0, $k = 0) {
        $level = $pos1->level;
        $startX = $pos1->x;
        $startY = $pos1->y;
        $startZ = $pos1->z;
        $endX = $pos2->x;
        $endY = $pos2->y;
        $endZ = $pos2->z;
        for($n = 0; $n < $limit; $n++) {
            $i++;
            $x = $startX + $i;
            $z = $startZ + $j;
            $y = $startY + $k;
            if($x > $endX){
                $i = 0;
                $j++;
                $x = $startX + $i;
                $z = $startZ + $j;
                if($z > $endZ){
                    $j = 0;
                    $k++;
                    $z = $startZ + $j;
                    $y = $startY + $k;
                    if($y > $endY) return;
                }
            }
            $rand = mt_rand(1, $total);
            foreach($ids as $id) {
                if($id["min"] <= $rand and $rand <= $id["max"]) break;
            }
            if(!isset($id)) $id = $ids[array_rand($ids)];
            $level->setBlockIdAt($x, $y, $z, $id["id"]);
            $level->setBlockDataAt($x, $y, $z, $id["damage"]);
        }
        $this->getOwner()->getScheduler()->scheduleDelayedTask(new class([$this, "setBlocks"], [$pos1, $pos2, $ids, $total, $limit, $period, $i, $j, $k]) extends Task {
            public function __construct($callable, $datas) {
                $this->callable = $callable;
                $this->datas = $datas;
            }

            public function onRun(int $currentTick) {
                call_user_func_array($this->callable, $this->datas);
            }
        }, $period);
    }
}