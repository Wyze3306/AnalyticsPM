<?php

declare(strict_types=1);

namespace mcpe\analytics;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\player\Player;

class EventListener implements Listener {

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    private function getPlatformName(Player $player): string {
        $data = $player->getNetworkSession()->getPlayerInfo();
        if ($data === null) return "Unknown";

        $extraData = $data->getExtraData();
        if (isset($extraData["DeviceOS"])) {
            return match((int)$extraData["DeviceOS"]) {
                1 => "Android",
                2 => "iOS",
                3 => "macOS",
                4 => "FireOS",
                5 => "GearVR",
                6 => "HoloLens",
                7 => "Windows",
                8 => "Windows",
                9 => "Dedicated",
                10 => "tvOS",
                11 => "PlayStation",
                12 => "Switch",
                13 => "Xbox",
                14 => "Windows Phone",
                15 => "Linux",
                default => "Unknown (" . $extraData["DeviceOS"] . ")"
            };
        }

        return "Unknown";
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $uuid = $player->getUniqueId()->toString();
        $platform = $this->getPlatformName($player);
        $now = (int)(microtime(true) * 1000);

        // Start session tracking
        $this->plugin->getSessionTracker()->startSession($uuid);

        // Send join event immediately
        $this->plugin->getApiClient()->sendJoin(
            $uuid,
            $player->getName(),
            $platform,
            $now,
            $player->getNetworkSession()->getIp()
        );

        // Track initial world
        if ($this->plugin->isTrackingEnabled("worlds")) {
            $this->plugin->getApiClient()->sendWorldChange(
                $uuid,
                $player->getWorld()->getFolderName(),
                $now
            );
        }

        if ($this->plugin->isDebug()) {
            $this->plugin->getLogger()->info("Join tracked: " . $player->getName() . " (" . $platform . ")");
        }
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $uuid = $player->getUniqueId()->toString();
        $now = (int)(microtime(true) * 1000);

        $playtime = $this->plugin->getSessionTracker()->endSession($uuid);

        $this->plugin->getApiClient()->sendLeave($uuid, $playtime);

        if ($this->plugin->isDebug()) {
            $this->plugin->getLogger()->info("Leave tracked: " . $player->getName() . " (playtime: " . round($playtime / 60000, 1) . "m)");
        }
    }

    public function onCommand(CommandEvent $event): void {
        if (!$this->plugin->isTrackingEnabled("commands")) return;

        $sender = $event->getSender();
        if (!$sender instanceof Player) return;

        $commandLine = $event->getCommand();
        $parts = explode(" ", $commandLine, 2);
        $command = $parts[0];
        $arguments = $parts[1] ?? "";

        $this->plugin->getBuffer()->add([
            "type" => "command",
            "uuid" => $sender->getUniqueId()->toString(),
            "command" => $command,
            "arguments" => $arguments,
            "world" => $sender->getWorld()->getFolderName(),
            "timestamp" => (int)(microtime(true) * 1000),
        ]);
    }

    public function onPlayerChat(PlayerChatEvent $event): void {
        if (!$this->plugin->isTrackingEnabled("chat")) return;

        $player = $event->getPlayer();

        $this->plugin->getBuffer()->add([
            "type" => "chat",
            "uuid" => $player->getUniqueId()->toString(),
            "message" => $event->getMessage(),
            "world" => $player->getWorld()->getFolderName(),
            "timestamp" => (int)(microtime(true) * 1000),
        ]);
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void {
        if (!$this->plugin->isTrackingEnabled("deaths")) return;

        $player = $event->getPlayer();
        $pos = $player->getPosition();
        $deathMessage = $event->getDeathMessage();
        if ($deathMessage instanceof \pocketmine\lang\Translatable) {
            $cause = $deathMessage->getText();
        } elseif (is_string($deathMessage)) {
            $cause = $deathMessage;
        } else {
            $cause = "Unknown";
        }

        $this->plugin->getBuffer()->add([
            "type" => "death",
            "uuid" => $player->getUniqueId()->toString(),
            "cause" => $cause,
            "world" => $player->getWorld()->getFolderName(),
            "x" => $pos->getX(),
            "y" => $pos->getY(),
            "z" => $pos->getZ(),
            "timestamp" => (int)(microtime(true) * 1000),
        ]);
    }

    public function onEntityTeleport(EntityTeleportEvent $event): void {
        if (!$this->plugin->isTrackingEnabled("worlds")) return;

        $entity = $event->getEntity();
        if (!$entity instanceof Player) return;

        $from = $event->getFrom()->getWorld();
        $to = $event->getTo()->getWorld();

        if ($from->getFolderName() === $to->getFolderName()) return;

        $this->plugin->getApiClient()->sendWorldChange(
            $entity->getUniqueId()->toString(),
            $to->getFolderName(),
            (int)(microtime(true) * 1000)
        );
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        if (!$this->plugin->isTrackingEnabled("blocks")) return;

        $player = $event->getPlayer();
        $block = $event->getBlock();
        $pos = $block->getPosition();

        $this->plugin->getBuffer()->add([
            "type" => "block",
            "uuid" => $player->getUniqueId()->toString(),
            "action" => "break",
            "block_id" => $block->getName(),
            "world" => $pos->getWorld()->getFolderName(),
            "x" => $pos->getFloorX(),
            "y" => $pos->getFloorY(),
            "z" => $pos->getFloorZ(),
            "timestamp" => (int)(microtime(true) * 1000),
        ]);
    }

    public function onBlockPlace(BlockPlaceEvent $event): void {
        if (!$this->plugin->isTrackingEnabled("blocks")) return;

        $player = $event->getPlayer();

        foreach ($event->getTransaction()->getBlocks() as [$x, $y, $z, $block]) {
            $this->plugin->getBuffer()->add([
                "type" => "block",
                "uuid" => $player->getUniqueId()->toString(),
                "action" => "place",
                "block_id" => $block->getName(),
                "world" => $player->getWorld()->getFolderName(),
                "x" => $x,
                "y" => $y,
                "z" => $z,
                "timestamp" => (int)(microtime(true) * 1000),
            ]);
        }
    }
}
