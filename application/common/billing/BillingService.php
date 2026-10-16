<?php

declare(strict_types=1);

namespace app\common\billing;

use PDO;
use PDOException;
use app\common\tenant\AuditLogger;

final class BillingService
{
    private $pdo;
    private $auditLogger;
    private $walletTable;
    private $ledgerTable;
    private const MAX_RETRY_DEADLOCK = 3;

    public function __construct(PDO $pdo, AuditLogger $auditLogger, string $prefix)
    {
        $this->pdo = $pdo;
        $this->auditLogger = $auditLogger;
        $this->walletTable = $prefix . 'wallets';
        $this->ledgerTable = $prefix . 'ledger_entries';
    }

    public function getWallet(int $companyId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->q($this->walletTable) . ' WHERE company_id=:cid LIMIT 1'
        );
        $stmt->execute(['cid' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function ensureWallet(int $companyId): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ' . $this->q($this->walletTable) . ' WHERE company_id=:cid LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['cid' => $companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) { $this->pdo->commit(); return $row; }
            $ins = $this->pdo->prepare(
                'INSERT INTO ' . $this->q($this->walletTable)
                . ' (company_id,currency,available_balance,frozen_balance,version)'
                . " VALUES (:cid,'CNY',0.00,0.00,0)"
            );
            $ins->execute(['cid' => $companyId]);
            $this->pdo->commit();
            return $this->getWallet($companyId)
                ?: throw BillingException::walletNotFound($companyId);
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            if ($this->isDuplicateKey($e)) {
                return $this->getWallet($companyId) ?: throw BillingException::walletNotFound($companyId);
            }
            throw $e;
        }
    }

    public function manualCredit(int $companyId, string $amount, int $actorId, string $reason, string $idempotencyKey, string $ip = ''): array
    {
        $this->validateAmount($amount);
        return $this->withRetry(fn() => $this->executeWrite($companyId, 'manual_credit', null, null, $amount, '0.00', null, $reason, $idempotencyKey, $actorId, 'admin', $ip,
            fn(string $av, string $fz, string $a) => [bcadd($av, $amount, 2), $fz, $amount, '0.00']));
    }

    public function manualDebit(int $companyId, string $amount, int $actorId, string $reason, string $idempotencyKey, string $ip = ''): array
    {
        $this->validateAmount($amount);
        return $this->withRetry(fn() => $this->executeWrite($companyId, 'manual_debit', null, null, $amount, '0.00', null, $reason, $idempotencyKey, $actorId, 'admin', $ip,
            function (string $av, string $fz, string $a) use ($amount): array {
                if (bccomp($av, $amount, 2) < 0) throw BillingException::insufficientAvailableBalance($av, $amount);
                return [bcsub($av, $amount, 2), $fz, '-' . $amount, '0.00'];
            }));
    }

    public function freeze(int $companyId, string $bizType, string $bizId, string $amount, string $idempotencyKey, string $ip = ''): array
    {
        $this->validateAmount($amount);
        return $this->withRetry(fn() => $this->executeWrite($companyId, 'freeze', $bizType, $bizId, $amount, $amount, null, null, $idempotencyKey, null, null, $ip,
            function (string $av, string $fz, string $a) use ($amount, $companyId, $bizType, $bizId): array {
                if (bccomp($av, $amount, 2) < 0) throw BillingException::insufficientAvailableBalance($av, $amount);
                $cum = $this->bizCumulative($companyId, $bizType, $bizId, 'freeze');
                if (bccomp($cum, '0.00', 2) > 0) throw BillingException::frozenOverrun($bizType, $bizId, $cum, $amount);
                return [bcsub($av, $amount, 2), bcadd($fz, $amount, 2), '-' . $amount, $amount];
            }));
    }

