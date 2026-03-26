<?php

namespace IMEdge\SnmpEngine\Application;

use IMEdge\SnmpPacket\Message\VarBind;
use IMEdge\SnmpPacket\Message\VarBindList;

class OidHelper
{
    /**
     * @param array<string, ?string> $oids
     */
    public static function oidsToVarBindList(array $oids): VarBindList
    {
        return new VarBindList(self::oidsToVarBindsForRequest($oids));
    }

    /**
     * @param array<string, ?string> $oids
     * @return VarBind[]
     */
    public static function oidsToVarBindsForRequest(array $oids): array
    {
        $varBinds = [];
        $i = 0;
        foreach (array_keys($oids) as $oid) {
            $i++;
            $varBinds[$i] = new VarBind($oid);
        }

        return $varBinds;
    }
}
