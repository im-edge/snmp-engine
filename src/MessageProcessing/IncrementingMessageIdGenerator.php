<?php

namespace IMEdge\SnmpEngine\MessageProcessing;

use IMEdge\SnmpEngine\Dispatcher\RequestIdGenerator;

use function rand;

class IncrementingMessageIdGenerator implements RequestIdGenerator
{
    protected int $lastId;

    public function __construct()
    {
        $this->lastId = rand(MessageId::MIN_ID, MessageId::MAX_ID);
    }

    public function getNextId(): int
    {
        $this->lastId++;
        if ($this->lastId > MessageId::MAX_ID) {
            $this->lastId = MessageId::MIN_ID;
        }

        return $this->lastId;
    }
}
