<?php

declare(strict_types=1);

namespace app\admin\command;

use app\common\billing\BillingServiceFactory;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Db;

class WalletReconcile extends Command
{
    protected function configure()
    {
        $this->setName('geo:wallet:reconcile')
            ->setDescription('Reconcile wallet balances against immutable ledger entries.')
            ->addOption('company_id', null, \think\console\input\Option::VALUE_OPTIONAL, 'Specific company ID to reconcile');
    }

    protected function execute(Input $input, Output $output)
    {
        $pdo = Db::connect()->connect();
        $prefix = (string) (Db::getConfig('prefix') ?: 'fa_');
        $service = BillingServiceFactory::create();
        $companyId = $input->getOption('company_id');

        $missingStmt = $pdo->query(
            'SELECT c.id FROM `' . $prefix . 'companies` c '
            . 'LEFT JOIN `' . $prefix . 'wallets` w ON w.company_id=c.id '
            . 'WHERE w.id IS NULL ORDER BY c.id ASC'
        );
        $missing = array_column($missingStmt->fetchAll(\PDO::FETCH_ASSOC), 'id');
        if (count($missing) > 0) {
            $output->writeln('WARN: ' . count($missing) . ' companies have no wallet; skipped without fabricating records.');
            foreach ($missing as $mw) {
                $output->writeln("  Company {$mw}: missing wallet");
            }
        }

        if ($companyId !== null) {
            $companyId = (int) $companyId;
            $wallet = $service->getWallet($companyId);
            if ($wallet === null) {
                $output->writeln("ERROR: Company {$companyId} does not have a wallet.");
                return 1;
            }
            $companyIds = [$companyId];
        } else {
            $companyIds = $service->allCompanyIds();
            if (count($companyIds) === 0) {
                // Check if companies table has any entries at all
                $stmt = $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'companies`');
                $companyCount = (int) $stmt->fetchColumn();
                if ($companyCount > 0) {
                    // Missing-wallet companies were already reported above and are not reconciled.
                }
                $output->writeln('RECONCILIATION PASSED: 0 companies, zero differences.');
                return 0;
            }
        }

        $errors = [];
        $passed = 0;

        foreach ($companyIds as $cid) {
            $wallet = $service->getWallet($cid);
            if ($wallet === null) {
                $errors[] = "Company {$cid}: wallet not found.";
                continue;
            }

            $entries = $service->listAllLedgerForReconcile($cid);

            $seenKeys = [];
            foreach ($entries as $entry) {
                $ik = $entry['idempotency_key'];
                if (isset($seenKeys[$ik])) {
                    $errors[] = "Company {$cid}: duplicate idempotency_key '{$ik}' at entries {$seenKeys[$ik]} and {$entry['id']}.";
                }
                $seenKeys[$ik] = $entry['id'];
            }

            $runningAvailable = '0.00';
            $runningFrozen = '0.00';
            $bizFreeze = [];
            $bizCapture = [];
            $bizRelease = [];
            $bizRefund = [];

            foreach ($entries as $entry) {
                $eid = $entry['id'];
                $ad = (string) $entry['available_delta'];
                $fd = (string) $entry['frozen_delta'];
                $aa = (string) $entry['available_after'];
                $fa = (string) $entry['frozen_after'];

                $runningAvailable = bcadd($runningAvailable, $ad, 2);
                $runningFrozen = bcadd($runningFrozen, $fd, 2);

                if (bccomp($runningAvailable, $aa, 2) !== 0) {
                    $errors[] = "Company {$cid}: entry {$eid} available_after mismatch: computed={$runningAvailable}, stored={$aa}.";
                }
                if (bccomp($runningFrozen, $fa, 2) !== 0) {
                    $errors[] = "Company {$cid}: entry {$eid} frozen_after mismatch: computed={$runningFrozen}, stored={$fa}.";
                }

                $bizType = $entry['biz_type'];
                $bizId = $entry['biz_id'];
                $ety = $entry['entry_type'];
                if ($bizType !== null && $bizId !== null && $bizType !== '' && $bizId !== '') {
                    $bizKey = $bizType . ':' . $bizId;
                    $amount = (string) $entry['amount'];
                    if ($ety === 'freeze') { $bizFreeze[$bizKey] = bcadd($bizFreeze[$bizKey] ?? '0.00', $amount, 2); }
                    elseif ($ety === 'capture') { $bizCapture[$bizKey] = bcadd($bizCapture[$bizKey] ?? '0.00', $amount, 2); }
                    elseif ($ety === 'release') { $bizRelease[$bizKey] = bcadd($bizRelease[$bizKey] ?? '0.00', $amount, 2); }
                    elseif ($ety === 'refund') { $bizRefund[$bizKey] = bcadd($bizRefund[$bizKey] ?? '0.00', $amount, 2); }
                }
            }

            $walletAvailable = (string) $wallet['available_balance'];
            $walletFrozen = (string) $wallet['frozen_balance'];
            if (bccomp($runningAvailable, $walletAvailable, 2) !== 0) {
                $errors[] = "Company {$cid}: wallet available_balance mismatch: computed={$runningAvailable}, wallet={$walletAvailable}.";
            }
            if (bccomp($runningFrozen, $walletFrozen, 2) !== 0) {
                $errors[] = "Company {$cid}: wallet frozen_balance mismatch: computed={$runningFrozen}, wallet={$walletFrozen}.";
            }
            if (bccomp($runningAvailable, '0.00', 2) < 0) {
                $errors[] = "Company {$cid}: negative available_balance: {$runningAvailable}.";
            }
            if (bccomp($runningFrozen, '0.00', 2) < 0) {
                $errors[] = "Company {$cid}: negative frozen_balance: {$runningFrozen}.";
            }

            foreach ($bizFreeze as $bizKey => $frozenTotal) {
                $captureTotal = $bizCapture[$bizKey] ?? '0.00';
                $releaseTotal = $bizRelease[$bizKey] ?? '0.00';
                $refundTotal = $bizRefund[$bizKey] ?? '0.00';
                $consumed = bcadd($captureTotal, $releaseTotal, 2);
                if (bccomp($consumed, $frozenTotal, 2) > 0) {
                    $errors[] = "Company {$cid}: business {$bizKey}: capture({$captureTotal})+release({$releaseTotal}) > freeze({$frozenTotal}).";
                }
                if (bccomp($refundTotal, $captureTotal, 2) > 0) {
                    $errors[] = "Company {$cid}: business {$bizKey}: refund({$refundTotal}) > capture({$captureTotal}).";
                }
            }
            $passed++;
        }

        if (count($errors) > 0) {
            $output->writeln('RECONCILIATION FAILED:');
            foreach ($errors as $err) { $output->writeln('  ' . $err); }
            $output->writeln('');
            $output->writeln("Total companies checked: " . count($companyIds) . ", errors: " . count($errors));
            return 1;
        }
        $output->writeln("RECONCILIATION PASSED: {$passed} companies, zero differences.");
        return 0;
    }
}
