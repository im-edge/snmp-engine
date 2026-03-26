<?php

namespace IMEdge\SnmpEngine\Result;

use IMEdge\SnmpPacket\Message\VarBind;
use IMEdge\SnmpPacket\Message\VarBindList;
use IMEdge\SnmpPacket\Pdu\Pdu;
use IMEdge\SnmpPacket\VarBindValue\ContextSpecific;

class SnmpTablesResult
{
    public array $tables = [];
    protected array $base;
    protected int $appendedResults = 0;

    public function __construct(
        protected array $oids,
        protected int $nonRepeaters,
        protected int $maxRepetitions,
    ) {
        $this->base = $oids;
    }

    public function getNonRepeaters(): int
    {
        return $this->nonRepeaters;
    }

    public function getMaxRepetitions(): int
    {
        return $this->maxRepetitions;
    }

    public function appendResults(Pdu $pdu): array
    {
        // TODO : check for tooBig...
        $results = self::normalizeBulkResult($pdu->varBinds, $this->base, $this->nonRepeaters);
        $fetch = [];
        if ($this->nonRepeaters > 0) {
            for ($i = 1; $i <= $this->nonRepeaters; $i++) {
                $this->tables[array_key_first($results)] = array_shift($results);
                array_shift($this->base);
            }
            $this->nonRepeaters = 0;
        }
        $done = [];
        foreach ($this->base as $oid => $alias) {
            $key = $alias ?? $oid;
            assert(is_array($results[$key])); // sure?
            if (isset($this->tables[$key])) {
                assert(is_array($this->tables[$key])); // sure?
                foreach ($results[$key] as $k => $v) {
                    $this->tables[$key][$k] = $v;
                }
            } else {
                $this->tables[$key] = $results[$key];
            }
            if (count($results[$key]) < $this->maxRepetitions) {
                $done[] = $oid;
            } else {
                $fetch[$results[$key][array_key_last($results[$key])]->oid] = $alias;
            }
        }

        foreach ($done as $oid) {
            unset($fetch[$oid]);
            unset($this->base[$oid]);
        }

        $this->appendedResults++;

        return $fetch;
    }

    public function getCurrentBase(): array
    {
        return $this->base;
    }

    /**
     * @param array<string, ?string>|null $baseOids
     * @return array
     */
    public static function normalizeBulkResult(VarBindList $varBinds, array $baseOids, int $nonRepeaters): array
    {
        $results = [];
        $varBinds = $varBinds->varBinds;
        $repeaters = $baseOids;
        for ($i = 1; $i <= $nonRepeaters; $i++) {
            if ($varBind = array_shift($varBinds)) {
                $name = array_shift($repeaters);
                $results[$name ?? $varBind->oid] = $varBind;
            } else {
                throw new \ValueError('Response does not contain all requested non-repeaters');
            }
        }
        $repeaters = array_keys($repeaters);
        $i = 0;
        /** @var VarBind $varBind */
        while ($varBind = array_shift($varBinds)) {
            $prefix = $repeaters[$i];
            $name = $baseOids[$prefix] ?? $prefix;
            if ($varBind->value instanceof ContextSpecific) {
                $results[$name] ??= [];
            } elseif (str_starts_with($varBind->oid, $prefix)) {
                assert(is_array($results[$name])); // TODO: check this
                $results[$name][substr($varBind->oid, strlen($prefix) + 1)] = $varBind;
            } else { // Hint: skipping all others
                $results[$name] ??= [];
            }
            $i++;
            if ($i === count($repeaters)) {
                $i = 0;
            }
        }

        return $results;
    }

}
