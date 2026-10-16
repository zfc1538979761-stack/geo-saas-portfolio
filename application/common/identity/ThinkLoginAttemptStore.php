<?php

declare(strict_types=1);

namespace app\common\identity;

use think\Config;
use think\Db;

final class ThinkLoginAttemptStore implements LoginAttemptStore
{
    public function reserveAttempt(
        string $key,
        int $now,
        int $maximumFailures,
        int $windowSeconds,
        int $lockoutSeconds
    ): bool
    {
        Db::startTrans();
        try {
            Db::execute(
                'INSERT IGNORE INTO `' . $this->table() . '`'
                . ' (`throttle_key`,`failures`,`window_started_at`,`blocked_until`,`expires_at`,`updated_at`)'
                . ' VALUES (:throttle_key,0,:window_started_at,0,:expires_at,:updated_at)',
                [
                    'throttle_key' => $key,
                    'window_started_at' => $now,
                    'expires_at' => $now + $windowSeconds + $lockoutSeconds,
                    'updated_at' => $now,
                ]
            );
            $row = Db::table($this->table())
                ->where('throttle_key', $key)
                ->lock(true)
                ->find();
            if (!$row) {
                throw new \RuntimeException('Login throttle state could not be locked.');
            }
            if ((int) $row['window_started_at'] + $windowSeconds <= $now) {
                $row['failures'] = 0;
                $row['window_started_at'] = $now;
                $row['blocked_until'] = 0;
            }
            if ((int) $row['blocked_until'] > $now) {
                Db::commit();
                return false;
            }
            if ((int) $row['failures'] >= $maximumFailures) {
                Db::table($this->table())
                    ->where('throttle_key', $key)
                    ->update([
                        'blocked_until' => $now + $lockoutSeconds,
                        'expires_at' => $now + $windowSeconds + $lockoutSeconds,
                        'updated_at' => $now,
                    ]);
                Db::commit();
                return false;
            }
            $row['failures'] = (int) $row['failures'] + 1;
            Db::table($this->table())
                ->where('throttle_key', $key)
                ->update([
                    'failures' => (int) $row['failures'],
                    'window_started_at' => (int) $row['window_started_at'],
                    'blocked_until' => (int) $row['blocked_until'],
                    'expires_at' => $now + $windowSeconds + $lockoutSeconds,
                    'updated_at' => $now,
                ]);
            Db::commit();
            return true;
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    public function delete(string $key): void
    {
        Db::table($this->table())->where('throttle_key', $key)->delete();
    }

    private function table(): string
    {
        return (string) Config::get('database.prefix') . 'geo_auth_throttles';
    }
}
