<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\content\T06ContentServiceFactory;
use think\Request;
use think\Response;

final class Article extends T06ApiController
{
    public function index(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'GET');return T06ContentServiceFactory::articles()->page((array)$r->get(),$this->context($r));}); }
    public function detail(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'GET');$q=$this->strictInput((array)$r->get(),['id']);return T06ContentServiceFactory::articles()->detail($this->integer($q['id']??null),$this->context($r),(string)$r->ip(0,false));}); }
    public function create(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'POST');$p=$this->strictInput((array)$r->post(),['keyword_id','question_id','title','content','prompt_template_id','knowledge_item_ids']);$p['knowledge_item_ids']=$this->ids($p['knowledge_item_ids']??[]);return T06ContentServiceFactory::articles()->create($p,$this->context($r),(string)$r->ip(0,false));}); }
    public function update(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'POST');$p=$this->strictInput((array)$r->post(),['id','current_version_id','title','content','prompt_template_id','knowledge_item_ids']);$id=$this->integer($p['id']??null);unset($p['id']);$p['knowledge_item_ids']=$this->ids($p['knowledge_item_ids']??[]);return T06ContentServiceFactory::articles()->edit($id,$p,$this->context($r),(string)$r->ip(0,false));}); }
    public function status(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'POST');$p=$this->strictInput((array)$r->post(),['id','status']);return T06ContentServiceFactory::articles()->setStatus($this->integer($p['id']??null),(string)($p['status']??''),$this->context($r),(string)$r->ip(0,false));}); }
    public function history(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'GET');$q=$this->strictInput((array)$r->get(),['id']);return T06ContentServiceFactory::articles()->versions($this->integer($q['id']??null),$this->context($r),(string)$r->ip(0,false));}); }
    public function version(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'GET');$q=$this->strictInput((array)$r->get(),['id','version_id']);return T06ContentServiceFactory::articles()->version($this->integer($q['id']??null),$this->integer($q['version_id']??null),$this->context($r),(string)$r->ip(0,false));}); }
    public function preview(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'GET');$q=$this->strictInput((array)$r->get(),['id','version_id']);$version=isset($q['version_id'])&&$q['version_id']!==''?$this->integer($q['version_id']):null;return T06ContentServiceFactory::articles()->preview($this->integer($q['id']??null),$version,$this->context($r),(string)$r->ip(0,false));}); }
    public function restore(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'POST');$p=$this->strictInput((array)$r->post(),['id','version_id','current_version_id']);return T06ContentServiceFactory::articles()->restore($this->integer($p['id']??null),$this->integer($p['version_id']??null),$this->integer($p['current_version_id']??null),$this->context($r),(string)$r->ip(0,false));}); }
    public function foundation(): Response { return $this->execute(function(Request $r):array{$this->requireMethod($r,'GET');$q=$this->strictInput((array)$r->get(),['id']);return T06ContentServiceFactory::articles()->context($this->integer($q['id']??null),$this->context($r),(string)$r->ip(0,false));}); }
}
