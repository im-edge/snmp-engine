<?php

namespace IMEdge\SnmpEngine\Timeout;

interface TimeoutSlotHandler
{
    public function triggerTimeoutSlot(int $slot): void;
}
