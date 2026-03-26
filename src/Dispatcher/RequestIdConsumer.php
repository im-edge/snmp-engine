<?php

namespace IMEdge\SnmpEngine\Dispatcher;

interface RequestIdConsumer
{
    public function hasId(int $id): bool;
}
