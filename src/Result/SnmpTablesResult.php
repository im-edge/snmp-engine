<?php

namespace IMEdge\SnmpEngine\Result;

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
}
