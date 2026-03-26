<?php

namespace IMEdge\SnmpEngine;

use IMEdge\SnmpPacket\Error\SnmpAuthenticationException;
use IMEdge\SnmpPacket\SnmpSecurityLevel;
use IMEdge\SnmpPacket\SnmpVersion;
use IMEdge\SnmpPacket\Usm\SnmpAuthProtocol;
use IMEdge\SnmpPacket\Usm\SnmpPrivProtocol;

class SnmpCredential
{
    public function __construct(
        public readonly SnmpVersion $version,
        public readonly string $securityName, // SNMPv1/2c community string, v3 user
        public readonly ?SnmpSecurityLevel $securityLevel = null,
        public readonly ?SnmpAuthProtocol $authProtocol = null,
        public readonly ?string $authKey = null,
        public readonly ?SnmpPrivProtocol $privProtocol = null,
        public readonly ?string $privKey = null,
    ) {
    }

    public function getSecurityLevel(): SnmpSecurityLevel
    {
        return $this->securityLevel
            ?? throw new SnmpAuthenticationException('Credential w/o security level is not valid');
    }
}
