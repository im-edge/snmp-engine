<?php

namespace IMEdge\SnmpEngine\MessageProcessing;

use Amp\Socket\InternetAddress;
use IMEdge\SnmpEngine\Usm\ClientContext;
use IMEdge\SnmpPacket\Error\SnmpAuthenticationException;
use IMEdge\SnmpPacket\Message\SnmpMessage;
use IMEdge\SnmpPacket\Message\SnmpV2Message;
use IMEdge\SnmpPacket\Pdu\Pdu;
use RuntimeException;

class MessageProcessingV2c implements MessageProcessingSubsystem
{
    public function processIncomingMessage(SnmpMessage $message, InternetAddress $peer): ?Pdu
    {
        if (! $message instanceof SnmpV2Message) {
            throw new RuntimeException('v2c MPS got a non-v2c message, this is a bug');
        }
        return $message->getPdu();
    }

    /**
     * @throws SnmpAuthenticationException
     */
    public function prepareOutgoingMessage(Pdu $pdu, ClientContext $client): SnmpMessage
    {
        // client->credential->version === SnmpVersion::v2c?

        return new SnmpV2Message(
            $client->credential->securityName
            ?? throw new SnmpAuthenticationException('Credential w/o security name is not valid'),
            $pdu
        );
    }
}
