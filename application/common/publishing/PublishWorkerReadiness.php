<?php

declare(strict_types=1);

namespace app\common\publishing;

final class PublishWorkerReadiness
{
    public static function defaultHeartbeatPath(): string
    {
        return ROOT_PATH . 'runtime' . DIRECTORY_SEPARATOR . 'publish-worker-heartbeat.json';
    }

    public static function isReady(string $heartbeatPath, int $maxAgeSeconds = 120, ?int $now = null): bool
    {
        if ($maxAgeSeconds < 1 || !is_file($heartbeatPath)) {
            return false;
        }
        $raw = @file_get_contents($heartbeatPath);
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)
            || ($payload['queue'] ?? '') !== 'geo_publish_submit'
            || ($payload['state'] ?? '') !== 'ready'
            || (int) ($payload['supervisor_pid'] ?? 0) < 1) {
            return false;
        }
        $updatedAt = (int) ($payload['updated_at_epoch'] ?? 0);
        $now = $now ?? time();
        if ($updatedAt <= 0 || $updatedAt > $now || ($now - $updatedAt) > $maxAgeSeconds) {
            return false;
        }
        return self::supervisorLockHeld(self::lockPath($heartbeatPath));
    }

    public static function lockPath(string $heartbeatPath): string
    {
        $extension = pathinfo($heartbeatPath, PATHINFO_EXTENSION);
        if ($extension === '') {
            return $heartbeatPath . '.lock';
        }
        return substr($heartbeatPath, 0, -(strlen($extension) + 1)) . '.lock';
    }

    private static function supervisorLockHeld(string $lockPath): bool
    {
        if (!is_file($lockPath)) {
            return false;
        }
        $handle = @fopen($lockPath, 'c+');
        if (!is_resource($handle)) {
            // Windows FileShare.None prevents opening while the supervisor is alive.
            return true;
        }
        $acquired = @flock($handle, LOCK_EX | LOCK_NB);
        if ($acquired) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return !$acquired;
    }

    private function __construct()
    {
    }
}
