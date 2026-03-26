<?php

declare(strict_types=1);

namespace mcpe\analytics;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;

class ApiClient {

    private string $panelUrl;
    private string $apiKey;
    private Main $plugin;

    public function __construct(string $panelUrl, string $apiKey, Main $plugin) {
        $this->panelUrl = rtrim($panelUrl, "/");
        $this->apiKey = $apiKey;
        $this->plugin = $plugin;
    }

    public function sendJoin(string $uuid, string $username, string $platform, int $timestamp, string $ip): void {
        $this->sendAsync("/api/ingest/join", [
            "uuid" => $uuid,
            "username" => $username,
            "platform" => $platform,
            "timestamp" => $timestamp,
            "ip" => $ip,
        ]);
    }

    public function sendLeave(string $uuid, int $playtime): void {
        $this->sendAsync("/api/ingest/leave", [
            "uuid" => $uuid,
            "timestamp" => (int)(microtime(true) * 1000),
            "playtime" => $playtime,
        ]);
    }

    public function sendWorldChange(string $uuid, string $world, int $timestamp): void {
        $this->sendAsync("/api/ingest/world", [
            "uuid" => $uuid,
            "world" => $world,
            "timestamp" => $timestamp,
        ]);
    }

    public function sendBatch(array $events): void {
        if (empty($events)) return;

        // Split into chunks of 100
        $chunks = array_chunk($events, 100);
        foreach ($chunks as $chunk) {
            $this->sendAsync("/api/ingest/batch", [
                "events" => $chunk,
            ]);
        }
    }

    private function sendAsync(string $endpoint, array $data): void {
        $url = $this->panelUrl . $endpoint;
        $json = json_encode($data);
        $apiKey = $this->apiKey;
        $debug = $this->plugin->isDebug();

        Server::getInstance()->getAsyncPool()->submitTask(
            new class($url, $json, $apiKey, $debug) extends AsyncTask {
                private string $url;
                private string $json;
                private string $apiKey;
                private bool $debug;

                public function __construct(string $url, string $json, string $apiKey, bool $debug) {
                    $this->url = $url;
                    $this->json = $json;
                    $this->apiKey = $apiKey;
                    $this->debug = $debug;
                }

                public function onRun(): void {
                    $ch = curl_init($this->url);
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $this->json,
                        CURLOPT_HTTPHEADER => [
                            "Content-Type: application/json",
                            "X-Api-Key: " . $this->apiKey,
                        ],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 10,
                        CURLOPT_CONNECTTIMEOUT => 5,
                    ]);

                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $error = curl_error($ch);
                    curl_close($ch);

                    $this->setResult([
                        "success" => $httpCode >= 200 && $httpCode < 300,
                        "code" => $httpCode,
                        "error" => $error,
                        "response" => $response,
                    ]);
                }

                public function onCompletion(): void {
                    $result = $this->getResult();
                    if (!$result["success"] && $this->debug) {
                        Server::getInstance()->getLogger()->warning(
                            "[MCPEAnalytics] API request failed: " . $this->url .
                            " (HTTP " . $result["code"] . ") " . $result["error"]
                        );
                    }
                }
            }
        );
    }
}
