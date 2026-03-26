<?php

namespace IMEdge\SnmpEngine\Dispatcher;

class RandomRequestIdGenerator implements RequestIdGenerator
{
    /** @var RequestIdConsumer[] */
    protected array $consumers = [];

    public function registerConsumer(RequestIdConsumer $consumer): void
    {
        $this->consumers[spl_object_id($consumer)] = $consumer;
    }

    public function getNextId(): int
    {
        while (true) {
            $id = rand(RequestId::MIN_ID, RequestId::MAX_ID);
            foreach ($this->consumers as $consumer) {
                if ($consumer->hasId($id)) {
                    continue 2;
                }
            }

            break;
        }

        return $id;
    }
}
