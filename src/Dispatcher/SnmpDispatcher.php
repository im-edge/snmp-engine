<?php

namespace IMEdge\SnmpEngine\Dispatcher;

use Amp\Socket\InternetAddress;
use Amp\Socket\InternetAddressVersion;
use Amp\Socket\ResourceUdpSocket;
use Exception;
use IMEdge\SnmpEngine\Error\AuthError;
use IMEdge\SnmpEngine\Error\SnmpNotInTimeWindowAuthenticationException;
use IMEdge\SnmpEngine\Error\SnmpTimeoutError;
use IMEdge\SnmpEngine\MessageProcessing\MessageProcessingSubsystem;
use IMEdge\SnmpEngine\MessageProcessing\MessageProcessingV1;
use IMEdge\SnmpEngine\MessageProcessing\MessageProcessingV2c;
use IMEdge\SnmpEngine\MessageProcessing\MessageProcessingV3;
use IMEdge\SnmpEngine\SnmpApplication;
use IMEdge\SnmpEngine\SnmpCounters;
use IMEdge\SnmpEngine\Usm\ClientContext;
use IMEdge\SnmpPacket\Error\SnmpParseError;
use IMEdge\SnmpPacket\Message\SnmpMessage;
use IMEdge\SnmpPacket\Pdu\Pdu;
use IMEdge\SnmpPacket\Pdu\Report;
use IMEdge\SnmpPacket\Pdu\Response;
use IMEdge\SnmpPacket\SnmpVersion;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;

use function Amp\Socket\bindUdpSocket;

class SnmpDispatcher
{
    protected SnmpCounters $counters;
    /** @var SnmpApplication[] */
    protected array $applications = [];
    protected OutgoingRequestHandler $outgoingRequests;
    private ?MessageProcessingV1 $mpV1 = null;
    private ?MessageProcessingV2c $mpV2c = null;
    private ?MessageProcessingV3 $mpV3 = null;
    protected ?ResourceUdpSocket $socket = null;
    protected ?ResourceUdpSocket $socket6 = null;
    // public ?SnmpPacketTrace $trace = null;
    /** @var array<int, true> */
    protected array $retryingTimeWindow = [];

    public function __construct(
        protected LoggerInterface $logger = new NullLogger(),
        protected InternetAddress $socketAddress = new InternetAddress('0.0.0.0', 0),
        protected InternetAddress $socketAddress6 = new InternetAddress('::', 0),
    ) {
        $this->counters = new SnmpCounters();
        $this->outgoingRequests = new OutgoingRequestHandler();
    }

    public function sendRequest(ClientContext $client, Pdu $pdu, int $timeout, int $attempts): Pdu
    {
        $mps = $this->getProcessor($client->credential->version);
        try {
            $deferred = $this->outgoingRequests->schedulePdu($pdu);
            $message = $mps->prepareOutgoingMessage($pdu, $client);
            $this->sendMessage($message, $client->address);

            $result = $deferred->getFuture()->await();
            if ($result instanceof Report) {
                throw AuthError::forReport($result);
            }
        } catch (SnmpNotInTimeWindowAuthenticationException $e) {
            if (isset($this->retryingTimeWindow[$pdu->requestId])) {
                unset($this->retryingTimeWindow[$pdu->requestId]);
                throw $e;
            }
            $pdu->requestId = null;
            $deferred = $this->outgoingRequests->schedulePdu($pdu);
            /** @var int $requestId schedulePdu set's a requestId, but phpstan doesn't get it?! */
            $requestId = $pdu->requestId;
            $this->retryingTimeWindow[$requestId] = true;
            $message = $mps->prepareOutgoingMessage($pdu, $client);
            try {
                $this->sendMessage($message, $client->address);
                $result = $deferred->getFuture()->await();
            } finally {
                unset($this->retryingTimeWindow[$requestId]);
            }
        } catch (SnmpTimeoutError $e) {
            if ($attempts === 1) {
                throw $e;
            }
            return $this->sendRequest($client, $pdu, $timeout, $attempts - 1);
        }

        return $result;
    }

    /**
     * Used by v3 MPS for intermediate requests
     *
     * @internal
     */
    public function sendMessageForRequest(ClientContext $client, SnmpMessage $message): Pdu
    {
        $deferred = $this->outgoingRequests->schedulePdu($message->getPdu());
        $this->sendMessage($message, $client->address);
        return $deferred->getFuture()->await();
    }

