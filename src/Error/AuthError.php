<?php

namespace IMEdge\SnmpEngine\Error;

use IMEdge\SnmpPacket\Error\SnmpAuthenticationException;
use IMEdge\SnmpPacket\Pdu\Report;
use IMEdge\SnmpPacket\Usm\UsmStats;

class AuthError
{
    public static function forReport(Report $report): SnmpAuthenticationException
    {
        $varBinds = $report->varBinds;
        if ($varBinds->hasOid(UsmStats::WRONG_DIGESTS)) {
            return new SnmpAuthenticationException('Peer reported failed authentication (wrong digest)');
        } elseif ($varBinds->hasOid(UsmStats::UNKNOWN_USER_NAMES)) {
            return new SnmpAuthenticationException('Peer reported unknown username');
        } elseif ($varBinds->hasOid(UsmStats::DECRYPTION_ERRORS)) {
            return new SnmpAuthenticationException('Peer reported decryption error');
        } elseif ($varBinds->hasOid(UsmStats::NOT_IN_TIME_WINDOWS)) {
            return new SnmpNotInTimeWindowAuthenticationException(
                'Peer rejected our request, we are not in its time window'
            );
        } else {
            return new SnmpAuthenticationException('Unknown authentication error');
        }
    }
}
