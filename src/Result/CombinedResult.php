<?php

namespace IMEdge\SnmpEngine\Result;

use IMEdge\SnmpPacket\Message\VarBind;
use JsonSerializable;
use stdClass;

class CombinedResult implements JsonSerializable
{
    public int $cntResults = 0;
    public int $cntVarBinds = 0;
    /** @var array<string, VarBind> */
    public array $nonRepeaters = [];

    /** @var array<string, array<string, VarBind>> */
    public array $repeaters = [];

    public function appendNonRepeaters(NormalizedBulkResult $result, int $nonRepeaters): void
    {
        $indexedVarBinds = $result->nonRepeaters;
        if ($nonRepeaters > 0) {
            for ($i = 1; $i <= $nonRepeaters; $i++) {
                $key = (string) array_key_first($indexedVarBinds);
                /** @var VarBind $value */
                $value = array_shift($indexedVarBinds);
                $this->nonRepeaters[$key] = $value;
                $this->cntVarBinds++;
            }
        }
    }

    /**
     * @param array<string, ?string> $requestedOids
     * @return array{0: array<string, ?string>, 1: string[]}
     */
    public function appendRepeaters(NormalizedBulkResult $results, array $requestedOids, int $maxRepetitions): array
    {
        $fetch = [];
        $repeaters = $results->repeaters;
        $completedRepeaters = [];
        foreach ($requestedOids as $oid => $alias) {
            $key = $alias ?? $oid;
            $oidRepeaters = $repeaters[$key] ?? [];
            if (isset($this->repeaters[$key])) {
                foreach ($oidRepeaters as $k => $v) {
                    $this->repeaters[$key][$k] = $v;
                    $this->cntVarBinds++;
                }
            } else {
                $this->repeaters[$key] = $oidRepeaters;
                $this->cntVarBinds += count($oidRepeaters);
            }

            if (count($oidRepeaters) < $maxRepetitions) {
                $completedRepeaters[] = $oid;
            } else {
                $fetch[$oidRepeaters[array_key_last($oidRepeaters)]->oid] = $alias;
            }
        }

        return [$fetch, $completedRepeaters];
    }

    public function jsonSerialize(): stdClass
    {
        return (object) [
            'counters'     => (object) [
                'results'  => $this->cntResults,
                'varBinds' => $this->cntVarBinds,
            ],
            'nonRepeaters' => $this->nonRepeaters,
            'repeaters'    => $this->repeaters,
        ];
    }
}
