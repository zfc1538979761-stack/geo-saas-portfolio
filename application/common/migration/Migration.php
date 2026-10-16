<?php

declare(strict_types=1);

namespace app\common\migration;

use PDO;

interface Migration
{
    public function up(PDO $pdo, string $prefix): void;

    public function down(PDO $pdo, string $prefix): void;
}
