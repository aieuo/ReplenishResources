<?php
namespace aieuo\replenish;

use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;

class ReplenishResourcesAPI {
    private static $instance;

    /** @var ReplenishResources */
    private $owner;
    /* @var Config */
    private $setting;
    /* @var Config */
    private $resources;

    public function __construct(ReplenishResources $owner, Config $resources, Config $setting) {
        $this->owner = $owner;
        $this->resources = $resources;
        $this->setting = $setting;
        self::$instance = $this;
    }

    public static function getInstance(): self {
        return self::$instance;
    }

    public function getOwner(): ReplenishResources {
        return $this->owner;
    }

    public function getResources(): Config {
        return $this->resources;
    }

    public function getSetting(): Config {
        return $this->setting;
    }

    public function existsResource(Position $pos): ?bool {
        if ($pos->level === null) return false;
        return $this->resources->exists($pos->x.",".$pos->y.",".$pos->z.",".$pos->level->getFolderName());
    }

    public function getResource(Position $pos): ?array {
        if ($pos->level === null) return null;
        return $this->resources->get($pos->x.",".$pos->y.",".$pos->z.",".$pos->level->getFolderName(), null);
    }

    public function addResource(Position $sign, Position $pos1, Position $pos2, array $ids): void {
        $this->resources->set($sign->x.",".$sign->y.",".$sign->z.",".$sign->level->getFolderName(), [
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

    public function updateResource(Position $pos, string $key, $data): bool {
        $resource = $this->getResource($pos);
        if($resource === null) return false;
        $resource[$key] = $data;
        $this->resources->set($pos->x.",".$pos->y.",".$pos->z.",".$pos->level->getFolderName(), $resource);
        $this->resources->save();
        return true;
    }

    public function removeResource(Position $pos): bool {
        if(!$this->existsResource($pos)) return false;
        $this->resources->remove($pos->x.",".$pos->y.",".$pos->z.",".$pos->level->getFolderName());
        $this->resources->save();
        return true;
    }

    public function getAutoReplenishResources(): array {
        return $this->setting->get("auto-replenish-resources", []);
    }

    public function addAutoReplenishResource(Position $pos): bool {
        $resources = $this->getAutoReplenishResources();
        $add = $pos->x.",".$pos->y.",".$pos->z.",".$pos->level->getFolderName();
        if(in_array($add, $resources, true)) return false;
        $this->setting->set("auto-replenish-resources", array_merge($resources, [$add]));
        $this->setting->save();
        return true;
    }

    public function removeAutoReplenishResource(Position $pos): bool {
        $resources = $this->getAutoReplenishResources();
        $remove = $pos->x.",".$pos->y.",".$pos->z.",".$pos->level->getFolderName();
        if(!in_array($remove, $resources, true)) return false;
        $resources = array_diff($resources, [$remove]);
        $resources = array_values($resources);
        $this->setting->set("auto-replenish-resources", $resources);
        $this->setting->save();
        return true;
    }

    public function replenish(Position $pos): void {
        $resource = $this->getResource($pos);
        if($resource === null) return;
        $ids = [];
        $total = 0;
        foreach($resource["id"] as $id) {
            $min = $total + 1;
            $total += abs((int)$id["per"]);
            $ids[] = ["id" => $id["id"], "damage" => $id["damage"], "min" => $min, "max" => $total];
        }
        $this->setBlocks(
            new Position($resource["startx"], $resource["starty"], $resource["startz"], $this->getOwner()->getServer()->getLevelByName($resource["level"])),
            new Vector3($resource["endx"], $resource["endy"], $resource["endz"]),
            $ids,
            $total,
            $this->setting->get("tick-place", 100),
            $this->setting->get("period", 1)
        );
    }

    public function setBlocks(Position $pos1, Vector3 $pos2, array $ids, int $total, int $limit, int $period, int $i = -1, int $j = 0, int $k = 0): bool {
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
                    if($y > $endY) return true;
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
            /** @var callable */
            private $callable;
            /** @var array  */
            private $data;
            public function __construct(callable $callable, array $data) {
                $this->callable = $callable;
                $this->data = $data;
            }

            /** @noinspection ReturnTypeCanBeDeclaredInspection */
            public function onRun(int $currentTick) {
                call_user_func_array($this->callable, $this->data);
            }
        }, $period);
        return false;
    }
}