<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\content\T06ContentServiceFactory;
use think\Request;
use think\Response;

final class Knowledge extends T06ApiController
{
    public function index(): Response { return $this->execute(function (Request $r): array { $this->requireMethod($r, 'GET'); return T06ContentServiceFactory::knowledge()->page((array) $r->get(), $this->context($r)); }); }
    public function detail(): Response { return $this->execute(function (Request $r): array { $this->requireMethod($r, 'GET'); $q=$this->strictInput((array)$r->get(), ['id']); return T06ContentServiceFactory::knowledge()->detail($this->integer($q['id']??null), $this->context($r), (string)$r->ip(0,false)); }); }
    public function create(): Response { return $this->execute(function (Request $r): array { $this->requireMethod($r, 'POST'); return T06ContentServiceFactory::knowledge()->createText($this->strictInput((array)$r->post(), ['title','content']), $this->context($r), (string)$r->ip(0,false)); }); }
    public function update(): Response { return $this->execute(function (Request $r): array { $this->requireMethod($r, 'POST'); $p=$this->strictInput((array)$r->post(), ['id','title','content']); return T06ContentServiceFactory::knowledge()->updateText($this->integer($p['id']??null), ['title'=>$p['title']??null,'content'=>$p['content']??null], $this->context($r), (string)$r->ip(0,false)); }); }
    public function upload(): Response
    {
        return $this->execute(function (Request $r): array {
            $this->requireMethod($r, 'POST');
            $p=$this->strictInput((array)$r->post(), ['title']);
            $file=$r->file('file');
            if (!$file) { return T06ContentServiceFactory::knowledge()->createFile($p, '', '', '', 0, $this->context($r), (string)$r->ip(0,false)); }
            $info=$file->getInfo();
            return T06ContentServiceFactory::knowledge()->createFile($p, (string)$info['tmp_name'], (string)$info['name'], (string)$info['type'], (int)$info['size'], $this->context($r), (string)$r->ip(0,false));
        });
    }
    public function status(): Response { return $this->execute(function (Request $r): array { $this->requireMethod($r,'POST'); $p=$this->strictInput((array)$r->post(),['id','status']); return T06ContentServiceFactory::knowledge()->setStatus($this->integer($p['id']??null),(string)($p['status']??''),$this->context($r),(string)$r->ip(0,false)); }); }
    public function delete(): Response { return $this->execute(function (Request $r): array { $this->requireMethod($r,'POST'); $p=$this->strictInput((array)$r->post(),['id']); T06ContentServiceFactory::knowledge()->delete($this->integer($p['id']??null),$this->context($r),(string)$r->ip(0,false)); return ['deleted'=>true]; }); }
    public function preview(): Response { return $this->execute(function (Request $r): array { $this->requireMethod($r,'GET'); $q=$this->strictInput((array)$r->get(),['id']); return T06ContentServiceFactory::knowledge()->preview($this->integer($q['id']??null),$this->context($r),(string)$r->ip(0,false)); }); }
    public function download(): Response
    {
        return $this->execute(function (Request $r): array {
            $this->requireMethod($r,'GET'); $q=$this->strictInput((array)$r->get(),['id']);
            $result=T06ContentServiceFactory::knowledge()->readFile($this->integer($q['id']??null),$this->context($r),(string)$r->ip(0,false));
            return ['mime'=>$result->mime(),'base64'=>base64_encode($result->contents())];
        });
    }
}
