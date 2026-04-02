<?php

namespace IMEdge\SnmpEngine\Dispatcher;

final class RequestId
{
    public const MIN_ID = 1;
    public const MAX_ID = 2 ** 31 - 1; // Hint: RFC3416 says 214783647, but the errata corrects this
}
