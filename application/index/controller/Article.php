<?php

declare(strict_types=1);
namespace app\index\controller;
use think\Response;
final class Article extends T06ContentPageController { public function index(): Response { return $this->render('article'); } }
