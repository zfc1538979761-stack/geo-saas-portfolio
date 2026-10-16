<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\content\T06ContentServiceFactory;
use think\Request;
use think\Response;

final class Prompt extends T06ApiController
{
    public function index(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'GET');return T06ContentServiceFactory::prompts()->page((array)$r->get(),$this->context($r));}); }
    public function detail(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'GET');$q=$this->strictInput((array)$r->get(),['id']);return T06ContentServiceFactory::prompts()->version($this->integer($q['id']??null),$this->context($r),(string)$r->ip(0,false));}); }
    public function history(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'GET');$q=$this->strictInput((array)$r->get(),['logical_key']);return T06ContentServiceFactory::prompts()->versions((string)($q['logical_key']??''),$this->context($r),(string)$r->ip(0,false));}); }
    public function create(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'POST');return T06ContentServiceFactory::prompts()->create($this->strictInput((array)$r->post(),['name','scene','content']),$this->context($r),(string)$r->ip(0,false));}); }
    public function version(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'POST');$p=$this->strictInput((array)$r->post(),['logical_key','name','scene','content']);return T06ContentServiceFactory::prompts()->createVersion((string)($p['logical_key']??''),['name'=>$p['name']??null,'scene'=>$p['scene']??null,'content'=>$p['content']??null],$this->context($r),(string)$r->ip(0,false));}); }
    public function status(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'POST');$p=$this->strictInput((array)$r->post(),['logical_key','status']);return T06ContentServiceFactory::prompts()->setStatus((string)($p['logical_key']??''),(string)($p['status']??''),$this->context($r),(string)$r->ip(0,false));}); }
    public function delete(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'POST');$p=$this->strictInput((array)$r->post(),['logical_key']);T06ContentServiceFactory::prompts()->delete((string)($p['logical_key']??''),$this->context($r),(string)$r->ip(0,false));return ['deleted'=>true];}); }
}