    /**
     * @internal
     */
    public function triggerFailureForRequest(int $requestId, Throwable $e): void
    {
        if ($deferred = $this->outgoingRequests->complete($requestId)) {
            $deferred->error($e);
        }
    }

    protected function sendMessage(SnmpMessage $message, InternetAddress $address): void
    {
        // $this->trace?->append($message, PacketDirection::OUTGOING, $address);
        $this->socket($address->getVersion())->send($address, $message->toBinary());
    }

    protected function receiveIncomingMessage(string $rawMessage, InternetAddress $peer): void
    {
        try {
            $message = SnmpMessage::fromBinary($rawMessage);
            $this->counters->receivedMessages++;
            $mps = $this->getProcessor($message->getVersion());
            $pdu = $mps->processIncomingMessage($message, $peer);
            if ($pdu instanceof Response) {
                $this->handleIncomingResponse($pdu);
            } elseif ($pdu instanceof Report) {
                $this->handleIncomingReport($pdu);
            }
            // TODO: Others -> not yet
        } catch (SnmpParseError) {
            // TODO: Log?
            $this->counters->snmpInASNParseErrs++;
            // $this->counters->snmpInBadVersions++; ??
        }
    }

    protected function handleIncomingResponse(Response $pdu): void
    {
        if ($deferred = $this->outgoingRequests->complete($pdu->requestId)) {
            $deferred->complete($pdu);
        }
    }

    protected function handleIncomingReport(Report $pdu): void
    {
        if ($deferred = $this->outgoingRequests->complete($pdu->requestId)) {
            $deferred->complete($pdu);
        }
    }

    protected function getProcessor(?SnmpVersion $version): MessageProcessingSubsystem
    {
        return match ($version) {
            SnmpVersion::v3  => $this->v3Processor(),
            SnmpVersion::v2c => $this->v2cProcessor(),
            SnmpVersion::v1  => $this->v1Processor(),
            null => throw new RuntimeException('Got no SNMP version when trying find suitable processing subsystem')
        };
    }

    protected function v1Processor(): MessageProcessingSubsystem
    {
        return $this->mpV1 ??= new MessageProcessingV1();
    }

    protected function v2cProcessor(): MessageProcessingSubsystem
    {
        return $this->mpV2c ??= new MessageProcessingV2c();
    }

    protected function v3Processor(): MessageProcessingSubsystem
    {
        return $this->mpV3 ??= new MessageProcessingV3($this, $this->logger);
    }

    protected function socket(InternetAddressVersion $ipVersion): ResourceUdpSocket
    {
        switch ($ipVersion) {
            case InternetAddressVersion::IPv4:
                if ($this->socket === null) {
                    $this->socket = bindUdpSocket($this->socketAddress);
                    EventLoop::queue($this->keepReadingFromSocket(...));
                }

                return $this->socket;
            case InternetAddressVersion::IPv6:
                if ($this->socket6 === null) {
                    $this->socket6 = bindUdpSocket($this->socketAddress6);
                    EventLoop::queue($this->keepReadingFromSocket6(...));
                }

                return $this->socket6;
        }
    }

    protected function keepReadingFromSocket(): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('Cannot register socket handlers w/o socket');
        }
        try {
            while ($received = $this->socket->receive()) {
                [$address, $data] = $received;
                $this->receiveIncomingMessage($data, $address);
            }
            $this->socket = null;
            $this->outgoingRequests->rejectAll(new Exception('Socket has been closed'));
        } catch (Throwable $error) {
            $this->outgoingRequests->rejectAll($error);
            if ($this->socket !== null) {
                $this->socket->close();
                $this->socket = null;
            }
        }
    }

    protected function keepReadingFromSocket6(): void
    {
        if ($this->socket6 === null) {
            throw new RuntimeException('Cannot register socket6 handlers w/o socket');
        }
        try {
            while ($received = $this->socket6->receive()) {
                [$address, $data] = $received;
                $this->receiveIncomingMessage($data, $address);
            }
            $this->socket = null;
            $this->outgoingRequests->rejectAll(new Exception('Socket6 has been closed'));
        } catch (Throwable $error) {
            $this->outgoingRequests->rejectAll($error);
            if ($this->socket6 !== null) {
                $this->socket6->close();
                $this->socket6 = null;
            }
        }
    }
}
