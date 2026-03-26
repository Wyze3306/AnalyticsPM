<?php

declare(strict_types=1);

namespace mcpe\analytics;

class SessionTracker {

    /** @var array<string, int> UUID => join timestamp (ms) */
    private array $sessions = [];

    public function startSession(string $uuid): void {
        $this->sessions[$uuid] = (int)(microtime(true) * 1000);
    }

    /**
     * Ends a session and returns the playtime in milliseconds.
     */
    public function endSession(string $uuid): int {
        if (!isset($this->sessions[$uuid])) return 0;

        $joinTime = $this->sessions[$uuid];
        unset($this->sessions[$uuid]);

        return (int)(microtime(true) * 1000) - $joinTime;
    }

    /**
     * Returns current session duration in milliseconds without ending it.
     */
    public function getSessionDuration(string $uuid): int {
        if (!isset($this->sessions[$uuid])) return 0;
        return (int)(microtime(true) * 1000) - $this->sessions[$uuid];
    }

    public function activeCount(): int {
        return count($this->sessions);
    }
}
