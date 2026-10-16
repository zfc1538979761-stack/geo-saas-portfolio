<?php

declare(strict_types=1);

namespace app\index\controller;

use think\Request;
use think\Response;

final class Keyword
{
    public function index(): Response
    {
        if (strtoupper(Request::instance()->method()) !== 'GET') {
            return Response::create('Method Not Allowed', 'html', 405);
        }
        $path = APP_PATH . 'index' . DIRECTORY_SEPARATOR . 'view'
            . DIRECTORY_SEPARATOR . 'keyword' . DIRECTORY_SEPARATOR . 'index.html';
        $html = file_get_contents($path);
        if (!is_string($html)) {
            return Response::create('Not Found', 'html', 404);
        }
        return Response::create($html, 'html', 200)->header([
            'Content-Security-Policy' =>
                "default-src 'self'; script-src 'self'; style-src 'self';"
                . " img-src 'self' data:; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ]);
    }
}
