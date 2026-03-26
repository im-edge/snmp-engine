<?php

namespace IMEdge\Tests\SnmpEngine;

use IMEdge\SnmpEngine\EngineId;
use IMEdge\SnmpEngine\EngineIdFormat;
use IMEdge\SnmpEngine\EngineIdStandard;
use PHPUnit\Framework\TestCase;

class EngineIdTest extends TestCase
{
    public function testLegacyHpEngineId(): void
    {
        /** @var string $binary */
        $binary = hex2bin('0000000B000070106F30B300');
        $id = EngineId::parse($binary);
        $this->assertEquals(EngineIdStandard::RFC_1910, $id->standard);
        $this->assertEquals(11, $id->enterpriseID);
        $this->assertEquals(hex2bin('000070106f30b300'), $id->value);
        $this->assertEquals($binary, $id->toBinary());
    }

    public function testEmptyMikrotikEngineId(): void
    {
        /** @var string $binary */
        $binary = hex2bin('80003A8C04');
        $id = EngineId::parse($binary);
        $this->assertEquals(EngineIdFormat::TEXT, $id->format);
        $this->assertEquals(EngineIdStandard::RFC_3411, $id->standard);
        $this->assertEquals(14988, $id->enterpriseID);
        $this->assertEquals('', $id->value);
        $this->assertEquals($binary, $id->toBinary());
    }

    public function testMikrotikEngineId(): void
    {
        /** @var string $binary */
        $binary = hex2bin('80003A8C043830303033613863303431313131');
        $id = EngineId::parse($binary);
        $this->assertEquals(EngineIdFormat::TEXT, $id->format);
        $this->assertEquals(EngineIdStandard::RFC_3411, $id->standard);
        $this->assertEquals(14988, $id->enterpriseID);
        $this->assertEquals('80003a8c041111', $id->value);
        $this->assertEquals($binary, $id->toBinary());
    }

    public function testAnotherMikrotikEngineId(): void
    {
        /** @var string $binary */
        $binary = hex2bin('80003A8C04763263');
        $id = EngineId::parse($binary);
        $this->assertEquals(EngineIdFormat::TEXT, $id->format);
        $this->assertEquals(EngineIdStandard::RFC_3411, $id->standard);
        $this->assertEquals(14988, $id->enterpriseID);
        $this->assertEquals('v2c', $id->value);
        $this->assertEquals($binary, $id->toBinary());
    }

    public function testFortinetEngineId(): void
    {
        /** @var string $binary */
        $binary = hex2bin('800030440446473648304654423233393036333539');
        $id = EngineId::parse($binary);
        $this->assertEquals(EngineIdFormat::TEXT, $id->format);
        $this->assertEquals(EngineIdStandard::RFC_3411, $id->standard);
        $this->assertEquals(12356, $id->enterpriseID);
        $this->assertEquals('FG6H0FTB23906359', $id->value);
        $this->assertEquals($binary, $id->toBinary());
    }

    public function disabledTestArista(): void
    {
        /** @var string $binary */
        $binary = hex2bin('800007DB03360102101100');
        $id = EngineId::parse($binary);
        $this->assertEquals(EngineIdFormat::MAC_ADDRESS, $id->format);
        $this->assertEquals(EngineIdStandard::RFC_3411, $id->standard);
        $this->assertEquals(2011, $id->enterpriseID);
        $this->assertEquals('36:01:02:10:11:00', $id->getReadableValue());
        $this->assertEquals($binary, $id->toBinary());
    }
    public function testAha(): void
    {
        /** @var string $binary */
        $binary = hex2bin('F5717F28993A20283700');
        // Others: F5717F7483EF9E885400, f5717f001c730436d700, f5717fc0d68297b93200
        $id = EngineId::parse($binary);
        $this->assertEquals(EngineIdFormat::MAC_ADDRESS, $id->format);
        $this->assertEquals(EngineIdStandard::RFC_3411, $id->standard);
        $this->assertEquals(2011, $id->enterpriseID);
        $this->assertEquals('36:01:02:10:11:00', $id->getReadableValue());
        $this->assertEquals($binary, $id->toBinary());
    }

    public function testIpv4(): void
    {
        /** @var string $binary */
        $binary = hex2bin('8000cd54010a2b01eb');
        $id = EngineId::parse($binary);
        $this->assertEquals(EngineIdFormat::IPV4_ADDRESS, $id->format);
        $this->assertEquals(EngineIdStandard::RFC_3411, $id->standard);
        $this->assertEquals(52564, $id->enterpriseID); // TODO: Change
        $this->assertEquals('10.43.1.235', $id->getReadableValue());
        $this->assertEquals($binary, $id->toBinary());
    }
}
