<?php
namespace aieuo;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\Player;
use pocketmine\block\Block;
use pocketmine\utils\Config;
use pocketmine\math\Vector3;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;

class ReplenishResources extends PluginBase implements Listener{
    public function onEnable(){
        $this->getServer()->getPluginManager()->registerEvents($this,$this);
        if(!file_exists($this->getDataFolder())) @mkdir($this->getDataFolder(), 0721, true);
    	$this->config = new Config($this->getDataFolder()."config.yml", Config::YAML);
    	$this->setting = new Config($this->getDataFolder()."setting.yml", Config::YAML, [
    		"wait" => 60,
    		"sneak" => true,
    		"announcement" => false
    	]);
    	$this->wait = $this->setting->get("wait");
    	$this->sneak = (int)$this->setting->get("sneak");
    	$this->announce = $this->setting->get("announcement");
    }

    public function onDisable(){
    	$this->setting->save();
    }

  	public function onCommand(CommandSender $sender, Command $command,string $label, array $args):bool{
        $cmd = $command->getName();
    	if($cmd == "reso"){
    		if(!$sender instanceof Player){
    			$sender->sendMessage("コンソールからは使用できません");
    			return true;
    		}
    		if(!$sender->isOp()){
    			return false;
    		}
    		if(!isset($args[0])){
    			return false;
    		}
        	$name = $sender->getName();
    		switch ($args[0]) {
    			case 'setting':
    				if(!isset($args[2])){
    					$sender->sendMessage("/reso setting <wait | sneak | announce>");
    					return true;
    				}
    				switch ($args[1]) {
    					case 'wait':
    						$wait = (int)$args[2];
    						if($wait < 0){
    							$sender->sendMessage("0秒以上で指定してください");
    							return true;
    						}
    						$this->setting->set("wait", $wait);
    						$sender->sendMessage($wait."秒に設定しました");
    						$this->wait = $wait;
    						break;
    					case 'sneak':
    						if($args[2] == "on"){
    							$this->setting->set("sneak", true);
    							$sender->sendMessage("スニークしないと補充できないようになりました");
    							$this->sneak = true;
    						}elseif($args[2] == "off"){
    							$this->setting->set("sneak", false);
    							$sender->sendMessage("スニークしなくても補充できるようになりました");
    							$this->sneak = false;
    						}
    						break;
    					case 'announce':
    						if($args[2] == "on"){
    							$this->setting->set("announcement", true);
    							$sender->sendMessage("補充するときにサーバーにいる全員にメッセージを送るようにしました");
    							$this->announce = true;
    						}elseif($args[2] == "off"){
    							$this->setting->set("announcement", false);
    							$sender->sendMessage("補充するときに全員にメッセージを送らないようにしました");
    							$this->announce = false;
    						}
    						break;
    					default:
	    					$sender->sendMessage("/reso setting <wait | sneak | anounce>");
	    					break;
    				}
    				return true;
				case 'cancel':
					unset($this->tap[$name],$this->break[$name],$this->pos1[$name],$this->pos2[$name]);
					$sender->sendMessage("キャンセルしました");
					return true;
				case 'pos1':
					$this->break[$name] = "pos1";
					unset($this->pos2[$name]);
					$sender->sendMessage("ブロックを壊してください");
					return true;
				case 'pos2':
					if(!isset($this->pos1[$name])){
						$sender->sendMessage("まずpos1を設定してください");
						return true;
					}
					$this->break[$name] = "pos2";
					$sender->sendMessage("ブロックを壊してください");
					return true;
				case 'add':
					if(!isset($args[1])){
						$sender->sendMessage("/reso add <id>");
						return true;
					}
					if(!isset($this->pos1[$name]) or !isset($this->pos2[$name])){
						$sender->sendMessage("まずposを設定してください");
						return true;
					}
					$this->tap[$name] = [
						"type" => "add",
						"id" => $args[1]
					];
					$sender->sendMessage("追加する看板をタップしてください");
					return true;
				case 'del':
					$this->tap[$name]["type"] = "del";
					$sender->sendMessage("削除する看板をタップしてください");
					return true;
    		}
    		return true;
		}
	}

	public function onBreak(BlockBreakEvent $event){
		$player = $event->getPlayer();
		$name = $player->getName();
		if(isset($this->break[$name])){
			$block = $event->getBlock();
			$event->setCancelled();
			switch ($this->break[$name]) {
				case 'pos1':
					$this->pos1[$name] = [
						"x" => $block->x,
						"y" => $block->y,
						"z" => $block->z,
						"level" => $block->level->getFolderName()
					];
					$player->sendMessage("設定しました(".$this->pos1[$name]["x"].",".$this->pos1[$name]["y"].",".$this->pos1[$name]["z"].",".$this->pos1[$name]["level"].")");
					break;
				case 'pos2':
					if($this->pos1[$name]["level"] != $block->level->getFolderName()){
						$player->sendMessage("pos1と同じワールドに設定してください");
						return;
					}
					$this->pos2[$name] = [
						"x" => $block->x,
						"y" => $block->y,
						"z" => $block->z,
						"level" => $block->level->getFolderName()
					];
					$player->sendMessage("設定しました");
					break;
			}
			unset($this->break[$name]);
		}
	}

