<?php

namespace IMEdge\SnmpEngine\Result;

use IMEdge\SnmpPacket\Message\VarBind;
use IMEdge\SnmpPacket\Message\VarBindList;
use IMEdge\SnmpPacket\VarBindValue\ContextSpecific;
use JsonSerializable;
use stdClass;
use ValueError;

class NormalizedBulkResult implements JsonSerializable
{
    /** @var array<string, VarBind> */
    public array $nonRepeaters = [];
    /** @var array<string, array<string, VarBind>> */
    public array $repeaters = [];

    /**
     * @param array<string, ?string> $baseOids
     */
    public static function fromVarBinds(VarBindList $varBinds, array $baseOids, int $nonRepeaters): NormalizedBulkResult
    {
        $self = new NormalizedBulkResult();
        $varBinds = $varBinds->varBinds;
        $repeaters = $baseOids;
        for ($i = 1; $i <= $nonRepeaters; $i++) {
            if ($varBind = array_shift($varBinds)) {
                $name = array_shift($repeaters);
                $self->nonRepeaters[$name ?? $varBind->oid] = $varBind;
            } else {
                throw new ValueError('Response does not contain all requested non-repeaters');
            }
        }
        $repeaters = array_keys($repeaters);
        $i = 0;
        /** @var VarBind $varBind */
        while ($varBind = array_shift($varBinds)) {
            $prefix = $repeaters[$i];
            $name = $baseOids[$prefix] ?? $prefix;
            if ($varBind->value instanceof ContextSpecific) {
                $self->repeaters[$name] ??= [];
            } elseif (str_starts_with($varBind->oid, $prefix)) {
                $self->repeaters[$name][substr($varBind->oid, strlen($prefix) + 1)] = $varBind;
            } else { // Hint: skipping all others
                $self->repeaters[$name] ??= [];
            }
            $i++;
            if ($i === count($repeaters)) {
                $i = 0;
            }
        }

        return $self;
    }

    public function jsonSerialize(): stdClass
    {
        return (object) [
            'nonRepeaters' => $this->nonRepeaters,
            'repeaters'    => $this->repeaters,
        ];
    }
}
