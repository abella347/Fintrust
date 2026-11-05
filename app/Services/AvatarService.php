<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/** Profile pictures. */
final class AvatarService
{
    private const MAX_BYTES = 2 * 1024 * 1024;
    private const SIZE = 512;
    private const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    public static function dir(): string
    {
        return base_path('storage/avatars');
    }

    /** Saves a new picture and returns its file name (used as a cache-busting version). */
    public function save(int $userId, mixed $dataUrl): string
    {
        if (!is_string($dataUrl) || preg_match('#^data:image/(?:jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m) !== 1) {
            throw $this->invalid('Choose a JPEG, PNG or WebP image.');
        }
        $bytes = base64_decode($m[1], true);
        if ($bytes === false || $bytes === '') {
            throw $this->invalid('That image could not be read.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw $this->invalid('The image is too large (2 MB maximum).');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset(self::TYPES[$info[2]]) || $info[0] < 32 || $info[1] < 32 || $info[0] > 8000 || $info[1] > 8000) {
            throw $this->invalid('That file is not a supported image (JPEG, PNG or WebP, at least 32×32 pixels).');
        }

        [$data, $ext] = $this->normalise($bytes, $info);

        if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0775, true) && !is_dir(self::dir())) {
            throw new HttpException(500, 'Could not save the picture (storage/avatars is not writable).', [], 'STORAGE_ERROR');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (@file_put_contents(self::dir() . '/' . $name, $data, LOCK_EX) === false) {
            throw new HttpException(500, 'Could not save the picture (storage/avatars is not writable).', [], 'STORAGE_ERROR');
        }

        $old = $this->current($userId);
        Database::connection()->prepare('UPDATE users SET avatar_path = ? WHERE id = ?')->execute([$name, $userId]);
        $this->deleteFile($old);
        AuditLog::record($userId, 'user', 'profile.avatar_updated', 'user', $userId);
        return $name;
    }

    public function remove(int $userId): void
    {
        $old = $this->current($userId);
        Database::connection()->prepare('UPDATE users SET avatar_path = NULL WHERE id = ?')->execute([$userId]);
        $this->deleteFile($old);
        AuditLog::record($userId, 'user', 'profile.avatar_removed', 'user', $userId);
    }

    /** Sends the user's own picture (404 if none). */
    public function stream(int $userId): never
    {
        $name = $this->current($userId);
        $file = $name !== null ? self::dir() . '/' . $name : null;
        if ($file === null || !is_file($file)) {
            throw new HttpException(404, 'No profile picture.', [], 'NOT_FOUND');
        }
        $type = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($name, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'");
        header('Cache-Control: private, max-age=31536000, immutable'); // The URL changes when the picture does
        readfile($file);
        exit;
    }

    public function current(int $userId): ?string
    {
        $s = Database::connection()->prepare('SELECT avatar_path FROM users WHERE id = ?');
        $s->execute([$userId]);
        $name = $s->fetchColumn();
        return is_string($name) && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $name) === 1 ? $name : null;
    }

    /** Re-encode with GD when available (strips metadata); otherwise keep the validated bytes. */
    private function normalise(string $bytes, array $info): array
    {
        if (!function_exists('imagecreatefromstring')) {
            return [$bytes, self::TYPES[$info[2]]];      // GD missing: still a verified image type
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw $this->invalid('That image could not be read.');
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);                               // Centre square crop
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // Transparent PNGs get a white background
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);
        ob_start();
        imagejpeg($dst, null, 86);
        $out = (string) ob_get_clean();
        return [$out, 'jpg'];
    }

    private function deleteFile(?string $name): void
    {
        if ($name !== null && is_file(self::dir() . '/' . $name)) {
            @unlink(self::dir() . '/' . $name);
        }
    }

    private function invalid(string $message): HttpException
    {
        return new HttpException(422, $message, ['avatar' => $message], 'VALIDATION_FAILED');
    }
}
