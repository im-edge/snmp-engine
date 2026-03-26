<?php

namespace IMEdge\SnmpEngine\Dispatcher;

final class RequestId
{
    public const MIN_ID = 1;
    public const MAX_ID = 2 ** 31 - 1;
}
