<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$required = [
    'application/common/content/KeywordQuestionService.php',
    'application/common/content/KeywordQuestionServiceFactory.php',
    'application/api/controller/Keyword.php',
    'application/api/controller/Question.php',
    'application/api/controller/ContentApiController.php',
    'application/index/controller/Keyword.php',
    'application/index/view/keyword/index.html',
    'public/assets/js/frontend/keyword-question.js',
    'database/migrations/202608010005_create_keywords_and_questions.php',
    'tests/t05/official_migration_safety_test.php',
];

$failure = null;
$sources = [];
foreach ($required as $relative) {
    $path = $root . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) {
        $failure = 'required T05 file is missing: ' . $relative;
        break;
    }
    $source = file_get_contents($path);
    if (!is_string($source)) {
        $failure = 'required T05 file is unreadable: ' . $relative;
        break;
    }
    $sources[$relative] = $source;
}

if ($failure === null) {
    foreach (['application/api/controller/Keyword.php', 'application/api/controller/Question.php'] as $controller) {
        $source = $sources[$controller];
        foreach (['Db::', 'new PDO', '->insert(', '->update(', '->delete('] as $forbidden) {
            if (strpos($source, $forbidden) !== false) {
                $failure = 'controller bypasses the T05 service: ' . $controller;
                break 2;
            }
        }
        if (strpos($source, 'TenantContextFactory::fromEnterpriseToken') === false) {
            $failure = 'controller does not use the trusted tenant context: ' . $controller;
            break;
        }
        if (substr_count($source, 'strictInput(') < 6) {
            $failure = 'controller does not strictly allowlist every endpoint input: ' . $controller;
            break;
        }
    }
}

if ($failure === null) {
    $baseController = $sources['application/api/controller/ContentApiController.php'];
    if (strpos($baseController, 'catch (ContentAuthenticationException') === false
        || strpos($baseController, 'catch (TenantException') !== false) {
        $failure = 'content API authentication and internal tenant failures are not separated';
    }
}

if ($failure === null) {
    $service = $sources['application/common/content/KeywordQuestionService.php'];
    foreach (['createKeyword', 'updateKeyword', 'keywordPage', 'setKeywordStatus', 'deleteKeyword',
        'batchKeywordStatus', 'createQuestion', 'updateQuestion', 'questionPage', 'setQuestionStatus',
        'deleteQuestion', 'batchQuestionStatus', 'activeQuestions'] as $method) {
        if (strpos($service, 'function ' . $method . '(') === false) {
            $failure = 'T05 service method is missing: ' . $method;
            break;
        }
    }
    foreach (['ai', 'import', 'export', 'doubao', 'openai'] as $forbidden) {
        if (preg_match('/function\s+[A-Za-z0-9_]*' . $forbidden . '[A-Za-z0-9_]*\s*\(/i', $service) === 1) {
            $failure = 'T05 service exposes a deferred capability: ' . $forbidden;
            break;
        }
    }
}

if ($failure === null) {
    $javascript = $sources['public/assets/js/frontend/keyword-question.js'];
    foreach (['innerHTML', 'outerHTML', 'document.write', 'localStorage', 'sessionStorage'] as $forbidden) {
        if (strpos($javascript, $forbidden) !== false) {
            $failure = 'T05 page uses an unsafe DOM or token storage API: ' . $forbidden;
            break;
        }
    }
    if (strpos($javascript, 'textContent') === false) {
        $failure = 'T05 page does not render untrusted text through textContent';
    }
}

if ($failure === null) {
    $migration = $sources['database/migrations/202608010005_create_keywords_and_questions.php'];
    foreach (['@tenant-resource keywords', '@tenant-resource questions', 'ON DELETE RESTRICT'] as $requiredText) {
        if (strpos($migration, $requiredText) === false) {
            $failure = 'T05 migration safety declaration is missing: ' . $requiredText;
            break;
        }
    }
}

if ($failure === null) {
    $official = $sources['tests/t05/official_migration_safety_test.php'];
    foreach (['GEO_T05_OFFICIAL_MIGRATION_TEST_DATABASE', 'GEO_T05_OFFICIAL_MIGRATION_TEST_HOST',
        'GEO_T05_OFFICIAL_MIGRATION_TEST_PORT', '$port === 3306'] as $requiredText) {
        if (strpos($official, $requiredText) === false) {
            $failure = 'official migration test lacks an isolated database identity gate: ' . $requiredText;
            break;
        }
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "PASS: T05 service boundary, trusted tenant context, deferred scope, and safe DOM guards\n");
