<?php

namespace IMEdge\SnmpEngine\Timeout;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

class TimeoutHandler implements TimeoutSlotHandler
{
    protected TimeoutTimer $timer;
    /** @var array<int, int[]> */
    protected array $timeoutSlots = [];
    /** @var array<int, int> */
    protected array $idTimeoutSlot = [];

    public function __construct(
        protected Closure $onTimeout,
        protected ?LoggerInterface $logger = null
    ) {
        $this->timer = new TimeoutTimer($this);
    }

    public function schedule(int $id, int $timeout): void
    {
        $slot = $this->timer->schedule($timeout);
        $this->timeoutSlots[$slot] ??= [];
        $this->timeoutSlots[$slot][$id] = $id;
        $this->idTimeoutSlot[$id] = $slot;
    }

    public function forget(int $id): void
    {
        unset($this->timeoutSlots[$this->idTimeoutSlot[$id] ?? null][$id]);
        unset($this->idTimeoutSlot[$id]);
    }

    /**
     * @internal
     */
    public function triggerTimeoutSlot(int $slot): void
    {
        $onTimeout = $this->onTimeout;
        foreach ($this->timeoutSlots[$slot] ?? [] as $id) {
            try {
                $onTimeout($id);
            } catch (Throwable $e) {
                $this->logger?->error('TimeoutHandler calling $onTimeout failed: ' . $e->getMessage());
            }
        }
        unset($this->timeoutSlots[$slot]);
    }
}
