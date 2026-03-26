<?php

namespace IMEdge\SnmpEngine\Dispatcher;

class IncrementingRequestIdGenerator implements RequestIdGenerator
{
    protected int $lastId;

    public function __construct()
    {
        $this->lastId = rand(RequestId::MIN_ID, RequestId::MAX_ID);
    }

    public function getNextId(): int
    {
        $this->lastId++;
        if ($this->lastId > RequestId::MAX_ID) {
            $this->lastId = RequestId::MIN_ID;
        }

        return $this->lastId;
    }
}
