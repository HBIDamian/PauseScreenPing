<?php
declare(strict_types=1);

namespace HBIDamian\PauseScreenPing;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {
    private $config;
    public function onEnable(): void {
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $updateInterval = $this->config->get("update-interval", 20);
        if (!is_int($updateInterval) || $updateInterval <= 0) {
            $this->getLogger()->warning("Invalid update-interval in config.yml. Using default value of 20.");
            $updateInterval = 20;
        }
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->updateScoreboards();
        }), $updateInterval);
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->createScoreboard($player);
    }

    public function onQuit(PlayerQuitEvent $event): void {
        $disconnectedPlayer = $event->getPlayer();
        $this->removePlayerFromScoreboards($disconnectedPlayer);
    }

    public function updateScoreboards(): void {
        $onlySeeYourself = (bool)$this->config->get("only-see-own-ping", false);

        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if ($onlySeeYourself) {
                $this->updateScoreboard($player, [$player]);
            } else {
                $this->updateScoreboard($player, $this->getServer()->getOnlinePlayers());
            }
        }
    }

    private function createScoreboard(Player $player): void {
        $objectivePacket = new SetDisplayObjectivePacket();
        $objectivePacket->displaySlot = "list";
        $objectivePacket->objectiveName = "hbidamian_player_ping"; // Unique name to prevent conflicts
        $displayName = (string)$this->config->get("display-name", "&dPauseScreenPing");
        $objectivePacket->displayName = TextFormat::colorize($displayName);
        $objectivePacket->criteriaName = "dummy";
        $objectivePacket->sortOrder = 0;
        $player->getNetworkSession()->sendDataPacket($objectivePacket);
        $this->updateScoreboard($player, [$player]);
    }

    private function updateScoreboard(Player $player, array $targets): void {
        $scorePacket = new SetScorePacket();
        $scorePacket->type = SetScorePacket::TYPE_CHANGE;
        foreach ($targets as $target) {
            $entry = new ScorePacketEntry();
            $entry->objectiveName = "hbidamian_player_ping";
            $entry->type = ScorePacketEntry::TYPE_PLAYER;
            $entry->customName = $target->getName();
            $entry->score = $target->getNetworkSession()->getPing();
            $entry->scoreboardId = $target->getId();
            $entry->actorUniqueId = $target->getId();
            $scorePacket->entries[] = $entry;
        }
        $player->getNetworkSession()->sendDataPacket($scorePacket);
    }

    private function removePlayerFromScoreboards(Player $disconnectedPlayer): void {
        foreach ($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
            // Don't try to update the scoreboard of the player who just disconnected
            if ($onlinePlayer === $disconnectedPlayer) {
                continue;
            }
            
            $scorePacket = new SetScorePacket();
            $scorePacket->type = SetScorePacket::TYPE_REMOVE;
            $entry = new ScorePacketEntry();
            $entry->objectiveName = "hbidamian_player_ping";
            $entry->type = ScorePacketEntry::TYPE_PLAYER;
            $entry->customName = $disconnectedPlayer->getName();
            $entry->score = 0; // Score is required even for removal
            $entry->scoreboardId = $disconnectedPlayer->getId();
            $entry->actorUniqueId = $disconnectedPlayer->getId();
            $scorePacket->entries[] = $entry;
            
            $onlinePlayer->getNetworkSession()->sendDataPacket($scorePacket);
        }
    }
}