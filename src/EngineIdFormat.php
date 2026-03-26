<?php

namespace IMEdge\SnmpEngine;

enum EngineIdFormat: int
{
    case IPV4_ADDRESS = 1;
    case IPV6_ADDRESS = 2;
    case MAC_ADDRESS = 3;
    case TEXT = 4;
    case CUSTOM = 5; // OCTET?
}