    public function capture(int $companyId, string $bizType, string $bizId, string $amount, string $idempotencyKey, string $ip = ''): array
    {
        $this->validateAmount($amount);
        return $this->withRetry(fn() => $this->executeWrite($companyId, 'capture', $bizType, $bizId, $amount, $amount, null, null, $idempotencyKey, null, null, $ip,
            function (string $av, string $fz, string $a) use ($amount, $companyId, $bizType, $bizId): array {
                if (bccomp($fz, $amount, 2) < 0) throw BillingException::insufficientFrozenBalance($fz, $amount, $bizType, $bizId);
                $cumCap = $this->bizCumulative($companyId, $bizType, $bizId, 'capture');
                $cumFrz = $this->bizCumulative($companyId, $bizType, $bizId, 'freeze');
                if (bccomp(bcadd($cumCap, $amount, 2), $cumFrz, 2) > 0) throw BillingException::captureOverrun($bizType, $bizId, $cumCap, $amount);
                return [$av, bcsub($fz, $amount, 2), '0.00', '-' . $amount];
            }));
    }

    public function release(int $companyId, string $bizType, string $bizId, string $amount, string $idempotencyKey, string $ip = ''): array
    {
        $this->validateAmount($amount);
        return $this->withRetry(fn() => $this->executeWrite($companyId, 'release', $bizType, $bizId, $amount, $amount, null, null, $idempotencyKey, null, null, $ip,
            function (string $av, string $fz, string $a) use ($amount, $companyId, $bizType, $bizId): array {
                if (bccomp($fz, $amount, 2) < 0) throw BillingException::insufficientFrozenBalance($fz, $amount, $bizType, $bizId);
                $cumRel = $this->bizCumulative($companyId, $bizType, $bizId, 'release');
                $cumCap = $this->bizCumulative($companyId, $bizType, $bizId, 'capture');
                $cumFrz = $this->bizCumulative($companyId, $bizType, $bizId, 'freeze');
                if (bccomp(bcadd($cumCap, bcadd($cumRel, $amount, 2), 2), $cumFrz, 2) > 0) throw BillingException::releaseOverrun($bizType, $bizId, $cumRel, $amount);
                return [bcadd($av, $amount, 2), bcsub($fz, $amount, 2), $amount, '-' . $amount];
            }));
    }

    public function refund(int $companyId, string $bizType, string $bizId, string $amount, string $idempotencyKey, string $ip = ''): array
    {
        $this->validateAmount($amount);
        return $this->withRetry(fn() => $this->executeWrite($companyId, 'refund', $bizType, $bizId, $amount, '0.00', null, null, $idempotencyKey, null, null, $ip,
            function (string $av, string $fz, string $a) use ($amount, $companyId, $bizType, $bizId): array {
                $cumRef = $this->bizCumulative($companyId, $bizType, $bizId, 'refund');
                $cumCap = $this->bizCumulative($companyId, $bizType, $bizId, 'capture');
                if (bccomp(bcadd($cumRef, $amount, 2), $cumCap, 2) > 0) throw BillingException::refundOverrun($bizType, $bizId, $cumRef, $amount);
                return [bcadd($av, $amount, 2), $fz, $amount, '0.00'];
            }));
    }