    public function onTouch(PlayerInteractEvent $event){
    	$player = $event->getPlayer();
    	$block = $event->getBlock();
    	$name = $player->getName();
    	if(isset($this->tap[$name])){
    		switch ($this->tap[$name]["type"]) {
    			case 'add':
					if(!($block->getId() == 63 or $block->getId() == 68)){
						$player->sendMessage("看板を触ってください");
						return;
					}
					$event->setCancelled();
					$ids = explode(":",$this->tap[$name]["id"]);
					if(!isset($ids[1]))$ids[1] = 0;
					$this->config->set($block->x.",".$block->y.",".$block->z.",".$block->level->getFolderName(),[
						"startx" => min($this->pos1[$name]["x"],$this->pos2[$name]["x"]),
						"starty" => min($this->pos1[$name]["y"],$this->pos2[$name]["y"]),
						"startz" => min($this->pos1[$name]["z"],$this->pos2[$name]["z"]),
						"endx" => max($this->pos1[$name]["x"],$this->pos2[$name]["x"]),
						"endy" => max($this->pos1[$name]["y"],$this->pos2[$name]["y"]),
						"endz" => max($this->pos1[$name]["z"],$this->pos2[$name]["z"]),
						"level" => $this->pos1[$name]["level"],
						"id" => [
							"id" => $ids[0],
							"damage" => $ids[1]
						]
					]);
					$this->config->save();
					$player->sendMessage("追加しました");
    				break;
    			case 'del':
					if(!($block->getId() == 63 or $block->getId() == 68)){
						$player->sendMessage("看板を触ってください");
						return;
					}
		    		$place = $block->x.",".$block->y.",".$block->z.",".$block->level->getFolderName();
					if($this->config->exists($place)){
						$this->config->remove($place);
						$this->config->save();
						$player->sendMessage("削除しました");
					}else{
						$player->sendMessage("その場所には登録されていません");
					}
					break;
    		}
    		unset($this->tap[$name]);
    		return;
    	}
    	if(($block->getId() == 63 or $block->getId() == 68)){
	    	$place = $block->x.",".$block->y.",".$block->z.",".$block->level->getFolderName();
	    	if($this->config->exists($place)){
	    		if($this->sneak == true and !$player->isSneaking()){
	    			$player->sendMessage("スニークしながらタップすると補充します");
	    			return;
	    		}
	    		if($this->wait !== 0){
		    		$time = $this->checkTime($player->getName(), $place);
		    		if($time == false){
		    			$player->sendMessage($this->wait."秒以内に使用しています\nしばらくお待ちください");
		    			return;
		    		}
		    	}
	    		$datas = $this->config->get($place);
	    		$count = $this->countBlocks($datas);
	    		if($count != 0){
	    			$player->sendMessage("まだブロックが残っています");
	    			return;
	    		}
	    		if($this->announce == true)$this->getServer()->broadcastMessage($name."さんが資源(".$place.")の補充を行います");
	    		$this->setBlocks($datas);
	    	}
	    }
    }

    public function checkTime($name,$type){
    	if(!isset($this->time[$name][$type])){
			$this->time[$name][$type] = microtime(true);
			return true;
    	}
    	$time = microtime(true) -$this->time[$name][$type];
    	if($time <= $this->wait){
    		return false;
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
		    		if($block->getId() !== 0){
		    			$count ++;
		    		}
		    	}
	    	}
    	}
    	return $count;
    }

    public function setBlocks($datas){
    	$sx = $datas["startx"];
    	$sy = $datas["starty"];
    	$sz = $datas["startz"];
    	$ex = $datas["endx"];
    	$ey = $datas["endy"];
    	$ez = $datas["endz"];
    	$level = $this->getServer()->getLevelByName($datas["level"]);
    	$id = $datas["id"]["id"];
    	$meta = $datas["id"]["damage"];
    	for ($x = $sx; $x <= $ex; $x++) {
	    	for ($y = $sy; $y <= $ey; $y++) {
		    	for ($z = $sz; $z <= $ez; $z++) {
		    		$level->setBlock(new Vector3($x,$y,$z),Block::get($id,$meta));
		    	}
	    	}
    	}
    }
}