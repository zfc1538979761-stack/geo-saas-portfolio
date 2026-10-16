<?php

declare(strict_types=1);

if (getenv('GEO_T06_HTTP_TEST_ENABLE') !== '1') { fwrite(STDOUT, "SKIP: T06 HTTP test is not enabled\n"); exit(0); }
if (getenv('GEO_APP_ENV') !== 'testing' || getenv('GEO_T06_HTTP_TEST_ALLOW_SHARED_DATABASE') !== '1' || getenv('GEO_T06_HTTP_TEST_PREFIX') !== 't06_http_') {
    fwrite(STDERR, "FAIL: T06 HTTP safety gate rejected the environment\n"); exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);
use app\common\migration\MigrationManager;

final class T06HttpFailure extends RuntimeException {}
function t06HttpAssert(bool $condition, string $message): void { if (!$condition) { throw new T06HttpFailure($message); } fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL); }
function t06HttpRequest(string $base, string $method, string $path, array $body = [], array $headers = [], ?string $raw = null): array
{
    $default = ['Accept: application/json'];
    if ($raw === null) { $default[] = 'Content-Type: application/x-www-form-urlencoded'; $raw = $body === [] ? '' : http_build_query($body); }
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", array_merge($default, $headers)), 'content' => $raw, 'ignore_errors' => true, 'timeout' => 8, 'follow_location' => 0]]);
    $text = @file_get_contents($base . $path, false, $context); $responseHeaders = $http_response_header ?? []; $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $m)) { $status = (int) $m[1]; }
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => is_string($text) ? $text : '', 'payload' => json_decode(is_string($text) ? $text : '', true)];
}
function t06HttpWait(string $base): void { $until=microtime(true)+10; do { if (@file_get_contents($base.'/api/version')!==false) return; usleep(100000); } while(microtime(true)<$until); throw new T06HttpFailure('server did not start'); }

