<?php
declare(strict_types=1);
namespace app\common\portfolio;
use PDO;
final class PortfolioDatabaseGuard {
 public static function assertBeforeMigration(PDO $pdo): void {
  if (!preg_match('/^geo_portfolio_[a-z0-9_]+$/D',(string)$pdo->query('SELECT DATABASE()')->fetchColumn())) {throw new \RuntimeException('Not an isolated Portfolio schema.');}
  $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
  if ($tables===[]) {return;}
  if (!in_array('fa_portfolio_instance',$tables,true)) {throw new \RuntimeException('Unmarked nonempty database refused before migration.');}
  if ($pdo->query("SELECT edition FROM fa_portfolio_instance WHERE id=1")->fetchColumn()!=='portfolio-v1') {throw new \RuntimeException('Untrusted Portfolio instance marker.');}
  if (in_array('fa_companies',$tables,true)) {
   foreach($pdo->query('SELECT name FROM fa_companies')->fetchAll(PDO::FETCH_COLUMN) as $name) {
    if (!in_array($name,['Portfolio Demo A — 澄屿收纳（虚构）','Portfolio Demo B — 岚川户外（虚构）'],true)) {throw new \RuntimeException('Non-demo company found before migration.');}
   }
  }
 }
 public static function initialize(PDO $pdo): void {
  self::assertBeforeMigration($pdo);
  $pdo->exec("CREATE TABLE IF NOT EXISTS fa_portfolio_instance(id INT PRIMARY KEY,edition VARCHAR(30) NOT NULL) ENGINE=InnoDB");
  $pdo->exec("INSERT IGNORE INTO fa_portfolio_instance(id,edition) VALUES(1,'portfolio-v1')");
 }
}
