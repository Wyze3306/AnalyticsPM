<?php

declare(strict_types=1);

namespace mcpe\analytics;

use pocketmine\event\Listener;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\inventory\CraftItemEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\inventory\CreativeInventory;
use pocketmine\block\inventory\ChestInventory;
use pocketmine\block\inventory\DoubleChestInventory;
use pocketmine\block\inventory\EnderChestInventory;
use pocketmine\block\inventory\FurnaceInventory;
use pocketmine\block\inventory\BarrelInventory;
use pocketmine\block\inventory\HopperInventory;
use pocketmine\block\inventory\ShulkerBoxInventory;
use pocketmine\block\inventory\AnvilInventory;
use pocketmine\block\inventory\BrewingStandInventory;
use pocketmine\block\inventory\EnchantInventory;
use pocketmine\inventory\ArmorInventory;
use pocketmine\player\Player;
use pocketmine\world\Position;

class LogsTracker implements Listener {

    private Main $plugin;

    /** @var array[] buffered logs */
    private array $buffer = [];

    /** Items to always track (from your LinesiaCore config) */
    private const TRACKED_KEYWORDS = [
        "onyx", "cash", "soul", "sponge", "spawn", "egg", "ball", "bow", "clé", "key", "rubis", "pocket"
    ];

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    private function isTrackedItem(string $itemName): bool {
        $lower = strtolower($itemName);
        foreach (self::TRACKED_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    private function posStr(Position $pos): array {
        return [
            "world" => $pos->getWorld()->getFolderName(),
            "x" => round($pos->getX(), 1),
            "y" => round($pos->getY(), 1),
            "z" => round($pos->getZ(), 1),
        ];
    }

    private function log(string $category, string $action, ?Player $player, array $extra = [], string $level = "info"): void {
        $entry = array_merge([
            "uuid" => $player?->getUniqueId()->toString(),
            "player" => $player?->getName(),
            "category" => $category,
            "action" => $action,
            "level" => $level,
            "timestamp" => (int)(microtime(true) * 1000),
        ], $extra);

        $this->buffer[] = $entry;

        // Auto-flush at 50 entries
        if (count($this->buffer) >= 50) {
            $this->flush();
        }
    }

    public function flush(): void {
        if (empty($this->buffer)) return;
        $entries = $this->buffer;
        $this->buffer = [];
        $this->plugin->getApiClient()->sendLogsAsync($entries);
    }

    public function getBufferCount(): int {
        return count($this->buffer);
    }

    // ===================== CONNECTION =====================

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $pos = $this->posStr($player->getPosition());
        $this->log("connection", "Join", $player, array_merge($pos, [
            "detail" => "Platform: " . $this->plugin->getListener()->getPlatform($player),
        ]));
    }

    public function onQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $playtime = $this->plugin->getSessionTracker()->getSessionDuration($player->getUniqueId()->toString());
        $pos = $this->posStr($player->getPosition());
        $this->log("connection", "Quit", $player, array_merge($pos, [
            "detail" => "Playtime: " . round($playtime / 60000, 1) . "m",
        ]));
    }

    // ===================== COMMANDS =====================

    public function onCommand(CommandEvent $event): void {
        $sender = $event->getSender();
        if (!$sender instanceof Player) return;

        $cmd = $event->getCommand();
        $parts = explode(" ", $cmd, 2);
        $pos = $this->posStr($sender->getPosition());

        $this->log("command", $parts[0], $sender, array_merge($pos, [
            "detail" => $cmd,
        ]));
    }

    // ===================== CHAT =====================

