<?php

namespace IMEdge\SnmpEngine\MessageProcessing;

use Amp\Socket\InternetAddress;
use IMEdge\SnmpEngine\Usm\ClientContext;
use IMEdge\SnmpPacket\Error\SnmpAuthenticationException;
use IMEdge\SnmpPacket\Message\SnmpMessage;
use IMEdge\SnmpPacket\Message\SnmpV1Message;
use IMEdge\SnmpPacket\Pdu\Pdu;
use RuntimeException;

class MessageProcessingV1 implements MessageProcessingSubsystem
{
    public function processIncomingMessage(SnmpMessage $message, InternetAddress $peer): ?Pdu
    {
        if (! $message instanceof SnmpV1Message) {
            throw new RuntimeException('v1 MPS got a non-v1 message, this is a bug');
        }
        return $message->getPdu();
    }

    /**
     * @throws SnmpAuthenticationException
     */
    public function prepareOutgoingMessage(Pdu $pdu, ClientContext $client): SnmpMessage
    {
        // client->credential->version === SnmpVersion::v1?
        return new SnmpV1Message(
            $client->credential->securityName
            ?? throw new SnmpAuthenticationException('Credential w/o security name is not valid'),
            $pdu
        );
    }
}
