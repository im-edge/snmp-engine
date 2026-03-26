<?php

namespace IMEdge\SnmpEngine\Timeout;

use Revolt\EventLoop;

class TimeoutTimer
{
    /** @var array<int, string> */
    protected array $timeoutTimers = [];
    protected int $granularity;
    public function __construct(
        protected TimeoutSlotHandler $handler,
        int $slotsPerSecond = 4,
    ) {
        $this->granularity = (int) round(1_000_000_000 / $slotsPerSecond);
    }

    public function schedule(int $delay): int
    {
        $slot = (int) (hrtime(true) / $this->granularity) + 1;
        $this->timeoutTimers[$slot] ??= EventLoop::delay($delay, fn () => $this->trigger($slot));

        return $slot;
    }

    protected function trigger(int $slot): void
    {
        $this->handler->triggerTimeoutSlot($slot);
        unset($this->timeoutTimers[$slot]);
    }

    public function __destruct()
    {
        unset($this->handler);
        foreach ($this->timeoutTimers as $timer) {
            EventLoop::cancel($timer);
        }
    }
}
