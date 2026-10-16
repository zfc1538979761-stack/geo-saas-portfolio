<?php

declare(strict_types=1);

use think\Env;

$storageDriver = getenv('GEO_CONTENT_STORAGE_DRIVER');

return [
    'storage' => [
        'portfolio_root' => dirname(__DIR__, 2) . '/runtime/portfolio-storage',
        'driver' => strtolower(trim((string) (
            $storageDriver === false ? Env::get('content.storage_driver', '') : $storageDriver
        ))),
    ],
    'files' => [
        'knowledge_types' => ['text', 'image', 'document'],
        'extensions' => [
            'jpg' => ['type' => 'image', 'mime' => 'image/jpeg', 'max_bytes' => 10 * 1024 * 1024],
            'jpeg' => ['type' => 'image', 'mime' => 'image/jpeg', 'max_bytes' => 10 * 1024 * 1024],
            'png' => ['type' => 'image', 'mime' => 'image/png', 'max_bytes' => 10 * 1024 * 1024],
            'webp' => ['type' => 'image', 'mime' => 'image/webp', 'max_bytes' => 10 * 1024 * 1024],
            'pdf' => ['type' => 'document', 'mime' => 'application/pdf', 'max_bytes' => 20 * 1024 * 1024],
            'docx' => [
                'type' => 'document',
                'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'max_bytes' => 20 * 1024 * 1024,
            ],
        ],
        'maximum_original_name_length' => 120,
    ],
    'text' => [
        'title_max_characters' => 191,
        'knowledge_max_characters' => 1000000,
        'prompt_max_characters' => 200000,
        'article_max_characters' => 1000000,
        'search_max_characters' => 200,
    ],
    'pagination' => [
        'default_per_page' => 20,
        'maximum_per_page' => 100,
        'maximum_page' => 100000,
    ],
];