    public function listLedger(int $companyId, int $page = 1, int $perPage = 20, ?string $entryType = null, ?string $bizType = null): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $where = 'WHERE company_id=:cid';
        $params = ['cid' => $companyId];
        if ($entryType !== null && $entryType !== '') { $where .= ' AND entry_type=:eType'; $params['eType'] = $entryType; }
        if ($bizType !== null && $bizType !== '') { $where .= ' AND biz_type=:bType'; $params['bType'] = $bizType; }
        $cs = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->q($this->ledgerTable) . ' ' . $where);
        $cs->execute($params);
        $total = (int) $cs->fetchColumn();
        $params['lim'] = $perPage; $params['off'] = $offset;
        $ls = $this->pdo->prepare('SELECT * FROM ' . $this->q($this->ledgerTable) . ' ' . $where . ' ORDER BY id DESC LIMIT :lim OFFSET :off');
        $ls->bindValue('cid', $companyId, PDO::PARAM_INT);
        if ($entryType !== null && $entryType !== '') { $ls->bindValue('eType', $entryType); }
        if ($bizType !== null && $bizType !== '') { $ls->bindValue('bType', $bizType); }
        $ls->bindValue('lim', $perPage, PDO::PARAM_INT); $ls->bindValue('off', $offset, PDO::PARAM_INT);
        $ls->execute();
        return ['items' => $ls->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function listAllLedgerForReconcile(int $companyId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->q($this->ledgerTable) . ' WHERE company_id=:cid ORDER BY id ASC');
        $stmt->execute(['cid' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function allCompanyIds(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT company_id FROM ' . $this->q($this->walletTable) . ' ORDER BY company_id ASC');
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'company_id');
    }

    private function executeWrite(
        int $companyId, string $entryType, ?string $bizType, ?string $bizId,
        string $amount, string $bizAmount, ?string $metadataJson, ?string $reason,
        string $idempotencyKey, ?int $actorId, ?string $actorType, string $ip, callable $compute
    ): array {
        $fingerprint = $this->buildFingerprint($entryType, $bizType, $bizId, $amount);
        $this->pdo->beginTransaction();
        try {
            $existing = $this->findIdempotent($companyId, $idempotencyKey);
            if ($existing !== null) {
                if ($existing['idempotency_fingerprint'] !== $fingerprint) {
                    $this->pdo->rollBack();
                    throw BillingException::idempotencyConflict($idempotencyKey,
                        'Different params: expected ' . $existing['idempotency_fingerprint'] . ', got ' . $fingerprint);
                }
                $existing['id'] = (int) $existing['id'];
                $this->pdo->commit(); return $existing;
            }
            $wallet = $this->lockWallet($companyId);
            $av = (string) $wallet['available_balance'];
            $fz = (string) $wallet['frozen_balance'];
            $ver = (int) $wallet['version'];
            [$na, $nf, $ad, $fd] = $compute($av, $fz, $amount);
            if (bccomp($na, '0.00', 2) < 0) { $this->pdo->rollBack(); throw BillingException::insufficientAvailableBalance($av, $amount); }
            if (bccomp($nf, '0.00', 2) < 0) { $this->pdo->rollBack(); throw BillingException::insufficientFrozenBalance($fz, $amount, $bizType ?? '', $bizId ?? ''); }
            $nv = $ver + 1;
            $upd = $this->pdo->prepare('UPDATE ' . $this->q($this->walletTable) . ' SET available_balance=:av, frozen_balance=:fz, version=:ver, updated_at=NOW() WHERE id=:wid AND version=:old');
            $upd->execute(['av' => $na, 'fz' => $nf, 'ver' => $nv, 'wid' => $wallet['id'], 'old' => $ver]);
            if ($upd->rowCount() !== 1) { $this->pdo->rollBack(); throw new BillingException('Version conflict; retry.', 1431); }
            $ins = $this->pdo->prepare(
                'INSERT INTO ' . $this->q($this->ledgerTable)
                . ' (wallet_id,company_id,currency,entry_type,biz_type,biz_id,idempotency_key,'
                . 'idempotency_fingerprint,amount,available_delta,frozen_delta,available_after,'
                . 'frozen_after,actor_id,actor_type,reason,metadata_json,created_at)'
                . ' VALUES (:wid,:cid,:cur,:ety,:bty,:bid,:ik,:ifp,:amt,:ad,:fd,:aa,:fa,:aid,:aty,:rsn,:mj,NOW())'
            );
            try {
                $ins->execute([
                    'wid' => $wallet['id'], 'cid' => $companyId, 'cur' => 'CNY',
                    'ety' => $entryType, 'bty' => $bizType, 'bid' => $bizId,
                    'ik' => $idempotencyKey, 'ifp' => $fingerprint,
                    'amt' => $amount, 'ad' => $ad, 'fd' => $fd, 'aa' => $na, 'fa' => $nf,
                    'aid' => $actorId, 'aty' => $actorType, 'rsn' => $reason,
                    'mj' => ($metadataJson !== null && $metadataJson !== '') ? $metadataJson : null,
                ]);
            } catch (PDOException $e) {
                $this->pdo->rollBack();
                if ($this->isDuplicateKey($e)) {
                    $existing = $this->findIdempotent($companyId, $idempotencyKey);
                    if ($existing !== null) {
                        if ($existing['idempotency_fingerprint'] !== $fingerprint) {
                            throw BillingException::idempotencyConflict($idempotencyKey,
                                'Concurrent conflict: expected ' . $existing['idempotency_fingerprint'] . ', got ' . $fingerprint);
                        }
                        return $existing;
                    }
                }
                throw $e;
            }
            $eid = (int) $this->pdo->lastInsertId();
            $isManual = ($entryType === 'manual_credit' || $entryType === 'manual_debit');
            if ($isManual && $actorId !== null && $actorId > 0) {
                try {
                    $this->auditLogger->record('wallet_' . $entryType, null, 'wallet', (string) $eid, $ip,
                        ['company_id' => $companyId, 'amount' => $amount, 'available_after' => $na, 'frozen_after' => $nf, 'reason' => $reason, 'actor_id' => $actorId]);
                } catch (\Throwable $ae) { $this->pdo->rollBack(); throw new BillingException('Manual audit failed; rolled back.', 1432); }
            }
            $this->pdo->commit();
            if (!$isManual) {
                try { $this->auditLogger->record('wallet_' . $entryType, null, 'wallet', (string) $eid, $ip,
                    ['company_id' => $companyId, 'amount' => $amount, 'available_after' => $na, 'frozen_after' => $nf, 'reason' => $reason]); }
                catch (\Throwable $e) {}
            }
            return ['id' => $eid, 'company_id' => $companyId, 'entry_type' => $entryType, 'biz_type' => $bizType, 'biz_id' => $bizId,
                'amount' => $amount, 'available_delta' => $ad, 'frozen_delta' => $fd,
                'available_after' => $na, 'frozen_after' => $nf, 'idempotency_key' => $idempotencyKey];
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $e; }
    }

    private function lockWallet(int $companyId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->q($this->walletTable) . ' WHERE company_id=:cid LIMIT 1 FOR UPDATE');
        $stmt->execute(['cid' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) { throw BillingException::walletNotFound($companyId); }
        return $row;
    }

    private function findIdempotent(int $companyId, string $idempotencyKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->q($this->ledgerTable) . ' WHERE company_id=:cid AND idempotency_key=:ik LIMIT 1');
        $stmt->execute(['cid' => $companyId, 'ik' => $idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function bizCumulative(int $companyId, string $bizType, string $bizId, string $entryType): string
    {
        if ($bizType === null || $bizId === null || $bizType === '' || $bizId === '') { return '0.00'; }
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM ' . $this->q($this->ledgerTable)
            . ' WHERE company_id=:cid AND biz_type=:bty AND biz_id=:bid AND entry_type=:ety');
        $stmt->execute(['cid' => $companyId, 'bty' => $bizType, 'bid' => $bizId, 'ety' => $entryType]);
        return (string) $stmt->fetchColumn();
    }

    private function buildFingerprint(string $entryType, ?string $bizType, ?string $bizId, string $amount): string
    {
        return hash('sha256', implode('|', [$entryType, $bizType ?? '', $bizId ?? '', bcadd($amount, '0.00', 2), 'CNY']));
    }

    private function validateAmount(string $amount): void
    {
        if (!is_string($amount) || $amount === '') { throw BillingException::invalidAmount($amount); }
        if (!preg_match('/^\d+(\.\d{1,2})?$/D', $amount)) { throw BillingException::invalidAmount($amount); }
        if (bccomp($amount, '0.00', 2) <= 0) { throw BillingException::invalidAmount($amount); }
        if (bccomp($amount, '9999999999999999.99', 2) > 0) { throw BillingException::invalidAmount($amount); }
    }

    private function withRetry(callable $fn): array
    {
        $le = null;
        for ($a = 1; $a <= self::MAX_RETRY_DEADLOCK; $a++) {
            try { return $fn(); }
            catch (PDOException $e) {
                $le = $e;
                if ($a < self::MAX_RETRY_DEADLOCK && $this->isDeadlock($e)) { usleep(rand(5000, 50000)); continue; }
                throw $e;
            }
        }
        throw $le;
    }

    private function isDeadlock(PDOException $e): bool
    {
        $ss = $e->getCode();
        if (is_string($ss) && in_array($ss, ['40001', '40P01', 'HY000'], true)) { return true; }
        return stripos($e->getMessage(), 'deadlock') !== false || stripos($e->getMessage(), '1213') !== false;
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        $ss = $e->getCode();
        if (is_string($ss) && in_array($ss, ['23000'], true)) { return true; }
        return stripos($e->getMessage(), 'duplicate') !== false || stripos($e->getMessage(), '1062') !== false;
    }

    private function q(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) { throw new \RuntimeException('Billing SQL identifier is invalid.'); }
        return '`' . $identifier . '`';
    }
}
