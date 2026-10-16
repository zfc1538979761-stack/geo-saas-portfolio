<?php

declare(strict_types=1);

define("ROOT_PATH", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define("APP_PATH", ROOT_PATH . "application" . DIRECTORY_SEPARATOR);
require ROOT_PATH . "thinkphp" . DIRECTORY_SEPARATOR . "base.php";
\think\Loader::addNamespace("app", APP_PATH);

use app\common\publishing\OrderStateMachine;
use app\common\publishing\PublishException;

$pass = 0; $fail = 0;
function test(string $n, callable $f): void {
    global $pass, $fail;
    try { $f(); echo "PASS: {$n}\n"; $pass++; }
    catch (\Throwable $e) { echo "FAIL: {$n} - " . $e->getMessage() . "\n"; $fail++; }
}
function assertTrue(bool $c, string $m = ""): void { if (!$c) throw new \RuntimeException($m ?: "assertion failed"); }

echo "T11 Contract Tests\n==================\n";

$sm = new OrderStateMachine();

test("001 funds_frozen->queued valid", fn() => assertTrue($sm->canTransition("funds_frozen", "queued")));
test("002 funds_frozen->cancelled valid", fn() => assertTrue($sm->canTransition("funds_frozen", "cancelled")));
test("003 queued->submitted valid", fn() => assertTrue($sm->canTransition("queued", "submitted")));
test("004 submitted->processing valid", fn() => assertTrue($sm->canTransition("submitted", "processing")));
test("005 processing->published valid", fn() => assertTrue($sm->canTransition("processing", "published")));
test("006 processing->rejected valid", fn() => assertTrue($sm->canTransition("processing", "rejected")));
test("007 published->after_sale valid", fn() => assertTrue($sm->canTransition("published", "after_sale")));
test("008 after_sale->refunded valid", fn() => assertTrue($sm->canTransition("after_sale", "refunded")));

test("009 funds_frozen->published invalid", fn() => assertTrue(!$sm->canTransition("funds_frozen", "published")));
test("010 published->funds_frozen invalid", fn() => assertTrue(!$sm->canTransition("published", "funds_frozen")));
test("011 cancelled->published invalid", fn() => assertTrue(!$sm->canTransition("cancelled", "published")));

test("012 derive all published", fn() => assertTrue(OrderStateMachine::deriveOrderStatus(["published","published"]) === "published"));
test("013 derive mixed terminals", fn() => assertTrue(OrderStateMachine::deriveOrderStatus(["published","rejected"]) === "partially_completed"));
test("014 derive all refunded", fn() => assertTrue(OrderStateMachine::deriveOrderStatus(["refunded","refunded"]) === "refunded"));
test("015 derive with manual_review", fn() => assertTrue(OrderStateMachine::deriveOrderStatus(["published","manual_review"]) === "manual_review"));
test("016 empty = funds_frozen", fn() => assertTrue(OrderStateMachine::deriveOrderStatus([]) === "funds_frozen"));

test("017 canCancel all frozen", fn() => assertTrue(OrderStateMachine::canCancelOrder(["funds_frozen","funds_frozen"])));
test("018 cannotCancel with queued", fn() => assertTrue(!OrderStateMachine::canCancelOrder(["funds_frozen","queued"])));

test("019 fundsOp capture", fn() => assertTrue(OrderStateMachine::getFundsOperation("published") === "capture"));
test("020 fundsOp release", fn() => assertTrue(OrderStateMachine::getFundsOperation("rejected") === "release"));
test("021 fundsOp refund", fn() => assertTrue(OrderStateMachine::getFundsOperation("refunded") === "refund"));
test("022 fundsOp null", fn() => assertTrue(OrderStateMachine::getFundsOperation("queued") === null));

echo "\nT11 Contracts: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
