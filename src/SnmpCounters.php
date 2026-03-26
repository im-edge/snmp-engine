<?php

namespace IMEdge\SnmpEngine;

class SnmpCounters
{
    public int $snmpInASNParseErrs = 0;
    public int $snmpInBadVersions = 0;
    public int $receivedMessages = 0;
    public int $receivedInvalidPackets = 0;
}
