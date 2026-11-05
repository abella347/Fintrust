<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

final class Cbor
{
    /** Decodes one item starting at $offset; $offset is advanced past it. */
    public static function decode(string $data, int &$offset = 0): mixed
    {
        if ($offset >= strlen($data)) {
            throw new RuntimeException('CBOR: unexpected end of data');
        }
        $initial = ord($data[$offset++]);
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22 => null,
                default => throw new RuntimeException('CBOR: unsupported simple value'),
            };
        }

        $length = self::length($data, $offset, $info);

        switch ($major) {
            case 0:                                   // Unsigned integer
                return $length;
            case 1:                                   // Negative integer: -1 - n
                return -1 - $length;
            case 2:                                   // Byte string
            case 3:                                   // Text string
                if ($offset + $length > strlen($data)) {
                    throw new RuntimeException('CBOR: string runs past the end');
                }
                $value = substr($data, $offset, $length);
                $offset += $length;
                return $value;
            case 4:                                   // Array
                $items = [];
                for ($i = 0; $i < $length; $i++) {
                    $items[] = self::decode($data, $offset);
                }
                return $items;
            case 5:                                   // Map
                $map = [];
                for ($i = 0; $i < $length; $i++) {
                    $key = self::decode($data, $offset);
                    if (!is_int($key) && !is_string($key)) {
                        throw new RuntimeException('CBOR: unsupported map key');
                    }
                    $map[$key] = self::decode($data, $offset);
                }
                return $map;
        }
        throw new RuntimeException('CBOR: unsupported type');
    }

    private static function length(string $data, int &$offset, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $bytes = match ($info) { 24 => 1, 25 => 2, 26 => 4, 27 => 8, default => 0 };
        if ($bytes === 0 || $offset + $bytes > strlen($data)) {
            throw new RuntimeException('CBOR: unsupported or truncated length');
        }
        $value = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $value = ($value << 8) | ord($data[$offset++]);
        }
        if ($value < 0 || $value > 1 << 24) {          // Nothing in WebAuthn is this large
            throw new RuntimeException('CBOR: length too large');
        }
        return $value;
    }
}
