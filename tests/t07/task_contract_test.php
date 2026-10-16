<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

final class T07TaskContractFailure extends RuntimeException {}

function t07TaskContractAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new T07TaskContractFailure($message); }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

$classes = [
    'app\\common\\ai\\task\\AiTaskDispatcher',
    'app\\common\\ai\\task\\ThinkQueueAiTaskDispatcher',
    'app\\common\\ai\\task\\AiGenerationService',
    'app\\common\\ai\\task\\AiGenerationRunner',
    'app\\common\\ai\\task\\AiArticleWriter',
    'app\\common\\ai\\task\\AiPromptAssembler',
    'app\\common\\ai\\task\\AiTaskException',
    'app\\common\\job\\AiArticleGenerationJob',
];

try {
    foreach ($classes as $class) {
        t07TaskContractAssert(class_exists($class) || interface_exists($class), 'T07 task class exists: ' . $class);
    }
    $dispatcher = new ReflectionClass('app\\common\\ai\\task\\AiTaskDispatcher');
    t07TaskContractAssert($dispatcher->isInterface(), 'AI task dispatcher is an interface');
    t07TaskContractAssert($dispatcher->hasMethod('dispatch'), 'AI task dispatcher has dispatch contract');
    foreach (['create', 'detail', 'result', 'retry', 'cancel'] as $method) {
        t07TaskContractAssert(method_exists('app\\common\\ai\\task\\AiGenerationService', $method), 'generation service exposes ' . $method);
    }
    t07TaskContractAssert(method_exists('app\\common\\ai\\task\\AiGenerationRunner', 'run'), 'generation runner exposes run');
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . get_class($exception) . ' file=' . basename($exception->getFile())
        . ' line=' . $exception->getLine() . ' message=' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "RESULT: T07 task contracts passed\n");
