<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/application/common/portfolio/PortfolioRuntimeGuard.php';
$ini=\app\common\portfolio\PortfolioRuntimeGuard::fromFile($root.'/.env');
$adminFile=$root.'/runtime/mysql-admin.json';
$admin=is_file($adminFile)?json_decode(file_get_contents($adminFile),true,32,JSON_THROW_ON_ERROR):null;
$pdo=new PDO('mysql:host=127.0.0.1;port=33329;charset=utf8mb4','root',$admin['password']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db=$ini['database']['database'];
if (!preg_match('/^geo_portfolio_[a-z0-9_]+$/D',$db)) { throw new RuntimeException('Unsafe database name.'); }
$exists=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$exists->execute([$db]);
if ((int)$exists->fetchColumn()!==0) { throw new RuntimeException('Database exists; not overwritten.'); }
$pdo->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$user=$ini['database']['username'];$password=$ini['database']['password'];
if ($user!=='portfolio') { throw new RuntimeException('Unexpected demo database user.'); }
$pdo->exec('CREATE USER '.$pdo->quote($user)."@'127.0.0.1' IDENTIFIED WITH mysql_native_password BY ".$pdo->quote($password));
$pdo->exec('GRANT ALL ON `'.$db.'`.* TO '.$pdo->quote($user)."@'127.0.0.1'");
if ($admin===null) {
    $random=bin2hex(random_bytes(32));
    $pdo->exec("ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY ".$pdo->quote($random));
    file_put_contents($adminFile,json_encode(['password'=>$random],JSON_THROW_ON_ERROR),LOCK_EX);@chmod($adminFile,0600);
}
echo "PASS: independent demo schema and scoped account created; random credentials not printed.\n";
