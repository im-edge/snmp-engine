<?php

namespace IMEdge\SnmpEngine;

use IMEdge\SnmpEngine\Error\InvalidEngineIdException;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;

use function str_repeat;
use function strlen;
use function substr;

/**
 * SNMP Engine ID according to RFC3411 and RFC1910
 *
 * TODO: Not ready for use, as it rejects non-standard IDs (see disabled test for Arista)
 */
class EngineId
{
    protected function __construct(
        public readonly int $enterpriseID,
        public readonly EngineIdStandard $standard,
        public readonly EngineIdFormat $format,
        public readonly string $value
    ) {
    }

    private static function parseLegacy(string $engineId): EngineId
    {
        return new EngineId(
            self::getFirstUnpackedInt(unpack('N', substr($engineId, 0, 4))),
            EngineIdStandard::RFC_1910,
            EngineIdFormat::CUSTOM,
            substr($engineId, 4)
        );
    }

    public function getReadableValue(): string
    {
        return match ($this->format) {
            EngineIdFormat::IPV4_ADDRESS, EngineIdFormat::IPV6_ADDRESS => self::requireIp($this->value),
            EngineIdFormat::MAC_ADDRESS => substr(chunk_split(strtolower(bin2hex($this->value)), 2, ':'), 0, -1),
            EngineIdFormat::TEXT => $this->value,
            EngineIdFormat::CUSTOM => '0x' . bin2hex($this->value),
        };
    }

    private static function assertValidEngineId(string $engineId): void
    {
        $length = strlen($engineId);
        if ($length < 5) {
            throw new InvalidEngineIdException('EngineID is too short');
        }
        if ($length > 32) {
            throw new InvalidEngineIdException('EngineID is too long');
        }
        if (str_repeat("\x00", $length) === $engineId) {
            throw new InvalidEngineIdException('EngineID composed of a sequence of 0x00 is invalid');
        }
        if (str_repeat("\xff", $length) === $engineId) {
            throw new InvalidEngineIdException('EngineID composed of a sequence of 0xff is invalid');
        }
    }

    private static function isLegacy(string $engineId): bool
    {
        return ($engineId[0] & "\x80") !== "\x80" && strlen($engineId) === 12;
    }

    public static function parse(string $engineId): EngineId
    {
        self::assertValidEngineId($engineId);
        if (self::isLegacy($engineId)) {
            return self::parseLegacy($engineId);
        }

        return new EngineId(
            self::getFirstUnpackedInt(unpack('N', $engineId & "\x7f\xff\xff\xff")),
            EngineIdStandard::RFC_3411,
            EngineIdFormat::from(self::getFirstUnpackedInt(unpack('C', $engineId[4]))),
            substr($engineId, 5)
        );
    }

    public static function fromUuid(UuidInterface $uuid, int $enterpriseNumber = 0): EngineId
    {
        return new EngineId(
            $enterpriseNumber,
            EngineIdStandard::RFC_3411,
            EngineIdFormat::CUSTOM,
            substr($uuid->getBytes(), 0, 27)
        );
    }

    public function toBinary(): string
    {
        if ($this->standard === EngineIdStandard::RFC_1910) {
            return pack('N', $this->enterpriseID) . $this->value;
        }

        return (pack('N', $this->enterpriseID) | "\x80\x00\x00\x00") . pack('C', $this->format->value) . $this->value;
    }

    protected static function requireIp(string $binaryString): string
    {
        $ip = inet_ntop($binaryString);
        if ($ip === false) {
            throw new RuntimeException('Got no IP');
        }

        return $ip;
    }

    /**
     * Method to satisfy static code analysis
     *
     * @param int[]|false $unpacked
     * @return int
     */
    protected static function getFirstUnpackedInt(array|false $unpacked): int
    {
        if ($unpacked === false || ! array_key_exists(1, $unpacked)) {
            throw new RuntimeException('Failed to unpack');
        }

        return $unpacked[1];
    }
}
