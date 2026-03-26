<?php

namespace IMEdge\SnmpEngine\MessageProcessing;

use Amp\Socket\InternetAddress;
use IMEdge\SnmpEngine\Usm\ClientContext;
use IMEdge\SnmpPacket\Message\SnmpMessage;
use IMEdge\SnmpPacket\Pdu\Pdu;

interface MessageProcessingSubsystem
{
    public function processIncomingMessage(SnmpMessage $message, InternetAddress $peer): ?Pdu;
    public function prepareOutgoingMessage(Pdu $pdu, ClientContext $client): SnmpMessage;
}