    public function onChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        $pos = $this->posStr($player->getPosition());
        $this->log("chat", "Message", $player, array_merge($pos, [
            "detail" => $event->getMessage(),
        ]));
    }

    // ===================== ITEM TRACKING =====================

    public function onDrop(PlayerDropItemEvent $event): void {
        $item = $event->getItem();
        if (!$this->isTrackedItem($item->getVanillaName())) return;

        $player = $event->getPlayer();
        $pos = $this->posStr($player->getPosition());
        $this->log("item", "Drop", $player, array_merge($pos, [
            "item_name" => $item->getVanillaName(),
            "item_count" => $item->getCount(),
        ]));
    }

    public function onPickUp(EntityItemPickupEvent $event): void {
        $item = $event->getItem();
        if (!$this->isTrackedItem($item->getVanillaName())) return;

        $entity = $event->getEntity();
        if (!$entity instanceof Player) return;

        $origin = $event->getOrigin();
        $originName = ($origin instanceof Player) ? $origin->getName() : $origin->getNameTag();

        $pos = $this->posStr($entity->getPosition());
        $this->log("item", "PickUp", $entity, array_merge($pos, [
            "item_name" => $item->getVanillaName(),
            "item_count" => $item->getCount(),
            "target_player" => $originName,
            "detail" => "Dropped by " . $originName,
        ]));
    }

    public function onUse(PlayerItemUseEvent $event): void {
        $item = $event->getItem();
        if (!$this->isTrackedItem($item->getVanillaName())) return;

        $player = $event->getPlayer();
        $pos = $this->posStr($player->getPosition());
        $this->log("item", "Use", $player, array_merge($pos, [
            "item_name" => $item->getVanillaName(),
            "item_count" => $item->getCount(),
        ]));
    }

    public function onCraft(CraftItemEvent $event): void {
        $player = $event->getPlayer();
        foreach ($event->getOutputs() as $item) {
            if (!$this->isTrackedItem($item->getVanillaName())) continue;
            $pos = $this->posStr($player->getPosition());
            $this->log("item", "Craft", $player, array_merge($pos, [
                "item_name" => $item->getVanillaName(),
                "item_count" => $item->getCount(),
            ]));
        }
    }

    public function onTransaction(InventoryTransactionEvent $event): void {
        $player = $event->getTransaction()->getSource();
        $pos = $this->posStr($player->getPosition());
        $transaction = $event->getTransaction();

        foreach ($transaction->getInventories() as $inventory) {
            // Creative inventory detection
            if ($inventory instanceof CreativeInventory) {
                foreach ($transaction->getActions() as $action) {
                    $src = $action->getSourceItem();
                    if ($src->isNull()) continue;
                    $this->log("item", "Creative", $player, array_merge($pos, [
                        "item_name" => $src->getVanillaName(),
                        "item_count" => $src->getCount(),
                        "detail" => "Took from creative inventory",
                    ]), "warning");
                }
                continue;
            }

            $invTypes = [
                EnderChestInventory::class => "EnderChest",
                FurnaceInventory::class => "Furnace",
                ArmorInventory::class => "Armor",
                BarrelInventory::class => "Barrel",
                AnvilInventory::class => "Anvil",
                BrewingStandInventory::class => "BrewingStand",
                ChestInventory::class => "Chest",
                DoubleChestInventory::class => "DoubleChest",
                EnchantInventory::class => "Enchant",
                HopperInventory::class => "Hopper",
                ShulkerBoxInventory::class => "Shulker",
            ];

            $invName = $invTypes[get_class($inventory)] ?? "Transaction";

            foreach ($inventory->getContents() as $item) {
                if (!$this->isTrackedItem($item->getVanillaName())) continue;
                $this->log("item", $invName, $player, array_merge($pos, [
                    "item_name" => $item->getVanillaName(),
                    "item_count" => $item->getCount(),
                ]), "warning");
            }
        }
    }

    // ===================== BLOCKS =====================

    public function onBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $item = $block->asItem();
        if (!$this->isTrackedItem($item->getVanillaName())) return;

        $pos = $this->posStr($block->getPosition());
        $this->log("item", "Break", $player, array_merge($pos, [
            "item_name" => $item->getVanillaName(),
            "item_count" => $item->getCount(),
        ]));
    }

    public function onPlace(BlockPlaceEvent $event): void {
        $player = $event->getPlayer();
        foreach ($event->getTransaction()->getBlocks() as [$x, $y, $z, $block]) {
            $item = $block->asItem();
            if (!$this->isTrackedItem($item->getVanillaName())) continue;
            $this->log("item", "Place", $player, [
                "world" => $player->getWorld()->getFolderName(),
                "x" => $x, "y" => $y, "z" => $z,
                "item_name" => $item->getVanillaName(),
                "item_count" => $item->getCount(),
            ]);
        }
    }

    // ===================== DEATHS =====================

    public function onDeath(PlayerDeathEvent $event): void {
        $player = $event->getPlayer();
        $pos = $this->posStr($player->getPosition());
        $msg = $event->getDeathMessage();
        $cause = ($msg instanceof \pocketmine\lang\Translatable) ? $msg->getText() : (is_string($msg) ? $msg : "Unknown");

        $this->log("death", "Death", $player, array_merge($pos, [
            "detail" => $cause,
        ]));
    }
}
