<?php

/**  __    __       __    __
 * /＼ ＼_＼ ＼   /＼  "-./ ＼
 * ＼ ＼  __   ＼ ＼ ＼ ＼/＼＼
 *  ＼ ＼_＼ ＼ _＼＼ ＼_＼ ＼_＼
 *   ＼/_/  ＼/__/   ＼/_/ ＼/__/
 * ( *you can redistribute it and/or modify *) */
namespace ifteam\EconomyGamble;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\utils\Config;
use pocketmine\network\protocol\AddItemEntityPacket;
use pocketmine\network\protocol\RemoveEntityPacket;
use pocketmine\network\protocol\AddPlayerPacket;
use pocketmine\network\protocol\RemovePlayerPacket;
use pocketmine\command\PluginCommand;
use pocketmine\utils\TextFormat;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\Player;
use pocketmine\entity\Entity;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\block\BlockPlaceEvent;

class EconomyGamble extends PluginBase implements Listener {
	public $messages, $db; // 메시지, DB
	public $lotto, $probability; // 로또 확률
	public $economyAPI = null; // 이코노미 API
	public $m_version = 3; // 메시지 버전 변수
	public $packetQueue = [ ]; // 아이템 패킷 큐
	public $createQueue = [ ]; // 생성 큐
	public $placeQueue = [ ]; // 블럭배치방지 큐
	public $askAgainQueue = [ ]; // 터치 확인 큐
	public $packet = [ ]; // 전역 패킷 변수
	public function onEnable() {
		@mkdir ( $this->getDataFolder () );
		
		$this->initMessage ();
		
		$this->db = (new Config ( $this->getDataFolder () . "GambleDB.yml", Config::YAML, [ 
				"allow-gamble" => true 
		] ))->getAll ();
		
		if ($this->getServer ()->getPluginManager ()->getPlugin ( "EconomyAPI" ) != null) {
			$this->economyAPI = \onebone\economyapi\EconomyAPI::getInstance ();
		} else {
			$this->getLogger ()->error ( $this->get ( "there-are-no-economyapi" ) );
			$this->getServer ()->getPluginManager ()->disablePlugin ( $this );
		}
		
		$this->registerCommand ( $this->get ( "commands-gamble" ), $this->get ( "commands-gamble" ), "economygamble.commands.gamble", $this->get ( "commands-gamble-usage" ) );
		$this->getServer ()->getPluginManager ()->registerEvents ( $this, $this );
		
		$this->packet ["AddItemEntityPacket"] = new AddItemEntityPacket ();
		$this->packet ["AddItemEntityPacket"]->item = Item::get ( Item::GOLD_INGOT );
		
		$this->packet ["RemoveEntityPacket"] = new RemoveEntityPacket ();
		
		$this->packet ["AddPlayerPacket"] = new AddPlayerPacket ();
		$this->packet ["AddPlayerPacket"]->clientID = 0;
		$this->packet ["AddPlayerPacket"]->yaw = 0;
		$this->packet ["AddPlayerPacket"]->pitch = 0;
		$this->packet ["AddPlayerPacket"]->meta = 0;
		$this->packet ["AddPlayerPacket"]->metadata = [ 
				Entity::DATA_FLAGS => [ 
						Entity::DATA_TYPE_BYTE,
						1 << Entity::DATA_FLAG_INVISIBLE 
				],
				Entity::DATA_AIR => [ 
						Entity::DATA_TYPE_SHORT,
						300 
				],
				Entity::DATA_SHOW_NAMETAG => [ 
						Entity::DATA_TYPE_BYTE,
						1 
				],
				Entity::DATA_NO_AI => [ 
						Entity::DATA_TYPE_BYTE,
						1 
				] 
		];
		
		$this->packet ["RemovePlayerPacket"] = new RemovePlayerPacket ();
		$this->packet ["RemovePlayerPacket"]->clientID = 0;
		
		$this->getServer ()->getScheduler ()->scheduleRepeatingTask ( new EconomyGambleTask ( $this ), 20 );
	}
	public function onDisable() {
		$save = new Config ( $this->getDataFolder () . "GambleDB.yml", Config::YAML );
		$save->setAll ( $this->db );
		$save->save ();
		
		$save = new Config ( $this->getDataFolder () . "lotto.yml", Config::YAML );
		$save->setAll ( $this->lotto );
		$save->save ();
	}
	public function get($var) {
		return $this->messages [$this->messages ["default-language"] . "-" . $var];
	}
	public function initMessage() {
		$this->saveResource ( "messages.yml", false );
		$this->messagesUpdate ( "messages.yml" );
		$this->messages = (new Config ( $this->getDataFolder () . "messages.yml", Config::YAML ))->getAll ();
		
		$this->saveResource ( "lotto.yml", false );
		$this->lotto = (new Config ( $this->getDataFolder () . "lotto.yml", Config::YAML ))->getAll ();
		
		$this->saveResource ( "probability.yml", false );
		$this->messagesUpdate ( "probability.yml" );
		$this->probability = (new Config ( $this->getDataFolder () . "probability.yml", Config::YAML ))->getAll ();
	}
	public function messagesUpdate($targetYmlName) {
		$targetYml = (new Config ( $this->getDataFolder () . $targetYmlName, Config::YAML ))->getAll ();
		if (! isset ( $targetYml ["m_version"] )) {
			$this->saveResource ( $targetYmlName, true );
		} else if ($targetYml ["m_version"] < $this->m_version) {
			$this->saveResource ( $targetYmlName, true );
		}
	}
	public function registerCommand($name, $fallback, $permission, $description = "", $usage = "") {
		$commandMap = $this->getServer ()->getCommandMap ();
		$command = new PluginCommand ( $name, $this );
		$command->setDescription ( $description );
		$command->setPermission ( $permission );
		$command->setUsage ( $usage );
		$commandMap->register ( $fallback, $command );
	}
	public function message($player, $text = "", $mark = null) {
		if ($mark == null)
			$mark = $this->get ( "default-prefix" );
		$player->sendMessage ( TextFormat::DARK_AQUA . $mark . " " . $text );
	}
	public function alert($player, $text = "", $mark = null) {
		if ($mark == null)
			$mark = $this->get ( "default-prefix" );
		$player->sendMessage ( TextFormat::RED . $mark . " " . $text );
	}
	// ----------------------------------------------------------------------------------
	public function onBreak(BlockBreakEvent $event) {
		$block = $event->getBlock ();
		$player = $event->getPlayer ();
		if (isset ( $this->db ["showCase"] ["{$block->x}.{$block->y}.{$block->z}"] )) {
			if (! $player->hasPermission ( "economygamble.commands.gamble" )) {
				$this->alert ( $player, $this->get ( "gamble-cannot-break" ) );
				$event->setCancelled ();
				return;
			}
			unset ( $this->db ["showCase"] ["{$block->x}.{$block->y}.{$block->z}"] );
			if (isset ( $this->packetQueue [$player->getName ()] ["nametag"] ["{$block->x}.{$block->y}.{$block->z}"] )) {
				$this->packet ["RemovePlayerPacket"]->eid = $this->packetQueue [$player->getName ()] ["nametag"] ["{$block->x}.{$block->y}.{$block->z}"];
				$player->dataPacket ( $this->packet ["RemovePlayerPacket"] ); // 제거패킷 전송
			}
			if (isset ( $this->packetQueue [$player->getName ()] ["{$block->x}.{$block->y}.{$block->z}"] )) {
				$this->packet ["RemoveEntityPacket"]->eid = $this->packetQueue [$player->getName ()] ["{$block->x}.{$block->y}.{$block->z}"];
				$player->dataPacket ( $this->packet ["RemoveEntityPacket"] ); // 아이템 제거패킷 전송
				unset ( $this->packetQueue [$player->getName ()] ["{$block->x}.{$block->y}.{$block->z}"] );
			}
			$this->message ( $player, $this->get ( "gamble-completely-destroyed" ) );
		}
	}
	public function onTouch(PlayerInteractEvent $event) {
		$block = $event->getBlock ();
		$player = $event->getPlayer ();
		if (isset ( $this->db ["showCase"] ["{$block->x}.{$block->y}.{$block->z}"] )) {
			if (! isset ( $this->askAgainQueue [spl_object_hash ( $event->getPlayer () )] )) {
				$this->askAgainQueue [spl_object_hash ( $event->getPlayer () )] = "{$block->x}.{$block->y}.{$block->z}";
				$this->message ( $event->getPlayer (), $this->get ( "AskAgain" ) . $this->economyAPI->myMoney ( $player ) . " $ ]" );
				return;
			} else {
				if ($this->askAgainQueue [spl_object_hash ( $event->getPlayer () )] != "{$block->x}.{$block->y}.{$block->z}") {
					$this->askAgainQueue [spl_object_hash ( $event->getPlayer () )] = "{$block->x}.{$block->y}.{$block->z}";
					$this->message ( $event->getPlayer (), $this->get ( "AskAgain" ) . $this->economyAPI->myMoney ( $player ) . " $ ]" );
					return;
				} else {
					unset ( $this->askAgainQueue [spl_object_hash ( $event->getPlayer () )] );
				}
			}
			$gamble = $this->db ["showCase"] ["{$block->x}.{$block->y}.{$block->z}"];
			switch ($gamble) {
				case $this->get ( "itemgamble" ) : // 아이템겜블
					if ($event->getItem ()->getID () == 0) {
						$this->alert ( $player, $this->get ( "noitem" ) );
						if ($event->getItem ()->isPlaceable ())
							$this->placeQueue [$player->getName ()] = true;
						return;
					}
					$rand = mt_rand ( 0, $this->getProbability ( "ItemGambleProbability", 1 ) );
					if ($rand <= $this->getProbability ( "ItemGambleProbability", 0 )) {
						$player->getInventory ()->addItem ( Item::get ( $event->getItem ()->getID (), $event->getItem ()->getDamage (), 1 ) );
						$this->message ( $player, $this->get ( "SuccessitemGamble" ) );
					} else {
						$player->getInventory ()->removeItem ( Item::get ( $event->getItem ()->getID (), $event->getItem ()->getDamage (), 1 ) );
						$this->message ( $player, $this->get ( "FailitemGamble" ) );
					}
					if ($event->getItem ()->isPlaceable ())
						$this->placeQueue [$player->getName ()] = true;
					break;
				case $this->get ( "randomgamble" ) : // 랜덤겜블
					$mymoney = $this->economyAPI->myMoney ( $player );
					if ($mymoney == 0) {
						$this->message ( $player, $this->get ( "LackOfhMoney" ) );
						if ($event->getItem ()->isPlaceable ())
							$this->placeQueue [$player->getName ()] = true;
						return 0;
					}
					$pay = mt_rand ( 0, $mymoney );
					$rand = mt_rand ( 0, $this->getProbability ( "RandomGambleProbability", 1 ) );
					if ($rand <= $this->getProbability ( "RandomGambleProbability", 0 )) {
						$this->economyAPI->addMoney ( $player, $pay );
						$this->message ( $player, $this->get ( "SuccessGamble" ) . " $" . $pay . $this->get ( "SuccessGamble-a" ) );
					} else {
						$this->economyAPI->reduceMoney ( $player, $pay );
						$this->message ( $player, $this->get ( "FailGamble" ) . " $" . $pay . $this->get ( "FailGamble-a" ) );
					}
					if ($event->getItem ()->isPlaceable ())
						$this->placeQueue [$player->getName ()] = true;
					break;
				case $this->get ( "lottery" ) :
					$mymoney = $this->economyAPI->myMoney ( $player );
					if ($mymoney < $this->probability ["LotteryPrice"]) {
						$this->message ( $player, $this->get ( "LackOfhMoney" ) );
						if ($event->getItem ()->isPlaceable ())
							$this->placeQueue [$player->getName ()] = true;
						return;
					}
					$lotto_c = 0;
					if (isset ( $this->lotto [$player->getName ()] )) {
						$get = $this->lotto [$player->getName ()];
						$lotto_c = $get ["count"];
					}
					if ($lotto_c >= 25) {
						$this->message ( $player, $this->get ( "Toomuch-Lottery" ) );
						return;
					}
					$this->lotto [$player->getName ()] = array (
							"count" => ++ $lotto_c,
							"day" => date ( "d" ) 
					);
					
					$this->economyAPI->reduceMoney ( $player, $this->getProbability ( "LotteryPrice", 0 ) );
					$this->message ( $player, $this->get ( "LotteryPayment" ) . $this->lotto [$player->getName ()] ["count"] );
					$this->message ( $player, $this->get ( "LotteryPayment-a" ) );
					if ($event->getItem ()->isPlaceable ())
						$this->placeQueue [$player->getName ()] = true;
					break;
				default :
					if (is_numeric ( $gamble )) { // 겜블
						$money = $gamble;
						$mymoney = $this->economyAPI->myMoney ( $player );
						if ($mymoney < $money) {
							$this->message ( $player, $this->get ( "LackOfhMoney" ) );
							if ($event->getItem ()->isPlaceable ())
								$this->placeQueue [$player->getName ()] = true;
							return;
						}
						$rand = mt_rand ( 0, $this->getProbability ( "GambleProbability", 1 ) );
						if ($rand <= $this->getProbability ( "GambleProbability", 0 )) {
							$this->economyAPI->addMoney ( $player, $money );
							$this->message ( $player, $this->get ( "SuccessGamble" ) );
						} else {
							$this->economyAPI->reduceMoney ( $player, $money );
							$this->message ( $player, $this->get ( "FailGamble" ) );
						}
						if ($event->getItem ()->isPlaceable ())
							$this->placeQueue [$player->getName ()] = true;
					}
					break;
			}
		}
		if (isset ( $this->createQueue [$player->getName ()] )) {
			$event->setCancelled ();
			$pos = $block->getSide ( 1 );
			$gamble = $this->createQueue [$player->getName ()];
			$this->db ["showCase"] ["{$pos->x}.{$pos->y}.{$pos->z}"] = $gamble;
			$block->getLevel ()->setBlock ( $pos, Block::get ( Item::GLASS ), true );
			$this->message ( $player, $this->get ( "gamble-completely-created" ) );
			unset ( $this->createQueue [$player->getName ()] );
		}
	}
	public function onPlace(BlockPlaceEvent $event) {
		if (isset ( $this->placeQueue [$event->getPlayer ()->getName ()] )) {
			$event->setCancelled ();
			unset ( $this->placeQueue [$event->getPlayer ()->getName ()] );
		}
	}
	public function onCommand(CommandSender $player, Command $command, $label, Array $args) {
		if (! $player instanceof Player) {
			$this->alert ( $player, $this->get ( "only-in-game" ) );
			return true;
		}
		switch (strtolower ( $command->getName () )) {
			case $this->get ( "commands-gamble" ) :
				if (! isset ( $args [0] )) {
					$this->message ( $player, $this->get ( "commands-eg-help1" ) );
					$this->message ( $player, $this->get ( "commands-eg-help2" ) );
					$this->message ( $player, $this->get ( "commands-eg-help3" ) );
					break;
				}
				switch ($args [0]) {
					case $this->get ( "sub-commands-create" ) :
						(isset ( $args [1] )) ? $this->GambleCreateQueue ( $player, $args [1] ) : $this->GambleCreateQueue ( $player );
						break;
					case $this->get ( "sub-commands-cancel" ) :
						// ( 모든 큐를 초기화 )
						if (isset ( $this->createQueue [$player->getName ()] ))
							unset ( $this->createQueue [$player->getName ()] );
						$this->message ( $player, $this->get ( "all-processing-is-stopped" ) );
						break;
					case $this->get ( "sub-commands-seegamble" ) :
						if (isset ( $this->db ["settings"] ["seeGamble"] )) {
							if ($this->db ["settings"] ["seeGamble"]) {
								$this->db ["settings"] ["seeGamble"] = false;
								$this->message ( $player, $this->get ( "seegamble-disabled" ) );
							} else {
								$this->db ["settings"] ["seeGamble"] = true;
								$this->message ( $player, $this->get ( "seegamble-enabled" ) );
							}
						} else {
							$this->db ["settings"] ["seeGamble"] = true;
							$this->message ( $player, $this->get ( "seegamble-enabled" ) );
						}
						break;
					default :
						$this->message ( $player, $this->get ( "commands-eg-help1" ) );
						$this->message ( $player, $this->get ( "commands-eg-help2" ) );
						$this->message ( $player, $this->get ( "commands-eg-help3" ) );
						break;
				}
				break;
		}
		return true;
	}
	public function GambleCreateQueue(Player $player, $gamble = null) {
		if ($gamble == null or (! is_numeric ( $gamble ) and ($gamble != $this->get ( "itemgamble" ) and $gamble != $this->get ( "randomgamble" ) and $gamble != $this->get ( "lottery" )))) {
			$this->message ( $player, $this->get ( "gamble-help1" ) );
			$this->message ( $player, $this->get ( "gamble-help2" ) );
			$this->message ( $player, $this->get ( "gamble-help3" ) );
			$this->message ( $player, $this->get ( "gamble-help4" ) );
			return;
		}
		$this->message ( $player, $this->get ( "which-you-want-place-choose-pos" ) );
		$this->createQueue [$player->getName ()] = $gamble;
	}
	public function EconomyGamble() {
		// 스케쥴로 매번 위치확인하면서 생성작업시작
		if (! isset ( $this->db ["showCase"] ))
			return;
		foreach ( $this->getServer ()->getOnlinePlayers () as $player ) {
			foreach ( $this->db ["showCase"] as $gamblePos => $type ) {
				$explode = explode ( ".", $gamblePos );
				if (! isset ( $explode [2] ))
					continue; // 좌표가 아닐경우 컨티뉴
				$dx = abs ( $explode [0] - $player->x );
				$dy = abs ( $explode [1] - $player->y );
				$dz = abs ( $explode [2] - $player->z ); // XYZ 좌표차이 계산
				                                         
				// 반경 25블럭을 넘어갔을경우 생성해제 패킷 전송후 생성패킷큐를 제거
				if (! ($dx <= 25 and $dy <= 25 and $dz <= 25)) {
					if (! isset ( $this->packetQueue [$player->getName ()] [$gamblePos] ))
						continue;
					
					if (isset ( $this->packetQueue [$player->getName ()] [$gamblePos] )) {
						$this->packet ["RemoveEntityPacket"]->eid = $this->packetQueue [$player->getName ()] [$gamblePos];
						$player->dataPacket ( $this->packet ["RemoveEntityPacket"] ); // 아이템 제거패킷 전송
						unset ( $this->packetQueue [$player->getName ()] [$gamblePos] );
					}
					if (isset ( $this->packetQueue [$player->getName ()] ["nametag"] [$gamblePos] )) {
						$this->packet ["RemovePlayerPacket"]->eid = $this->packetQueue [$player->getName ()] ["nametag"] [$gamblePos];
						$player->dataPacket ( $this->packet ["RemovePlayerPacket"] ); // 네임택 제거패킷 전송
						unset ( $this->packetQueue [$player->getName ()] ["nametag"] [$gamblePos] );
					}
					continue;
				} else {
					if (! isset ( $this->packetQueue [$player->getName ()] [$gamblePos] )) {
						// 반경 25블럭 내일경우 생성패킷 전송 후 생성패킷큐에 추가
						$this->packetQueue [$player->getName ()] [$gamblePos] = Entity::$entityCount ++;
						$this->packet ["AddItemEntityPacket"]->eid = $this->packetQueue [$player->getName ()] [$gamblePos];
						$this->packet ["AddItemEntityPacket"]->x = $explode [0] + 0.5;
						$this->packet ["AddItemEntityPacket"]->y = $explode [1];
						$this->packet ["AddItemEntityPacket"]->z = $explode [2] + 0.5;
						$player->dataPacket ( $this->packet ["AddItemEntityPacket"] );
					}
					// 네임택 비활성화 상태이면 네임택비출력
					if (isset ( $this->db ["settings"] ["nametagEnable"] ))
						if (! $this->db ["settings"] ["nametagEnable"])
							continue;
						
						// 반경 5블럭 내일경우 유저 패킷을 상점밑에 보내서 네임택 출력
					if ($dx <= 4 and $dy <= 4 and $dz <= 4) {
						if (isset ( $this->packetQueue [$player->getName ()] ["nametag"] [$gamblePos] ))
							continue;
							// 도박 네임택띄우기
						switch ($type) {
							case $this->get ( "itemgamble" ) :
								$nameTag = $this->get ( "itemgamble-desc1" );
								$nameTag .= "\n" . $this->get ( "itemgamble-desc2" );
								$nameTag .= "\n" . $this->get ( "itemgamble-desc3" );
								$nameTag .= "\n" . $this->get ( "itemgamble-desc4" );
								break;
							case $this->get ( "randomgamble" ) :
								$nameTag = $this->get ( "randomgamble-desc1" );
								$nameTag .= "\n" . $this->get ( "randomgamble-desc2" );
								$nameTag .= "\n" . $this->get ( "randomgamble-desc3" );
								break;
							case $this->get ( "lottery" ) :
								$nameTag = $this->get ( "lottery-desc1" );
								$nameTag .= '$' . $this->probability ["LotteryPrice"];
								$nameTag .= "\n" . $this->get ( "lottery-desc2" );
								$nameTag .= "\n" . $this->get ( "lottery-desc3" );
								$nameTag .= '$' . $this->probability ["LotteryCompensation"];
								break;
							default :
								if (is_numeric ( $type )) {
									$nameTag = $this->get ( "gamble-desc1" );
									$nameTag .= '$' . $type;
									$nameTag .= "\n" . $this->get ( "gamble-desc2" );
									$nameTag .= "\n" . $this->get ( "gamble-desc3" );
									break;
								}
								$nameTag = "unknown";
								break;
						}
						$this->packetQueue [$player->getName ()] ["nametag"] [$gamblePos] = Entity::$entityCount ++;
						$this->packet ["AddPlayerPacket"]->eid = $this->packetQueue [$player->getName ()] ["nametag"] [$gamblePos];
						$this->packet ["AddPlayerPacket"]->clientID = $this->packetQueue [$player->getName ()] ["nametag"] [$gamblePos];
						$this->packet ["AddPlayerPacket"]->username = $nameTag;
						$this->packet ["AddPlayerPacket"]->x = $explode [0] + 0.4;
						$this->packet ["AddPlayerPacket"]->y = $explode [1] - 3.2;
						$this->packet ["AddPlayerPacket"]->z = $explode [2] + 0.4;
						$player->dataPacket ( $this->packet ["AddPlayerPacket"] );
					} else if (isset ( $this->packetQueue [$player->getName ()] ["nametag"] [$gamblePos] )) {
						$this->packet ["RemovePlayerPacket"]->eid = $this->packetQueue [$player->getName ()] ["nametag"] [$gamblePos];
						$this->packet ["RemovePlayerPacket"]->clientID = $this->packetQueue [$player->getName ()] ["nametag"] [$gamblePos];
						$player->dataPacket ( $this->packet ["RemovePlayerPacket"] ); // 네임택 제거패킷 전송
						unset ( $this->packetQueue [$player->getName ()] ["nametag"] [$gamblePos] );
					}
				}
			}
		}
	}
	public function getProbability($var, $num) {
		$e = explode ( "/", $this->probability [$var] );
		return $e [$num];
	}
	public function lottocheck(PlayerJoinEvent $event) {
		$player = $event->getPlayer ();
		if (isset ( $this->lotto [$player->getName ()] )) {
			$get = $this->lotto [$player->getName ()];
			$day = $get ["day"];
			$count = $get ["count"];
			
			if ($count != 0 and date ( "d" ) != $day) {
				$this->message ( $player, $this->get ( "LotteryCheck" ) );
				$resultCount = $count;
				for($i = 1; $i <= $count; $i ++) {
					$rand = mt_rand ( 0, $this->getProbability ( "LotteryProbability", 1 ) );
					if (! ($rand <= $this->getProbability ( "LotteryProbability", 0 )))
						-- $resultCount;
				}
				if ($resultCount == 0) {
					$this->message ( $player, $this->get ( "LotteryCheck-a" ) . " " . $count . $this->get ( "LotteryCheck-b" ) );
					$this->message ( $player, $this->get ( "FailGamble" ) );
				} else {
					$this->economyAPI->addMoney ( $player, $resultCount * $this->getProbability ( "LotteryCompensation", 0 ) );
					foreach ( $this->getServer ()->getOnlinePlayers () as $p ) {
						$this->message ( $p, $player->getName () . $this->get ( "SuccessLottery" ) );
						$this->message ( $p, $this->get ( "SuccessLottery-a" ) . $resultCount . $this->get ( "SuccessLottery-b" ) . $resultCount * $this->getProbability ( "LotteryCompensation", 0 ) . $this->get ( "SuccessLottery-c" ) );
					}
				}
				unset ( $this->lotto [$player->getName ()] );
			}
		}
	}
	public function onQuit(PlayerQuitEvent $event) {
		if (isset ( $this->createQueue [$event->getPlayer ()->getName ()] ))
			unset ( $this->createQueue [$event->getPlayer ()->getName ()] );
		if (isset ( $this->packetQueue [$event->getPlayer ()->getName ()] ))
			unset ( $this->packetQueue [$event->getPlayer ()->getName ()] );
	}
}

?>