$ini=parse_ini_file(ROOT_PATH.'.env',true,INI_SCANNER_RAW);$db=is_array($ini)?($ini['database']??[]):[];
$v=static function(string $key,$fallback){$x=getenv($key);return $x===false?$fallback:$x;};
$host=(string)$v('GEO_DATABASE_HOSTNAME',$db['hostname']??'');$port=(int)$v('GEO_DATABASE_HOSTPORT',$db['hostport']??0);$name=(string)$v('GEO_DATABASE_DATABASE',$db['database']??'');$user=(string)$v('GEO_DATABASE_USERNAME',$db['username']??'');$password=(string)$v('GEO_DATABASE_PASSWORD',$db['password']??'');
if($host!=='127.0.0.1'||$port===3306||$name!==(string)getenv('GEO_T06_HTTP_TEST_DATABASE')){fwrite(STDERR,"FAIL: T06 HTTP requires isolated non-3306 database\n");exit(1);}
$prefix='t06_http_';$names=['article_version_knowledge','article_versions','articles','prompt_templates','knowledge_items','oss_file_indexes','questions','keywords','company_profiles','audit_logs','geo_auth_throttles','user_token','user','companies','geo_async_jobs','geo_migrations'];$tables=array_map(static function(string $t)use($prefix):string{return $prefix.$t;},$names);
$pdo=null;$process=null;$pipes=[];$failure=null;$before=[];$out=tempnam(sys_get_temp_dir(),'geo-t06-http-out-');$err=tempnam(sys_get_temp_dir(),'geo-t06-http-err-');
try {
    $pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$host,$port,$name),$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $before=$pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    foreach($tables as $table){t06HttpAssert(!in_array($table,$before,true),'HTTP namespace is empty: '.$table);}
    $manager=new MigrationManager($pdo,ROOT_PATH.'database'.DIRECTORY_SEPARATOR.'migrations',$prefix);t06HttpAssert(count($manager->migrateThrough('202608010006_create_content_version_foundation'))===6,'HTTP fixture applies T01 through T06');
    $now=time();$company=$pdo->prepare('INSERT INTO `'.$prefix.'companies` (`name`,`status`,`createtime`,`updatetime`) VALUES (?,?,?,?)');$account=$pdo->prepare('INSERT INTO `'.$prefix.'user` (`company_id`,`username`,`password_hash`,`role`,`status`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?)');$fixtures=[];
    foreach(['a','b'] as $suffix){$company->execute(['T06 Company '.strtoupper($suffix),'normal',$now,$now]);$cid=(int)$pdo->lastInsertId();$account->execute([$cid,'t06-http-'.$suffix,password_hash('T06-Http-2026',PASSWORD_DEFAULT),'company_owner','normal',$now,$now]);$fixtures[$suffix]=['company_id'=>$cid,'user_id'=>(int)$pdo->lastInsertId()];}
    $socket=stream_socket_server('tcp://127.0.0.1:0',$eno,$emsg);t06HttpAssert($socket!==false,'reserve HTTP port');$socketName=stream_socket_get_name($socket,false);fclose($socket);$httpPort=(int)substr(strrchr((string)$socketName,':'),1);$base='http://127.0.0.1:'.$httpPort;
    $serverEnvironment=array_merge(getenv(),['GEO_APP_ENV'=>'testing','GEO_APP_TOKEN_KEY'=>'test-only-t06-http-token-key-20260801','GEO_COMPANY_CONTACT_KEY'=>'base64:'.base64_encode(random_bytes(32)),'GEO_DATABASE_TYPE'=>'mysql','GEO_DATABASE_HOSTNAME'=>$host,'GEO_DATABASE_HOSTPORT'=>(string)$port,'GEO_DATABASE_DATABASE'=>$name,'GEO_DATABASE_USERNAME'=>$user,'GEO_DATABASE_PASSWORD'=>$password,'GEO_DATABASE_PREFIX'=>$prefix,'GEO_CONTENT_STORAGE_DRIVER'=>'mock']);
    $process=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',ROOT_PATH.'public',ROOT_PATH.'public'.DIRECTORY_SEPARATOR.'router.php'],[0=>['pipe','r'],1=>['file',$out,'a'],2=>['file',$err,'a']],$pipes,ROOT_PATH,$serverEnvironment,['bypass_shell'=>true]);t06HttpAssert(is_resource($process),'start isolated HTTP server');t06HttpWait($base);
    $headers=[];foreach(['a','b'] as $suffix){$login=t06HttpRequest($base,'POST','/api/auth/login',['account'=>'t06-http-'.$suffix,'password'=>'T06-Http-2026']);t06HttpAssert($login['status']===200,'login '.strtoupper($suffix));$headers[$suffix]=['Authorization: Bearer '.(string)($login['payload']['data']['token']??'')];}
    t06HttpAssert(t06HttpRequest($base,'GET','/api/knowledge/index')['status']===401,'knowledge requires bearer authentication');
    t06HttpAssert(t06HttpRequest($base,'GET','/api/knowledge/index',[],['Cookie: token=fake'])['status']===401,'cookie-only request is rejected for CSRF safety');
    $textA=t06HttpRequest($base,'POST','/api/knowledge/create',['title'=>'A facts','content'=>'<script>globalThis.t06=true</script>'],$headers['a']);t06HttpAssert($textA['status']===200,'A creates text knowledge');$textAId=(int)($textA['payload']['data']['id']??0);
    t06HttpAssert(t06HttpRequest($base,'POST','/api/knowledge/create',['title'=>'forged','content'=>'x','company_id'=>$fixtures['b']['company_id']],$headers['a'])['status']===422,'knowledge body rejects forged company_id');
    t06HttpAssert(t06HttpRequest($base,'GET','/api/knowledge/index?company_id='.$fixtures['b']['company_id'],[],$headers['a'])['status']===422,'knowledge query rejects forged company_id');
    $preview=t06HttpRequest($base,'GET','/api/knowledge/preview?id='.$textAId,[],$headers['a']);t06HttpAssert($preview['status']===200&&strpos((string)($preview['payload']['data']['html']??''),'<script>')===false,'knowledge HTTP preview escapes scripts');
    $boundary='----GeoT06'.bin2hex(random_bytes(8));$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',true);$multipart="--$boundary\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nLogo\r\n--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"logo.png\"\r\nContent-Type: image/png\r\n\r\n".$png."\r\n--$boundary--\r\n";
    $upload=t06HttpRequest($base,'POST','/api/knowledge/upload',[],array_merge($headers['a'],['Content-Type: multipart/form-data; boundary='.$boundary]),$multipart);t06HttpAssert($upload['status']===200,'HTTP file upload uses validated MediaMarket Mock');$fileAId=(int)($upload['payload']['data']['id']??0);
    t06HttpAssert(t06HttpRequest($base,'GET','/api/knowledge/download?id='.$fileAId,[],$headers['b'])['status']===404,'B cannot download A file');
    $prompt=t06HttpRequest($base,'POST','/api/prompt/create',['name'=>'Writer','scene'=>'article','content'=>'v1'],$headers['a']);t06HttpAssert($prompt['status']===200&&($prompt['payload']['data']['version_no']??0)===1,'prompt HTTP creates version one');$promptId=(int)$prompt['payload']['data']['id'];$logical=(string)$prompt['payload']['data']['logical_key'];
    $prompt2=t06HttpRequest($base,'POST','/api/prompt/version',['logical_key'=>$logical,'name'=>'Writer','scene'=>'article','content'=>'v2'],$headers['a']);t06HttpAssert($prompt2['status']===200&&($prompt2['payload']['data']['version_no']??0)===2,'prompt HTTP creates immutable next version');$prompt2Id=(int)$prompt2['payload']['data']['id'];
    t06HttpAssert(t06HttpRequest($base,'POST','/api/prompt/version',['logical_key'=>$logical,'name'=>'x','scene'=>'article','content'=>'x','version_no'=>99],$headers['a'])['status']===422,'prompt HTTP protects version number');
    $keyword=t06HttpRequest($base,'POST','/api/keyword/create',['keyword'=>'T06 keyword'],$headers['a']);$keywordId=(int)$keyword['payload']['data']['id'];$question=t06HttpRequest($base,'POST','/api/question/create',['keyword_id'=>$keywordId,'text'=>'T06 question'],$headers['a']);$questionId=(int)$question['payload']['data']['id'];
    $article=t06HttpRequest($base,'POST','/api/article/create',['keyword_id'=>$keywordId,'question_id'=>$questionId,'title'=>'Draft','content'=>'<img src=x onerror=alert(1)>','prompt_template_id'=>$prompt2Id,'knowledge_item_ids'=>json_encode([$textAId,$fileAId])],$headers['a']);t06HttpAssert($article['status']===200&&($article['payload']['data']['current_version']['version_no']??0)===1,'article HTTP atomically creates version one');$articleId=(int)$article['payload']['data']['id'];$version1=(int)$article['payload']['data']['current_version_id'];
    t06HttpAssert(t06HttpRequest($base,'POST','/api/article/create',['keyword_id'=>$keywordId,'question_id'=>$questionId,'title'=>'x','content'=>'x','prompt_template_id'=>$promptId,'knowledge_item_ids'=>'[]','source'=>'ai'],$headers['a'])['status']===422,'article HTTP rejects AI source forgery');
    $edit=t06HttpRequest($base,'POST','/api/article/update',['id'=>$articleId,'current_version_id'=>$version1,'title'=>'Draft v2','content'=>'body2','prompt_template_id'=>$prompt2Id,'knowledge_item_ids'=>json_encode([$textAId])],$headers['a']);t06HttpAssert($edit['status']===200&&($edit['payload']['data']['current_version']['version_no']??0)===2,'article HTTP edit creates version two');$version2=(int)$edit['payload']['data']['current_version_id'];
    t06HttpAssert(t06HttpRequest($base,'POST','/api/article/update',['id'=>$articleId,'current_version_id'=>$version1,'title'=>'stale','content'=>'stale','prompt_template_id'=>$prompt2Id,'knowledge_item_ids'=>'[]'],$headers['a'])['status']===409,'HTTP stale edit is rejected');
    $restore=t06HttpRequest($base,'POST','/api/article/restore',['id'=>$articleId,'version_id'=>$version1,'current_version_id'=>$version2],$headers['a']);t06HttpAssert($restore['status']===200&&($restore['payload']['data']['current_version']['version_no']??0)===3,'HTTP restore creates a new version');
    $foreign=t06HttpRequest($base,'GET','/api/article/detail?id='.$articleId,[],$headers['b']);$missing=t06HttpRequest($base,'GET','/api/article/detail?id=999999',[],$headers['b']);t06HttpAssert($foreign['status']===404&&($foreign['payload']['msg']??'')===($missing['payload']['msg']??''),'cross-tenant article is indistinguishable from missing');
    $articlePreview=t06HttpRequest($base,'GET','/api/article/preview?id='.$articleId.'&version_id='.$version1,[],$headers['a']);t06HttpAssert($articlePreview['status']===200&&strpos((string)($articlePreview['payload']['data']['html']??''),'<img')===false,'article version preview escapes active HTML');
    $injection=t06HttpRequest($base,'GET','/api/article/index?search='.rawurlencode("%' OR 1=1 --"),[],$headers['a']);t06HttpAssert($injection['status']===200&&(int)($injection['payload']['data']['total']??-1)===0,'article search parameterizes SQL-shaped input');
    foreach(['knowledge'=>'知识素材管理','prompt'=>'写作指令管理','article'=>'文章版本管理'] as $page=>$label){$response=t06HttpRequest($base,'GET','/index/'.$page.'/index');t06HttpAssert($response['status']===200&&strpos($response['body'],$label)!==false,'enterprise page is available: '.$page);t06HttpAssert(stripos($response['body'],'innerHTML')===false,'page avoids unsafe innerHTML: '.$page);}
    $audits=$pdo->query('SELECT `detail_json` FROM `'.$prefix.'audit_logs`')->fetchAll(PDO::FETCH_COLUMN);$auditText=implode(' ',$audits);t06HttpAssert(count($audits)>=8,'HTTP writes produce audit records');t06HttpAssert(strpos($auditText,'body2')===false&&strpos($auditText,'globalThis.t06')===false,'HTTP audits exclude full content');
    proc_terminate($process);proc_close($process);$process=null;$logs=(string)@file_get_contents($out).(string)@file_get_contents($err);t06HttpAssert(strpos($logs,'globalThis.t06')===false&&strpos($logs,'body2')===false,'ordinary logs exclude enterprise content');
} catch(Throwable $exception){$failure=$exception;} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}if($pdo instanceof PDO){$pdo->exec('SET FOREIGN_KEY_CHECKS=0');foreach($tables as $table){$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}$pdo->exec('SET FOREIGN_KEY_CHECKS=1');$after=$pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);if($after!==$before){$failure=new T06HttpFailure('HTTP cleanup changed unrelated tables');}}
    if(is_string($out)){@unlink($out);}if(is_string($err)){@unlink($err);}
}
if($failure!==null){fwrite(STDERR,'FAIL: '.get_class($failure).' file='.basename($failure->getFile()).' line='.$failure->getLine().' message='.preg_replace('/(?:password|token|secret|authorization|cookie|key)\s*[=:]\s*[^\s,;]+/i','$1=[REDACTED]',$failure->getMessage()).PHP_EOL);exit(1);}
fwrite(STDOUT,"RESULT: T06 HTTP auth, CRUD, upload, tenant, CSRF, XSS, SQL injection, audit, and page tests passed\n");
