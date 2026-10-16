<?php
declare(strict_types=1);
$root=dirname(__DIR__);
if (is_file($root.'/.env')) { fwrite(STDERR,"Local .env already exists; no overwrite.\n"); exit(1); }
$template=str_replace("\r\n","\n",file_get_contents($root.'/.env.example'));
$template=str_replace('change-me-with-a-random-64-character-hex-value',bin2hex(random_bytes(32)),$template);
$template=str_replace('change-me-with-base64-encoded-32-byte-random-key','base64:'.base64_encode(random_bytes(32)),$template);
$template=str_replace('password = change-me','password = '.bin2hex(random_bytes(24)),$template);
$template=preg_replace("/(?m)^contact_encryption_key = (base64:.*)$/",'contact_encryption_key = "$1"',$template);
file_put_contents($root.'/.env',$template,LOCK_EX);
@chmod($root.'/.env',0600);
@mkdir($root.'/runtime',0700,true);
echo "PASS: new local demo config created; credentials were not printed.\n";
