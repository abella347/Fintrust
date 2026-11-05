<?php

declare(strict_types=1);

namespace App\Helpers;

final class Cache
{
    public static function remember(string $key, int $ttlSeconds, callable $compute, bool $refresh = false): mixed
    {
        $file = base_path('storage/cache/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json');

        if (!$refresh && is_file($file) && (time() - filemtime($file)) < $ttlSeconds) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && array_key_exists('value', $data)) {
                return $data['value'];
            }
        }

        $value = $compute();
        // Write to a temp file then rename: readers never see a half-written file.
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode(['value' => $value])) !== false) {
            @rename($tmp, $file);
        }
        return $value;
    }

    public static function forget(string $key): void
    {
        @unlink(base_path('storage/cache/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json'));
    }
}
