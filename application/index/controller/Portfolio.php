<?php
namespace app\index\controller;
final class Portfolio {
    public function published(): \think\Response {
        return \think\Response::create('<!doctype html><meta charset="utf-8"><title>Portfolio 模拟发布</title><h1>模拟发布展示页</h1><p>这是本地 Portfolio 演示页面，不是真实媒体发布或 AI 收录证明。</p><a href="/index/enterprise/index">返回工作台</a>','html')->header(['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
