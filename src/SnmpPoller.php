<?php

namespace IMEdge\SnmpEngine;

use Amp\Socket\InternetAddress;
use IMEdge\SnmpEngine\Application\OidHelper;
use IMEdge\SnmpEngine\Dispatcher\SnmpDispatcher;
use IMEdge\SnmpEngine\Error\SnmpTimeoutError;
use IMEdge\SnmpEngine\Result\SnmpTablesResult;
use IMEdge\SnmpEngine\Usm\ClientContext;
use IMEdge\SnmpPacket\Error\SnmpAuthenticationException;
use IMEdge\SnmpPacket\Error\SnmpError;
use IMEdge\SnmpPacket\Message\VarBind;
use IMEdge\SnmpPacket\Pdu\GetBulkRequest;
use IMEdge\SnmpPacket\Pdu\GetNextRequest;
use IMEdge\SnmpPacket\Pdu\GetRequest;
use IMEdge\SnmpPacket\Pdu\Pdu;
use OutOfBoundsException;

class SnmpPoller
{
    /** @var array<string, ClientContext> */
    protected array $clients = [];
    public function __construct(
        protected SnmpDispatcher $dispatcher,
    ) {
    }

    /**
     * @param array<string, ?string> $oids
     * @throws SnmpAuthenticationException|SnmpTimeoutError
     */
    public function get(string $clientId, array $oids, int $timeout = 5, int $attempts = 3): Pdu
    {
        return $this->dispatcher->sendRequest(
            $this->requireClient($clientId),
            new GetRequest(OidHelper::oidsToVarBindList($oids)),
            $timeout,
            $attempts
        );
    }

    /**
     * @param array<string, ?string> $oids
     * @throws SnmpAuthenticationException|SnmpTimeoutError
     */
    public function getNext(string $clientId, array $oids, int $timeout = 5, int $attempts = 3): Pdu
    {
        return $this->dispatcher->sendRequest(
            $this->requireClient($clientId),
            new GetNextRequest(OidHelper::oidsToVarBindList($oids)),
            $timeout,
            $attempts
        );
    }

    /**
     * @param array<string, ?string> $oids
     * @throws SnmpAuthenticationException|SnmpTimeoutError
     */
    public function getBulk(
        string $clientId,
        array $oids,
        int $maxRepetitions = 10,
        int $nonRepeaters = 0,
        int $timeout = 5,
        int $attempts = 3
    ): Pdu {
        $request = new GetBulkRequest(OidHelper::oidsToVarBindList($oids), null, $maxRepetitions, $nonRepeaters);
        return $this->dispatcher->sendRequest(
            $this->requireClient($clientId),
            $request,
            $timeout,
            $attempts
        );
    }

    /**
     * @param array<string, ?string> $oids
     * @throws SnmpAuthenticationException|SnmpTimeoutError
     */
    public function set(string $clientId, array $oids, int $timeout = 5, int $attempts = 3): Pdu
    {
        return $this->dispatcher->sendRequest(
            $this->requireClient($clientId),
            new GetRequest(OidHelper::oidsToVarBindList($oids)),
            $timeout,
            $attempts
        );
    }

    /**
     * @param array<string, ?string> $oids
     * @throws SnmpAuthenticationException
     * @return array<string, VarBind|VarBind[]|null>
     */
    public function getTable(
        string $clientId,
        array $oids,
        int $maxRepetitions = 10,
        int $nonRepeaters = 0
    ): array {
        $tables = new SnmpTablesResult($oids, $nonRepeaters, $maxRepetitions);
        $fetch = $tables->getCurrentBase();
        $requests = 0;
        $maxRequests = 10_000;
        while (! empty($fetch)) {
            $fetch = $tables->appendResults(
                $this->getBulk($clientId, $fetch, $tables->getMaxRepetitions(), $tables->getNonRepeaters()),
            );
            $requests++;
            if ($requests === $maxRequests) {
                throw new SnmpError("Reached $maxRequests requests, aborting table fetch");
            }
        }

        return $tables->tables;
    }

    protected function requireClient(string $clientId): ClientContext
    {
        return $this->clients[$clientId] ?? throw new OutOfBoundsException("Unknown client reference: $clientId");
    }

    /**
     * @throws SnmpAuthenticationException
     */
    public function registerClient(string $id, InternetAddress $address, SnmpCredential $credential): void
    {
        if (isset($this->clients[$id])) {
            if (($this->clients[$id]->address == $address) && ($this->clients[$id]->credential == $credential)) {
                return;
            }
        }
        $this->clients[$id] = new ClientContext($address, $credential);
    }

    public function forgetClient(string $id): void
    {
        unset($this->clients[$id]);
    }
}
