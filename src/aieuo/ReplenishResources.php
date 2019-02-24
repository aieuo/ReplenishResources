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

class ReplenishResources extends PluginBase implements Listener{

    public function onEnable(){
        $this->getServer()->getPluginManager()->registerEvents($this,$this);
        if(!file_exists($this->getDataFolder())) @mkdir($this->getDataFolder(), 0721, true);
    	$this->config = new Config($this->getDataFolder()."config.yml", Config::YAML);
    	$this->setting = new Config($this->getDataFolder()."setting.yml", Config::YAML, [
    		"wait" => 60,
    		"sneak" => true,
    		"announcement" => false,
    		"count" => 0
    	]);

    	$this->formIds = [
    		"settings" => mt_rand(0, 99999999),
    		"wait" => mt_rand(0, 99999999),
    		"count" => mt_rand(0, 99999999)
    	];
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
    		if(!$sender->isOp() or !isset($args[0])){
    			return false;
    		}
        	$name = $sender->getName();
    		switch ($args[0]) {
    			case 'setting':
            		$this->sendSettingForm($sender);
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
	    		if($this->setting->get("sneak") and !$player->isSneaking()){
	    			$player->sendMessage("スニークしながらタップすると補充します");
	    			return;
	    		}
	    		if((float)$this->setting->get("wait") > 0){
		    		$time = $this->checkTime($player->getName(), $place);
		    		if($time !== true){
		    			$player->sendMessage((float)$this->setting->get("wait")."秒以内に使用しています\nあと".round($time)."秒お待ちください");
		    			return;
		    		}
		    	}
	    		$datas = $this->config->get($place);
	    		$check = (int)$this->setting->get("count");
	    		$count = $this->countBlocks($datas);
	    		if($check >= 0 and $count > $check){
	    			$player->sendMessage("まだブロックが残っています");
	    			return;
	    		}
	    		if($this->setting->get("announcement"))$this->getServer()->broadcastMessage($name."さんが資源(".$place.")の補充を行います");
	    		$this->setBlocks($datas);
	    	}
	    }
    }

    public function checkTime($name, $type){
    	if(!isset($this->time[$name][$type])){
			$this->time[$name][$type] = microtime(true);
			return true;
    	}
    	$time = microtime(true) -$this->time[$name][$type];
    	if($time <= (float)$this->setting->get("wait")){
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

    public function sendSettingForm($player) {
    	if($this->setting->get("sneak") === false) {
    		$sneak = "[スニーク] 今は§cOFF§r (スニークしなくても反応します)";
    	} else {
    		$sneak = "[スニーク] 今は§bON§r (スニークしないと反応しません)";
    	}
    	if($this->setting->get("announcement") === false) {
    		$announcement = "[アナウンス] 今は§cOFF§r (補充時にみんなに知らせません)";
    	} else {
    		$announcement = "[アナウンス] 今は§bON§r (補充時にみんなに知らせます)";
    	}
    	if(($time = (float)$this->setting->get("wait")) <= 0) {
    		$wait = "[連続補充の制限] 今は§cOFF§r (連続補充を制限しません)";
    	} else {
    		$wait = "[連続補充の制限] 今は§bON§r (同じ看板のタップを".$time."秒間制限します)";
    	}
    	if(($check = (int)$this->setting->get("count")) === -1) {
    		$count = "[残さずに掘る] 今は§cOFF§r (ブロックが残っていても補充します)";
    	} else {
    		$count = "[残さずに掘る] 今は§bON§r (残っているブロックが".$check."個以下の時だけ補充します)";
    	}
        $data = [
            "type" => "form",
            "title" => "設定",
            "content" => "§7ボタンを押してください",
            "buttons" => [
            	["text" => $sneak],
            	["text" => $announcement],
            	["text" => $wait],
            	["text" => $count]
            ]
        ];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING | JSON_UNESCAPED_UNICODE);
        $pk = new ModalFormRequestPacket();
        $pk->formId = $this->formIds["settings"];
        $pk->formData = $json;
        $player->dataPacket($pk);
    }

    public function sendWaitSettingForm($player, $mes = "") {
        $data = [
            "type" => "custom_form",
            "title" => "設定 > 連続補充の制限",
            "content" => [
            	[
		            "type" => "input",
		            "text" => ($mes === "" ? "" : $mes."\n")."制限する秒数を入力してください",
		            "default" => (string)$this->setting->get("wait"),
		            "placeholder" => "0秒より長く設定してください"
		        ],
		        [
		            "type" => "toggle",
		            "text" => "初期の状態に戻す (60秒)"
		        ]
            ]
        ];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING | JSON_UNESCAPED_UNICODE);
        $pk = new ModalFormRequestPacket();
        $pk->formId = $this->formIds["wait"];
        $pk->formData = $json;
        $player->dataPacket($pk);
    }

    public function sendCountSettingForm($player, $mes = "") {
        $data = [
            "type" => "custom_form",
            "title" => "設定 > 残さずに掘る",
            "content" => [
            	[
		            "type" => "input",
		            "text" => ($mes === "" ? "" : $mes."\n")."残っていてもいいブロックの数を入力してください",
		            "default" => (string)$this->setting->get("count"),
		            "placeholder" => "0個以上で設定してください"
		        ]
            ]
        ];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING | JSON_UNESCAPED_UNICODE);
        $pk = new ModalFormRequestPacket();
        $pk->formId = $this->formIds["count"];
        $pk->formData = $json;
        $player->dataPacket($pk);
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
				    	if($this->setting->get("sneak") === false) {
				    		$this->setting->set("sneak", true);
				    		$player->sendMessage("スニークをオンにしました");
				    	} else {
				    		$this->setting->set("sneak", false);
				    		$player->sendMessage("スニークをオフにしました");
				    	}
				    	break;
            		case 1:
				    	if($this->setting->get("announcement") === false) {
				    		$this->setting->set("announcement", true);
				    		$player->sendMessage("アナウンスをオンにしました");
				    	} else {
				    		$this->setting->set("announcement", false);
				    		$player->sendMessage("アナウンスをオフにしました");
				    	}
				    	break;
            		case 2:
				    	if((float)$this->setting->get("wait") <= 0) {
				    		$this->sendWaitSettingForm($player);
				    		return;
				    	} else {
				    		$this->setting->set("wait", 0);
				    		$player->sendMessage("連続補充の制限をオフにしました");
				    	}
				    	break;
            		case 3:
				    	if((int)$this->setting->get("count") === -1) {
				    		$this->sendCountSettingForm($player);
				    		return;
				    	} else {
				    		$this->setting->set("count", -1);
				    		$player->sendMessage("ブロックが残っていても補充するようにしました");
				    	}
				    	break;
            	}
            	$this->sendSettingForm($player);
        	} elseif($pk->formId === $this->formIds["wait"]) {
            	$data = json_decode($pk->formData);
            	if($data === null) return;
            	if($data[1]) {
		    		$this->setting->set("wait", 60);
		    		$player->sendMessage("連続補充の制限を60秒に設定しました");
            		$this->sendSettingForm($player);
            		return;
            	}
            	if($data[0] === "") {
				    $this->sendWaitSettingForm($player, "§c必要事項を記入してください§f");
				    return;
            	}
            	if((float)$data[0] <= 0) {
				    $this->sendWaitSettingForm($player, "§c0秒より大きい数を入力してください§f");
				    return;
            	}
	    		$this->setting->set("wait", (float)$data[0]);
	    		$player->sendMessage("連続補充の制限を".floatval($data[0])."秒に設定しました");
            	$this->sendSettingForm($player);
        	} elseif($pk->formId === $this->formIds["count"]) {
            	$data = json_decode($pk->formData);
            	if($data === null) return;
            	if($data[0] === "") {
				    $this->sendWaitSettingForm($player, "§c必要事項を記入してください§f");
				    return;
            	}
            	if((int)$data[0] < 0) {
				    $this->sendWaitSettingForm($player, "§c0以上で入力してください§f");
				    return;
            	}
	    		$this->setting->set("count", (int)$data[0]);
	    		$player->sendMessage("残っているブロックの数が".(int)$data[0]."個以下の時だけ補充するようにしました");
            	$this->sendSettingForm($player);
        	}
        }
    }
}