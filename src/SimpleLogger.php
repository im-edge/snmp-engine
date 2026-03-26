<?php

namespace IMEdge\SnmpEngine;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

class SimpleLogger extends AbstractLogger
{
    public function log($level, Stringable|string $message, array $context = []): void
    {
        if (! is_scalar($level)) {
            throw new RuntimeException('Log level is ' . get_debug_type($level));
        }
        // Interpolate context
        $message = (string)$message;
        foreach ($context as $key => $value) {
            $message = str_replace('{' . $key . '}', $this->encode($value), $message);
        }

        // Simple output
        fwrite(STDOUT, sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $level, $message));
    }

    private function encode(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
