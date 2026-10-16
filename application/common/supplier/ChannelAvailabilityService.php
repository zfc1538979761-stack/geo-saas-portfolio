<?php

declare(strict_types=1);

namespace app\common\supplier;

use PDO;
use RuntimeException;

/**
 * Read-only channel capability source for the enterprise UI and order API.
 *
 * A channel is orderable only when the backend marks it supplier_confirmed=1.
 * The frontend never infers this from media rows or hard-coded business names.
 */
final class ChannelAvailabilityService
{
    private PDO $pdo;
    private string $prefix;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    public static function create(): self
    {
        \think\Db::query('SELECT 1');
        return new self(
            \think\Db::connect()->getPdo(),
            (string) \think\Db::getConfig('prefix')
        );
    }

    public function channels(): array
    {
        $statement = $this->pdo->query(
            'SELECT `id`,`code`,`name`,`category`,`status`,`supplier_confirmed`'
            . ' FROM ' . $this->q($this->prefix . 'channels')
            . ' WHERE `status`=\'normal\' ORDER BY `id` ASC'
        );
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'category' => (string) $row['category'],
                'status' => (string) $row['status'],
                'supplier_confirmed' => (bool) (int) $row['supplier_confirmed'],
            ];
        }
        return $items;
    }

    public function confirmedChannelIds(): array
    {
        $ids = [];
        foreach ($this->channels() as $channel) {
            if ($channel['supplier_confirmed']) {
                $ids[$channel['id']] = true;
            }
        }
        return $ids;
    }

    public function unavailableMediaIds(array $mediaIds): array
    {
        $mediaIds = array_values(array_unique(array_map('intval', $mediaIds)));
        $mediaIds = array_filter($mediaIds, static fn(int $id): bool => $id > 0);
        if ($mediaIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT m.`id`,m.`status`,m.`attributes_json`,c.`supplier_confirmed`,c.`status` AS channel_status,'
            . ' s.`code` AS supplier_code'
            . ' FROM ' . $this->q($this->prefix . 'media_resources') . ' m'
            . ' LEFT JOIN ' . $this->q($this->prefix . 'channels') . ' c ON c.`id`=m.`channel_id`'
            . ' LEFT JOIN ' . $this->q($this->prefix . 'suppliers') . ' s ON s.`id`=m.`supplier_id`'
            . ' WHERE m.`id` IN (' . $placeholders . ')'
        );
        $statement->execute($mediaIds);
        $seen = [];
        $unavailable = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $seen[$id] = true;
            if (
                $row['status'] !== 'active'
                || (int) ($row['supplier_confirmed'] ?? 0) !== 1
                || ($row['channel_status'] ?? '') !== 'normal'
                || $this->isPriceUnconfirmed($row)
            ) {
                $unavailable[] = $id;
            }
        }
        foreach ($mediaIds as $id) {
            if (!isset($seen[$id])) {
                $unavailable[] = $id;
            }
        }
        return array_values(array_unique($unavailable));
    }

    private function isPriceUnconfirmed(array $row): bool
    {
        $attributes = json_decode((string) ($row['attributes_json'] ?? '{}'), true);
        if (!is_array($attributes)) {
            $attributes = [];
        }
        if (array_key_exists('price_unconfirmed', $attributes)) {
            return (bool) $attributes['price_unconfirmed'];
        }
        return ($row['supplier_code'] ?? '') === 'media_market';
    }

    private function q(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('SQL identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
}
