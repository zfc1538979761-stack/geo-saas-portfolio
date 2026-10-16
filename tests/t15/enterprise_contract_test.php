<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$relativeFiles = [
    'application/common/controller/EnterpriseApi.php',
    'application/common/supplier/ChannelAvailabilityService.php',
    'application/api/controller/Media.php',
    'application/api/controller/Order.php',
    'application/api/controller/Wallet.php',
    'application/index/controller/Enterprise.php',
    'application/index/controller/Order.php',
    'application/index/view/enterprise/index.html',
    'public/assets/css/enterprise.css',
    'public/assets/js/frontend/enterprise-order-polling.js',
    'public/assets/js/frontend/enterprise.js',
];
$failure = null;
$sources = [];
foreach ($relativeFiles as $relative) {
    $path = $root . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) {
        $failure = 'T15 required file is missing: ' . $relative;
        break;
    }
    $sources[$relative] = (string) file_get_contents($path);
}

if ($failure === null) {
    foreach (['Media.php', 'Order.php', 'Wallet.php'] as $file) {
        $key = 'application/api/controller/' . $file;
        if (strpos($sources[$key], 'extends EnterpriseApi') === false) {
            $failure = 'T15 ' . $file . ' must use the enterprise API base';
            break;
        }
        if (strpos($sources[$key], '$this->auth') !== false) {
            $failure = 'T15 ' . $file . ' must not rely on legacy member auth';
            break;
        }
    }
}

if ($failure === null) {
    $apiBase = $sources['application/common/controller/EnterpriseApi.php'];
    foreach (['TenantContextFactory', 'bearerToken', '登录状态无效或已过期'] as $required) {
        if (strpos($apiBase, $required) === false) {
            $failure = 'EnterpriseApi missing ' . $required;
            break;
        }
    }
    $channel = $sources['application/common/supplier/ChannelAvailabilityService.php'];
    foreach (['supplier_confirmed', 'unavailableMediaIds', 'channels'] as $required) {
        if (strpos($channel, $required) === false) {
            $failure = 'ChannelAvailabilityService missing ' . $required;
            break;
        }
    }
}

if ($failure === null) {
    $html = $sources['application/index/view/enterprise/index.html'];
    foreach (['GEO 企业工作台', '登录', '企业资料', '关键词/问题', '知识素材', 'AI 生成任务', '媒体筛选', '订单', '余额/流水', '通知'] as $label) {
        if (strpos($html, $label) === false) {
            $failure = 'enterprise page missing label: ' . $label;
            break;
        }
    }
    if (preg_match('/<script(?![^>]*\bsrc=)/i', $html) === 1 || stripos($html, '<style') !== false) {
        $failure = 'enterprise page must keep scripts and styles external for CSP';
    }
    if (strpos($html, 'placeholder="原文链接（可选）"') === false) {
        $failure = 'enterprise order form must default article link to optional';
    }
    if (strpos($html, '<th>媒体</th>') === false) {
        $failure = 'enterprise order list must include the media name column';
    }
    if (strpos($html, '/assets/js/frontend/enterprise-order-polling.js') === false) {
        $failure = 'enterprise order page must load the silent polling policy before enterprise.js';
    }
}

if ($failure === null) {
    $js = $sources['public/assets/js/frontend/enterprise.js'];
    foreach ([
        '/api/auth/login',
        '/api/company/profile',
        '/api/keyword/index',
        '/api/knowledge/index',
        '/api/prompt/index',
        '/api/article/index',
        '/api/ai_task/create',
        '/api/ai_task/detail',
        '/api/media/index',
        '/api/order/preview',
        '/api/order/create',
        '/api/order/detail?id=',
        '/api/order/notifications',
        '/api/wallet/balance',
    ] as $required) {
        if (strpos($js, $required) === false) {
            $failure = 'enterprise.js missing endpoint: ' . $required;
            break;
        }
    }
    foreach (['innerHTML', 'outerHTML', 'eval(', 'cost_price', 'supplier_raw_status', 'supplier_cost'] as $forbidden) {
        if (strpos($js, $forbidden) !== false) {
            $failure = 'enterprise.js uses unsafe/private pattern: ' . $forbidden;
            break;
        }
    }
    foreach (['hashchange', 'location.hash', "'Bearer '"] as $required) {
        if (strpos($js, $required) === false) {
            $failure = 'enterprise.js missing state/security contract: ' . $required;
            break;
        }
    }
    foreach (['selectedRequiresVideoLink', '视频链接（必填，可使用网盘分享链接）', '原文链接（可选）'] as $required) {
        if (strpos($js, $required) === false) {
            $failure = 'enterprise.js missing channel-specific material rule: ' . $required;
            break;
        }
    }
    if (strpos($js, 'selectedNeedsOriginalLink') !== false) {
        $failure = 'enterprise.js must not require one link rule for every channel';
    }
    foreach (['orderMediaDisplayName', 'media_display_name', '媒体 ID：'] as $required) {
        if (strpos($js, $required) === false) {
            $failure = 'enterprise.js missing order media display contract: ' . $required;
            break;
        }
    }
    if (strpos($js, 'cell(row, item.media_id)') !== false) {
        $failure = 'enterprise order detail must not render a bare internal media id';
    }

    $polling = $sources['public/assets/js/frontend/enterprise-order-polling.js'];
    foreach (['isStalled', 'shouldPoll', 'signature', 'hasChanged'] as $required) {
        if (strpos($polling, $required) === false) {
            $failure = 'enterprise order polling policy missing: ' . $required;
            break;
        }
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "PASS: T15 enterprise page, API base, channel availability, and security contracts\n");
