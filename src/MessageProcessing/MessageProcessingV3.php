<?php

namespace IMEdge\SnmpEngine\MessageProcessing;

use Amp\Socket\InternetAddress;
use Exception;
use IMEdge\SnmpEngine\Dispatcher\SnmpDispatcher;
use IMEdge\SnmpEngine\Usm\ClientContext;
use IMEdge\SnmpPacket\Error\SnmpAuthenticationException;
use IMEdge\SnmpPacket\Error\SnmpError;
use IMEdge\SnmpPacket\Message\SnmpMessage;
use IMEdge\SnmpPacket\Message\SnmpV3Header;
use IMEdge\SnmpPacket\Message\SnmpV3Message;
use IMEdge\SnmpPacket\Message\SnmpV3ScopedPdu;
use IMEdge\SnmpPacket\Pdu\GetRequest;
use IMEdge\SnmpPacket\Pdu\Pdu;
use IMEdge\SnmpPacket\Pdu\Report;
use IMEdge\SnmpPacket\SnmpSecurityLevel;
use IMEdge\SnmpPacket\SnmpVersion;
use IMEdge\SnmpPacket\Usm\UserBasedSecurityModel;
use IMEdge\SnmpPacket\Usm\UsmStats;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MessageProcessingV3 implements MessageProcessingSubsystem
{
    /** @var array<int, ClientContext> */
    protected array $pendingMessages = [];
    protected IncrementingMessageIdGenerator $messageIdGenerator;

    public function __construct(
        protected SnmpDispatcher $dispatcher,
        protected LoggerInterface $logger
    ) {
        $this->messageIdGenerator = new IncrementingMessageIdGenerator();
    }

    public function processIncomingMessage(SnmpMessage $message, InternetAddress $peer): ?Pdu
    {
        if (! $message instanceof SnmpV3Message) {
            throw new RuntimeException('v3 MPS got a non-v3 message, this is a bug');
        }

        $id = $message->header->messageId;
        $clientContext = $this->pendingMessages[$id] ?? null;
        if ($clientContext === null) {
            $this->logger->notice('No client is waiting for a message from ' . $peer);
            return null;
        }
        if ($clientContext->address->toString() !== $peer->toString()) {
            $this->logger->warning(sprintf(
                "Peer address %s doesn't match the expected one (%s)\n",
                $peer,
                $clientContext->address ?? 'none'
            ));
            return null;
        }
        unset($this->pendingMessages[$id]);

        try {
            if ($check = $this->handleIncomingMessageForClient($clientContext, $message)) {
                if ($check instanceof Pdu) {
                    return $check;
                }

                return $message->getPdu();
            }
        } catch (SnmpError $e) {
            if ($requestId = $message->scopedPdu->pdu?->requestId ?? null) {
                $this->dispatcher->triggerFailureForRequest($requestId, $e);
            }
        }

        return null;
    }

    /**
     * @throws SnmpAuthenticationException
     */
    public function handleIncomingMessageForClient(ClientContext $client, SnmpV3Message $message): Pdu|bool
    {
        if (! $client->wantsAuthentication()) {
            // echo "Wants no auth\n";
            return $message->getPdu();
        }
        $usm = $message->securityParameters;
        if (! $usm instanceof UserBasedSecurityModel) {
            throw new SnmpAuthenticationException('USM is required');
        }

        if (!$client->hasAuthentication() || !$client->engine->hasId()) {
            $client->refreshFromSecurityParameters($usm);
            return $message->getPdu();
        }

        if (! $client->authenticate($message)) {
            if (($pdu = $message->scopedPdu->pdu) && ($pdu instanceof Report)) {
                throw new SnmpAuthenticationException(
                    UsmStats::getErrorForVarBindList($pdu->varBinds) ?? 'unknown error'
                );
            }
            // NOT AUTH INCOMING, no Report handling
            return false;
        }

        if (!$client->wantsEncryption()) {
            if ($message->scopedPdu->isPlainText()) {
                return $message->getPdu();
            } else {
                return false;
            }
        }
        if (! $client->hasPrivacy()) {
            $client->refreshFromSecurityParameters($usm);
            return true;
        }

        if ($message->scopedPdu->isPlainText()) {
            return false;
        }
        if ($usm->privacyParams === '') {
            return false;
        }
        if ($message->scopedPdu->encryptedPdu === null) {
            return false;
        }
        $client->refreshFromSecurityParameters($usm);

        try {
            return $client->decryptPdu($message->scopedPdu->encryptedPdu, $usm->privacyParams);
        } catch (Exception $e) {
            $this->logger->error('PDU decryption error: ' . $e->getMessage());
            // All kind of errors, Unexpected end of data while decoding long form length
            // Decode error: Length 123 overflows data, 68 bytes left.
            // Decode error: SEQUENCE expected, got primitive CONTEXT SPECIFIC TAG 29
            // Integer overflow
            // echo 'Decode error: ' . $e->getMessage() . "\n";
            // echo "Encrypted:\n" . bin2hex($message->scopedPdu->encryptedPdu) . "\n";
            // echo "Decrypted:\n" . bin2hex($binary) . "\n";

            return false;
        }
    }

    /**
     * @throws SnmpAuthenticationException
     */
    public function prepareOutgoingMessage(Pdu $pdu, ClientContext $client): SnmpMessage
    {
        if ($client->credential->version !== SnmpVersion::v3) {
            throw new RuntimeException('Cannot prepare v3 message w/o v3 credentials');
        }
        if ($client->wantsAuthentication()) {
            if (!$client->engine->hasId()) {
                $this->sendAndWaitForDiscovery($client);
                /** @phpstan-ignore booleanNot.alwaysTrue */ // async operation changes the engine. Does it??
                if (!$client->engine->hasId()) {
                    throw new SnmpAuthenticationException('Failed to retrieve Engine ID');
                }
            }

            if ($client->wantsEncryption() && !$client->hasPrivacy()) {
                $this->sendAndWaitForAuthenticatedDiscovery($client);
            }
        }

        return $client->authenticateOutgoing(new SnmpV3Message(
            new SnmpV3Header(
                messageId: $this->reserveMessageId($client),
                securityFlags: $client->credential->getSecurityLevel(),
                reportableFlag: true,
            ),
            $usm = $client->usm(),
            $this->prepareScopedPdu($client, $pdu, $usm->privacyParams)
        ));
    }

    protected function sendAndWaitForDiscovery(ClientContext $client): Pdu
    {
        $message = new SnmpV3Message(
            new SnmpV3Header(
                messageId: $this->reserveMessageId($client),
                securityFlags: SnmpSecurityLevel::NO_AUTH_NO_PRIV,
                reportableFlag: true,
            ),
            new UserBasedSecurityModel(),
            SnmpV3ScopedPdu::forPdu(new GetRequest())
        );
        return $this->dispatcher->sendMessageForRequest($client, $message);
    }

    protected function sendAndWaitForAuthenticatedDiscovery(ClientContext $client): Pdu
    {
        $message = $client->authenticateOutgoing(new SnmpV3Message(
            new SnmpV3Header(
                messageId: $this->reserveMessageId($client),
                securityFlags: SnmpSecurityLevel::AUTH_NO_PRIV,
                reportableFlag: true,
            ),
            $usm = $client->usm(),
            $this->prepareScopedPdu($client, new GetRequest(), $usm->privacyParams)
        ));

        return $this->dispatcher->sendMessageForRequest($client, $message);
    }

    protected function prepareScopedPdu(ClientContext $client, Pdu $pdu, string $salt): SnmpV3ScopedPdu
    {
        if ($client->hasPrivacy()) {
            return $client->encryptPdu($pdu, $salt);
        }

        return SnmpV3ScopedPdu::forPdu($pdu, '', '');
    }

    protected function reserveMessageId(ClientContext $context): int
    {
        $id = $this->messageIdGenerator->getNextId();
        $this->pendingMessages[$id] = $context;

        return $id;
    }
}
