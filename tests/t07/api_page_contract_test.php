<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$required = [
    'application/api/controller/AiTask.php',
    'application/index/view/keyword/index.html',
    'public/assets/js/frontend/keyword-question.js',
];
$failure = null; $sources = [];
foreach ($required as $relative) {
    $path = $root . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) { $failure = 'required T07 API/page file is missing: ' . $relative; break; }
    $sources[$relative] = (string) file_get_contents($path);
}
if ($failure === null) {
    $controller = $sources['application/api/controller/AiTask.php'];
    foreach (['create', 'detail', 'result', 'retry', 'cancel'] as $method) {
        if (strpos($controller, 'function ' . $method . '(') === false) { $failure = 'AI task API misses method: ' . $method; break; }
    }
    foreach (['new PDO', 'Db::', 'TenantDataGateway'] as $forbidden) {
        if (strpos($controller, $forbidden) !== false) { $failure = 'AI task controller bypasses service: ' . $forbidden; break; }
    }
}
if ($failure === null) {
    $javascript = $sources['public/assets/js/frontend/keyword-question.js'];
    foreach (['/api/ai_task/create', '/api/ai_task/detail', '/api/ai_task/result', '/api/ai_task/retry'] as $requiredText) {
        if (strpos($javascript, $requiredText) === false) { $failure = 'generation page misses endpoint: ' . $requiredText; break; }
    }
    foreach (['innerHTML', 'outerHTML', 'localStorage', 'sessionStorage', 'Authorization:'] as $forbidden) {
        if (strpos($javascript, $forbidden) !== false) { $failure = 'generation page uses unsafe token/DOM pattern: ' . $forbidden; break; }
    }
}
if ($failure !== null) { fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL); exit(1); }
fwrite(STDOUT, "PASS: T07 API and generation page contracts passed\n");
