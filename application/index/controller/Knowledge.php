<?php

declare(strict_types=1);
namespace app\index\controller;
use think\Response;
final class Knowledge extends T06ContentPageController { public function index(): Response { return $this->render('knowledge'); } }
