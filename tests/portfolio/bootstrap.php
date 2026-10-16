<?php
declare(strict_types=1);
define('PORTFOLIO_ROOT', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
spl_autoload_register(static function(string $class): void {
    if (strpos($class, 'app\\') === 0) {
        $path = PORTFOLIO_ROOT . 'application/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});
function check(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'PASS: ' . $label . PHP_EOL;
}
function rejects(callable $operation, string $label): void {
    try { $operation(); } catch (Throwable $e) { check(true, $label); return; }
    check(false, $label);
}
