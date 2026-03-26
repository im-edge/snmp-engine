<?php

namespace IMEdge\SnmpEngine;

use IMEdge\SnmpPacket\Message\SnmpMessage;

interface SnmpApplication
{
    public function process(SnmpMessage $message): void;
}
