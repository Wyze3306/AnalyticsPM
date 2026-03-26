<?php

declare(strict_types=1);

namespace mcpe\analytics;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;

class Main extends PluginBase {

    private EventListener $listener;
    private LogsTracker $logsTracker;
    private ApiClient $apiClient;
    private EventBuffer $buffer;
    private EventBuffer $externalLogsBuffer;
    private SessionTracker $sessionTracker;

    public function onEnable(): void {
        $this->saveDefaultConfig();

        $panelUrl = $this->getConfig()->get("panel-url", "http://localhost:3000");
        $apiKey = $this->getConfig()->get("api-key", "");
        $flushInterval = (int)$this->getConfig()->get("flush-interval", 30);

        if (empty($apiKey)) {
            $this->getLogger()->error("API key not configured! Plugin will not send data.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        $this->apiClient = new ApiClient($panelUrl, $apiKey, $this);
        $this->buffer = new EventBuffer();
        $this->externalLogsBuffer = new EventBuffer();
        $this->sessionTracker = new SessionTracker();

        $this->listener = new EventListener($this);
        $this->logsTracker = new LogsTracker($this);
        $this->getServer()->getPluginManager()->registerEvents($this->listener, $this);
        $this->getServer()->getPluginManager()->registerEvents($this->logsTracker, $this);

        // Register the public API so other plugins can send logs
        AnalyticsAPI::register($this);

        // Flush buffers periodically
        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function(): void {
                $this->flushBuffer();
                $this->logsTracker->flush();
                $this->flushExternalLogs();
            }),
            $flushInterval * 20
        );

        $this->getLogger()->info(TextFormat::GREEN . "MCPEAnalytics enabled! Panel: " . $panelUrl);
    }

    public function onDisable(): void {
        if (isset($this->buffer)) {
            $this->flushBuffer();
        }
        if (isset($this->logsTracker)) {
            $this->logsTracker->flush();
        }
        if (isset($this->externalLogsBuffer)) {
            $this->flushExternalLogs();
        }

        AnalyticsAPI::unregister();

        if (isset($this->sessionTracker)) {
            foreach ($this->getServer()->getOnlinePlayers() as $player) {
                $uuid = $player->getUniqueId()->toString();
                $playtime = $this->sessionTracker->endSession($uuid);
                if ($playtime > 0 && isset($this->apiClient)) {
                    $this->apiClient->sendLeave($uuid, $playtime);
                }
            }
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() !== "analytics") return false;

        $sub = $args[0] ?? "status";

        switch ($sub) {
            case "status":
                $sender->sendMessage(TextFormat::AQUA . "=== MCPE Analytics ===");
                $sender->sendMessage(TextFormat::WHITE . "Panel: " . $this->getConfig()->get("panel-url"));
                $sender->sendMessage(TextFormat::WHITE . "Buffer: " . $this->buffer->count() . " events");
                $sender->sendMessage(TextFormat::WHITE . "Logs buffer: " . $this->logsTracker->getBufferCount() . " logs");
                $sender->sendMessage(TextFormat::WHITE . "External logs buffer: " . $this->externalLogsBuffer->count() . " logs");
                $sender->sendMessage(TextFormat::WHITE . "Sessions: " . $this->sessionTracker->activeCount());
                $sender->sendMessage(TextFormat::WHITE . "Track chat: " . ($this->getConfig()->get("track-chat") ? "ON" : "OFF"));
                $sender->sendMessage(TextFormat::WHITE . "Track blocks: " . ($this->getConfig()->get("track-blocks") ? "ON" : "OFF"));
                break;

            case "flush":
                $count = $this->buffer->count() + $this->logsTracker->getBufferCount() + $this->externalLogsBuffer->count();
                $this->flushBuffer();
                $this->logsTracker->flush();
                $this->flushExternalLogs();
                $sender->sendMessage(TextFormat::GREEN . "Flushed " . $count . " events.");
                break;

            case "stats":
                $sender->sendMessage(TextFormat::AQUA . "=== Server Stats ===");
                $sender->sendMessage(TextFormat::WHITE . "Online: " . count($this->getServer()->getOnlinePlayers()));
                foreach ($this->getServer()->getOnlinePlayers() as $p) {
                    $uuid = $p->getUniqueId()->toString();
                    $time = $this->sessionTracker->getSessionDuration($uuid);
                    $sender->sendMessage(TextFormat::GRAY . "  " . $p->getName() . ": " . round($time / 60000, 1) . "m");
                }
                break;

            default:
                $sender->sendMessage(TextFormat::RED . "Usage: /analytics <status|flush|stats>");
        }

        return true;
    }

    public function getListener(): EventListener {
        return $this->listener;
    }

    public function getApiClient(): ApiClient {
        return $this->apiClient;
    }

    public function getBuffer(): EventBuffer {
        return $this->buffer;
    }

    public function getExternalLogsBuffer(): EventBuffer {
        return $this->externalLogsBuffer;
    }

    public function getSessionTracker(): SessionTracker {
        return $this->sessionTracker;
    }

    public function isTrackingEnabled(string $type): bool {
        return (bool)$this->getConfig()->get("track-" . $type, true);
    }

    public function isDebug(): bool {
        return (bool)$this->getConfig()->get("debug", false);
    }

    private function flushBuffer(): void {
        $events = $this->buffer->drain();
        if (empty($events)) return;
        if ($this->isDebug()) {
            $this->getLogger()->info("Flushing " . count($events) . " events");
        }
        $this->apiClient->sendBatch($events);
    }

    private function flushExternalLogs(): void {
        $logs = $this->externalLogsBuffer->drain();
        if (empty($logs)) return;
        if ($this->isDebug()) {
            $this->getLogger()->info("Flushing " . count($logs) . " external API logs");
        }
        $this->apiClient->sendLogsAsync($logs);
    }
}
