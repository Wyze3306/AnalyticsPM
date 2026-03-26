<?php

declare(strict_types=1);

namespace mcpe\analytics;

class EventBuffer {

    /** @var array[] */
    private array $events = [];

    public function add(array $event): void {
        $this->events[] = $event;
    }

    /**
     * Returns all buffered events and clears the buffer.
     * @return array[]
     */
    public function drain(): array {
        $events = $this->events;
        $this->events = [];
        return $events;
    }

    public function count(): int {
        return count($this->events);
    }
}
