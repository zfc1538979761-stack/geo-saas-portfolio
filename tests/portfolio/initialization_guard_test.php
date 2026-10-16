<?php
require __DIR__.'/bootstrap.php';
use app\common\portfolio\PortfolioDatabaseGuard;
check(class_exists(PortfolioDatabaseGuard::class),'pre-migration provenance guard exists');
class Rows extends PDOStatement { private array $rows; public function __construct(array $rows) {$this->rows=$rows;} public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array{return $this->rows;} public function fetchColumn(int $column=0):mixed{return $this->rows[0]??false;} }
class FixtureDb extends PDO {public array $tables;public int $writes=0;public function __construct(array $tables){$this->tables=$tables;}public function query(string $query,?int $mode=null,...$args):PDOStatement|false {if(strpos($query,'DATABASE()')!==false)return new Rows(['geo_portfolio_fixture']);if(strpos($query,'SHOW TABLES')!==false)return new Rows($this->tables);return new Rows([]);}public function exec(string $statement):int|false{$this->writes++;return 0;}}
$unsafe=new FixtureDb(['fa_companies','customer_data']);
rejects(fn()=>PortfolioDatabaseGuard::assertBeforeMigration($unsafe),'unmarked nonempty database rejected before migration');
check($unsafe->writes===0,'refusal performs zero DDL or data writes');
PortfolioDatabaseGuard::assertBeforeMigration(new FixtureDb([]));
check(true,'empty isolated schema accepted');
