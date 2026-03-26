<?php

namespace IMEdge\SnmpEngine\Result;

use IMEdge\SnmpPacket\Error\SnmpError;
use IMEdge\SnmpPacket\ErrorStatus;
use IMEdge\SnmpPacket\Pdu\Pdu;

class SnmpTablesResult
{
    public CombinedResult $result;

    /** @var array<string, ?string> */
    protected array $fetchNext;
    /** @var array<string, ?string> */
    protected array $pendingRequestedOids;

    public function __construct(
        /** @var array<string, ?string> */
        protected array $requestedOids,
        protected int $nonRepeaters,
        protected int $maxRepetitions,
    ) {
        $this->result = new CombinedResult();
        $this->fetchNext = $this->pendingRequestedOids = $this->requestedOids;
    }

    public function getNonRepeaters(): int
    {
        return $this->nonRepeaters;
    }

    public function getMaxRepetitions(): int
    {
        return $this->maxRepetitions;
    }

    public function appendResults(Pdu $pdu): void
    {
        switch ($pdu->errorStatus) {
            case ErrorStatus::NO_ERROR:
                break;
            case ErrorStatus::TOO_BIG:
                $this->shrinkMaxRepetitions();
                return;
            default:
                throw new \RuntimeException('Nix da');
        }

                // TODO : check for tooBig...
        $results = NormalizedBulkResult::fromVarBinds($pdu->varBinds, $this->pendingRequestedOids, $this->nonRepeaters);
        if ($this->nonRepeaters > 0) {
            $this->result->appendNonRepeaters($results, $this->nonRepeaters);
            for ($i = 0; $i < $this->nonRepeaters; $i++) {
                array_shift($this->pendingRequestedOids);
            }
            $this->nonRepeaters = 0;
        }

        [$fetch, $completedRepeaters] = $this->result->appendRepeaters(
            $results,
            $this->pendingRequestedOids,
            $this->maxRepetitions
        );
        foreach ($completedRepeaters as $oid) {
            unset($fetch[$oid]);
            unset($this->pendingRequestedOids[$oid]);
        }

        $this->result->cntResults++;
        $this->fetchNext = $fetch;
    }

    /**
     * @return array<string, ?string>
     */
    public function getNextOidsToFetch(): array
    {
        return $this->fetchNext;
    }

    public function wantsMore(): bool
    {
        return ! empty($this->fetchNext);
    }

    protected function shrinkMaxRepetitions(): void
    {
        if ($this->maxRepetitions === 1) {
            throw new SnmpError('maxRepetitions is 1, packet is still tooBig');
        }
        $this->maxRepetitions = (int) max(1, min(
            floor($this->maxRepetitions * 0.8),
            $this->maxRepetitions - 1
        ));
    }
}
