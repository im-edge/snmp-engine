<?php

namespace IMEdge\SnmpEngine\Dispatcher;

interface RequestIdGenerator
{
    public function getNextId(): int;
}
