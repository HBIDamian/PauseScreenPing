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
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\utils\Config;

/**
 * PauseScreenPing Plugin
 * 
 * Displays player ping information on the pause screen scoreboard
 * with support for dynamic display names and various configuration options.
 * 
 * @author HBIDamian
 */
class Main extends PluginBase implements Listener {
    
    private const OBJECTIVE_NAME = "hbidamian_player_ping";
    private const CONFIG_VERSION = "1.2.0";
    
    private Config $config;
    private int $currentDisplayNameIndex = 0;
    private array $dynamicDisplayNames = [];
    
    /**
     * Plugin initialization
     */
    public function onEnable(): void {
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        
        // Validate config version
        if ($this->config->get("config-version") !== self::CONFIG_VERSION) {
            $this->getLogger()->error("The config version is invalid. Please update the config.yml.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        
        $updateInterval = $this->validateUpdateInterval();
        $this->initializeDynamicDisplayNames();
        
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->scheduleScoreboardUpdates($updateInterval);
        $this->scheduleDynamicDisplayNameUpdates();
    }

    /**
     * Validate and get the update interval from config
     */
    private function validateUpdateInterval(): int {
        $updateInterval = $this->config->get("update-interval", 20);
        if (!is_int($updateInterval) || $updateInterval <= 0) {
            $this->getLogger()->warning("Invalid update-interval in config.yml. Using default value of 20.");
            return 20;
        }
        return $updateInterval;
    }

    /**
     * Schedule scoreboard updates
     */
    private function scheduleScoreboardUpdates(int $updateInterval): void {
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->updateScoreboards();
        }), $updateInterval);
    }

    /**
     * Schedule dynamic display name updates if enabled
     */
    private function scheduleDynamicDisplayNameUpdates(): void {
        if (!$this->config->get("dynamic-display-name", false)) {
            return;
        }
        
        $dynamicInterval = $this->config->get("dynamic-name-interval", 60);
        if (!is_int($dynamicInterval) || $dynamicInterval <= 0) {
            $this->getLogger()->warning("Invalid dynamic-name-interval in config.yml. Using default value of 60.");
            $dynamicInterval = 60;
        }
        
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->cycleDynamicDisplayName();
        }), $dynamicInterval);
    }

    /**
     * Replace variables in strings with actual values
     */
    private function replaceVars(string $str): string {
        $replaceArray = [
            '{POCKETMINE_API}' => $this->getServer()->getApiVersion(),
            '{SERVER_VERSION}' => $this->getServer()->getVersion(),
            '{ONLINE_PLAYERS}' => (string) count($this->getServer()->getOnlinePlayers()),
            '{MAX_PLAYERS}' => (string) $this->getServer()->getMaxPlayers()
        ];

        return str_replace(array_keys($replaceArray), array_values($replaceArray), $str);
    }

    /**
     * Initialize dynamic display names from config
     */
    private function initializeDynamicDisplayNames(): void {
        if (!$this->config->get("dynamic-display-name", false)) {
            return;
        }
        
        $dynamicNames = $this->config->get("dynamic-display-names", []);
        if (is_array($dynamicNames) && !empty($dynamicNames)) {
            $this->dynamicDisplayNames = $dynamicNames;
        } else {
            $this->getLogger()->warning("No valid dynamic-display-names found in config.yml. Disabling dynamic display names.");
            $this->dynamicDisplayNames = [];
        }
    }

    /**
     * Cycle through dynamic display names
     */
    private function cycleDynamicDisplayName(): void {
        if (empty($this->dynamicDisplayNames)) {
            return;
        }
        
        $shuffleMode = $this->config->get("dynamic-display-names-shuffle", "off"); // DDNS... get it? 😅
        
        if ($shuffleMode === "on") {
            // Shuffle mode: random selection
            $this->currentDisplayNameIndex = mt_rand(0, count($this->dynamicDisplayNames) - 1);
        } elseif ($shuffleMode === "off") {
            // Sequential mode: cycle through in order
            $this->currentDisplayNameIndex = ($this->currentDisplayNameIndex + 1) % count($this->dynamicDisplayNames);
        } else {
            // Error handling for invalid shuffle mode
            $this->getLogger()->error("Invalid dynamic-display-names-shuffle setting in config.yml. Use 'on' or 'off'. Defaulting to sequential mode.");
            $this->currentDisplayNameIndex = ($this->currentDisplayNameIndex + 1) % count($this->dynamicDisplayNames);
        }
        
        // Remove and recreate scoreboards for all players with the new display name
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $this->removeScoreboard($player);
            $this->createScoreboard($player);
        }
    }

    /**
     * Get the current display name (dynamic or static)
     */
    private function getCurrentDisplayName(): string {
        if ($this->config->get("dynamic-display-name", false) && !empty($this->dynamicDisplayNames)) {
            $displayName = $this->dynamicDisplayNames[$this->currentDisplayNameIndex];
            return $this->replaceVars($displayName);
        }

        $staticDisplayName = (string) $this->config->get("static-display-name", "&dPauseScreenPing");
        return $this->replaceVars($staticDisplayName);
    }

    /**
     * Remove scoreboard for a player
     */
    private function removeScoreboard(Player $player): void {
        $removePacket = new RemoveObjectivePacket();
        $removePacket->objectiveName = self::OBJECTIVE_NAME;
        $player->getNetworkSession()->sendDataPacket($removePacket);
    }

    /**
     * Handle player join event
     */
    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->createScoreboard($player);
        
        // If not in "only-see-own-ping" mode, refresh all scoreboards to show the new player
        if (!(bool) $this->config->get("only-see-own-ping", false)) {
            // Small delay to ensure player is fully loaded
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function (): void {
                $this->updateScoreboards();
            }), 5); // 5 ticks delay
        }
    }

    /**
     * Handle player quit event
     */
    public function onQuit(PlayerQuitEvent $event): void {
        $disconnectedPlayer = $event->getPlayer();
        $this->removePlayerFromScoreboards($disconnectedPlayer);
    }

    /**
     * Update scoreboards for all online players
     */
    public function updateScoreboards(): void {
        $onlySeeYourself = (bool) $this->config->get("only-see-own-ping", false);
        $onlinePlayers = $this->getServer()->getOnlinePlayers();

        foreach ($onlinePlayers as $player) {
            if (!$player->isConnected()) {
                continue;
            }
            
            if ($onlySeeYourself) {
                $this->updateScoreboard($player, [$player]);
            } else {
                $this->updateScoreboard($player, $onlinePlayers);
            }
        }
    }

    /**
     * Create scoreboard for a player
     */
    private function createScoreboard(Player $player): void {
        $objectivePacket = new SetDisplayObjectivePacket();
        $objectivePacket->displaySlot = "list";
        $objectivePacket->objectiveName = self::OBJECTIVE_NAME;
        $objectivePacket->displayName = TextFormat::colorize($this->getCurrentDisplayName());
        $objectivePacket->criteriaName = "dummy";
        $objectivePacket->sortOrder = 0;
        $player->getNetworkSession()->sendDataPacket($objectivePacket);
        
        // Respect the only-see-own-ping setting when creating scoreboard
        $onlySeeYourself = (bool) $this->config->get("only-see-own-ping", false);
        if ($onlySeeYourself) {
            $this->updateScoreboard($player, [$player]);
        } else {
            $this->updateScoreboard($player, $this->getServer()->getOnlinePlayers());
        }
    }

    /**
     * Update scoreboard for a specific player with target players
     */
    private function updateScoreboard(Player $player, array $targets): void {
        if (empty($targets)) {
            return;
        }
        
        $scorePacket = new SetScorePacket();
        $scorePacket->type = SetScorePacket::TYPE_CHANGE;
        
        foreach ($targets as $target) {
            if (!$target instanceof Player || !$target->isConnected()) {
                continue;
            }
            
            $entry = new ScorePacketEntry();
            $entry->objectiveName = self::OBJECTIVE_NAME;
            $entry->type = ScorePacketEntry::TYPE_PLAYER;
            $entry->customName = $target->getName();
            $entry->score = $target->getNetworkSession()->getPing();
            $entry->scoreboardId = $target->getId();
            $entry->actorUniqueId = $target->getId();
            $scorePacket->entries[] = $entry;
        }
        
        if (!empty($scorePacket->entries)) {
            $player->getNetworkSession()->sendDataPacket($scorePacket);
        }
    }

    /**
     * Remove a disconnected player from all scoreboards
     */
    private function removePlayerFromScoreboards(Player $disconnectedPlayer): void {
        foreach ($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
            // Don't try to update the scoreboard of the player who just disconnected
            if ($onlinePlayer === $disconnectedPlayer) {
                continue;
            }
            
            $scorePacket = new SetScorePacket();
            $scorePacket->type = SetScorePacket::TYPE_REMOVE;
            
            $entry = new ScorePacketEntry();
            $entry->objectiveName = self::OBJECTIVE_NAME;
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