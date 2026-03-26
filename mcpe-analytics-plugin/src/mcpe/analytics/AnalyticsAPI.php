<?php

declare(strict_types=1);

namespace mcpe\analytics;

use pocketmine\player\Player;
use pocketmine\Server;

/**
 * Public API for other plugins to send logs to the analytics panel.
 *
 * Usage from any plugin:
 *
 *   use mcpe\analytics\AnalyticsAPI;
 *
 *   // Simple log
 *   AnalyticsAPI::log("economy", "Transaction", $player, [
 *       "detail" => "Bought Diamond Sword for 500$",
 *       "item_name" => "Diamond Sword",
 *       "item_count" => 1,
 *   ]);
 *
 *   // Warning level
 *   AnalyticsAPI::warning("anticheat", "SpeedHack", $player, [
 *       "detail" => "Moving at 45 blocks/s",
 *   ]);
 *
 *   // Log with target player
 *   AnalyticsAPI::log("trade", "PlayerTrade", $player, [
 *       "target_player" => $otherPlayer->getName(),
 *       "item_name" => "Onyx",
 *       "item_count" => 5,
 *       "detail" => "Traded 5 Onyx for 3 Rubis",
 *   ]);
 *
 *   // Log without a player (server event)
 *   AnalyticsAPI::server("event", "AirDropStart", [
 *       "detail" => "AirDrop spawned at spawn",
 *       "world" => "world",
 *       "x" => 100, "y" => 80, "z" => 200,
 *   ]);
 *
 *   // Batch multiple logs at once
 *   AnalyticsAPI::batch([
 *       ["category" => "economy", "action" => "Deposit", "player" => "Steve", "detail" => "+1000$"],
 *       ["category" => "economy", "action" => "Withdraw", "player" => "Alex", "detail" => "-500$"],
 *   ]);
 */
class AnalyticsAPI {

    private static ?Main $plugin = null;

    /** @var array[] buffer for logs when plugin isn't loaded yet */
    private static array $earlyBuffer = [];

    /**
     * Called by Main::onEnable() to register the plugin instance.
     * @internal
     */
    public static function register(Main $plugin): void {
        self::$plugin = $plugin;

        // Flush any logs that were buffered before the plugin loaded
        if (!empty(self::$earlyBuffer)) {
            $plugin->getLogger()->info("Flushing " . count(self::$earlyBuffer) . " early-buffered API logs");
            foreach (self::$earlyBuffer as $entry) {
                self::$plugin->getExternalLogsBuffer()->add($entry);
            }
            self::$earlyBuffer = [];
        }
    }

    /**
     * Called by Main::onDisable().
     * @internal
     */
    public static function unregister(): void {
        self::$plugin = null;
    }

    /**
     * Check if the analytics system is available.
     */
    public static function isAvailable(): bool {
        return self::$plugin !== null;
    }

    /**
     * Send a log entry with a player context.
     *
     * @param string $category  Category name (e.g. "economy", "anticheat", "trade", "sanction", "event")
     * @param string $action    Action name (e.g. "Transaction", "SpeedHack", "Ban", "AirDropWin")
     * @param Player $player    The player involved
     * @param array  $extra     Optional extra fields:
     *                          - detail: string (free text description)
     *                          - item_name: string
     *                          - item_count: int
     *                          - target_player: string (name of another player involved)
     *                          - world: string (overrides player's current world)
     *                          - x, y, z: float (overrides player's position)
     *                          - level: string ("info" or "warning", default "info")
     */
    public static function log(string $category, string $action, Player $player, array $extra = []): void {
        $pos = $player->getPosition();
        $entry = array_merge([
            "uuid" => $player->getUniqueId()->toString(),
            "player" => $player->getName(),
            "category" => $category,
            "action" => $action,
            "world" => $pos->getWorld()->getFolderName(),
            "x" => round($pos->getX(), 1),
            "y" => round($pos->getY(), 1),
            "z" => round($pos->getZ(), 1),
            "level" => "info",
            "timestamp" => (int)(microtime(true) * 1000),
        ], $extra);

        self::push($entry);
    }

    /**
     * Shortcut to send a warning-level log.
     */
    public static function warning(string $category, string $action, Player $player, array $extra = []): void {
        $extra["level"] = "warning";
        self::log($category, $action, $player, $extra);
    }

    /**
     * Send a log without a player context (server events, console actions, etc.)
     *
     * @param string $category  Category name
     * @param string $action    Action name
     * @param array  $extra     Fields: detail, item_name, item_count, target_player, world, x, y, z, level, player (name as string)
     */
    public static function server(string $category, string $action, array $extra = []): void {
        $entry = array_merge([
            "uuid" => null,
            "player" => $extra["player"] ?? "CONSOLE",
            "category" => $category,
            "action" => $action,
            "level" => "info",
            "timestamp" => (int)(microtime(true) * 1000),
        ], $extra);

        self::push($entry);
    }

    /**
     * Send multiple log entries at once.
     * Each entry must have at least "category" and "action".
     *
     * @param array[] $entries
     */
    public static function batch(array $entries): void {
        foreach ($entries as $entry) {
            if (!isset($entry["category"], $entry["action"])) continue;
            $entry["timestamp"] = $entry["timestamp"] ?? (int)(microtime(true) * 1000);
            $entry["level"] = $entry["level"] ?? "info";
            self::push($entry);
        }
    }

    private static function push(array $entry): void {
        if (self::$plugin !== null) {
            self::$plugin->getExternalLogsBuffer()->add($entry);
        } else {
            // Buffer until the plugin loads
            self::$earlyBuffer[] = $entry;
        }
    }
}
