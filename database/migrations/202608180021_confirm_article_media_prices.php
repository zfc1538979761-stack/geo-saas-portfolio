<?php

declare(strict_types=1);

use app\common\migration\Migration;

// User-confirmed MVP price rule: /media/type/3 article self-media prices are
// denominated in yuan and marketPrice is the orderable catalog price.
return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $media = $prefix . 'media_resources';
        $channels = $prefix . 'channels';
        if (!$this->tableExists($pdo, $media) || !$this->tableExists($pdo, $channels)) {
            return;
        }

        $pdo->exec(
            'UPDATE ' . $this->quote($media) . ' mr'
            . ' JOIN ' . $this->quote($channels) . ' c ON c.`id` = mr.`channel_id`'
            . ' SET mr.`cost_price` = CAST(JSON_UNQUOTE(JSON_EXTRACT(mr.`attributes_json`, \'$.market_price\')) AS DECIMAL(14,6)),'
            . ' mr.`cost_currency` = \'CNY\','
            . ' mr.`attributes_json` = JSON_SET('
            . ' mr.`attributes_json`,'
            . ' \'$.price_unconfirmed\', false,'
            . ' \'$.price_source\', \'user_confirmed_marketPrice\','
            . ' \'$.price_unit\', \'CNY_元\''
            . ' ),'
            . ' mr.`updated_at` = NOW()'
            . ' WHERE c.`code` = \'influencer\''
            . ' AND JSON_UNQUOTE(JSON_EXTRACT(mr.`attributes_json`, \'$.source_catalog\')) = \'/media/type/3\''
            . ' AND JSON_UNQUOTE(JSON_EXTRACT(mr.`attributes_json`, \'$.market_price\')) IS NOT NULL'
            . ' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(mr.`attributes_json`, \'$.market_price\')) AS DECIMAL(14,6)) > 0'
        );
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $media = $prefix . 'media_resources';
        $channels = $prefix . 'channels';
        if (!$this->tableExists($pdo, $media) || !$this->tableExists($pdo, $channels)) {
            return;
        }

        $pdo->exec(
            'UPDATE ' . $this->quote($media) . ' mr'
            . ' JOIN ' . $this->quote($channels) . ' c ON c.`id` = mr.`channel_id`'
            . ' SET mr.`cost_price` = 0.000000,'
            . ' mr.`cost_currency` = \'CNY\','
            . ' mr.`attributes_json` = JSON_REMOVE('
            . ' JSON_SET(mr.`attributes_json`, \'$.price_unconfirmed\', true),'
            . ' \'$.price_source\', \'$.price_unit\''
            . ' ),'
            . ' mr.`updated_at` = NOW()'
            . ' WHERE c.`code` = \'influencer\''
            . ' AND JSON_UNQUOTE(JSON_EXTRACT(mr.`attributes_json`, \'$.source_catalog\')) = \'/media/type/3\''
            . ' AND JSON_UNQUOTE(JSON_EXTRACT(mr.`attributes_json`, \'$.market_price\')) IS NOT NULL'
            . ' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(mr.`attributes_json`, \'$.market_price\')) AS DECIMAL(14,6)) > 0'
        );
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('Migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
