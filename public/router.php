<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$candidate=realpath(__DIR__.$path);
if ($candidate!==false && strpos($candidate,__DIR__.DIRECTORY_SEPARATOR)===0 && is_file($candidate)) { return false; }
require __DIR__.'/index.php';
