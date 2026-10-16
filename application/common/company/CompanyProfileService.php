<?php

declare(strict_types=1);

namespace app\common\company;

use app\common\foundation\ApiErrorCode;
use app\common\tenant\AuditLogger;
use app\common\tenant\TenantCompanyGateway;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use PDO;
use Throwable;

final class CompanyProfileService
{
    private $pdo;
    private $gateway;
    private $companyGateway;
    private $cipher;
    private $auditLogger;
    private $validator;
    private $clock;

    public function __construct(
        PDO $pdo,
        TenantDataGateway $gateway,
        TenantCompanyGateway $companyGateway,
        ContactCipher $cipher,
        AuditLogger $auditLogger,
        ?CompanyProfileValidator $validator = null,
        ?callable $clock = null
    ) {
        $this->pdo = $pdo;
        $this->gateway = $gateway;
        $this->companyGateway = $companyGateway;
        $this->cipher = $cipher;
        $this->auditLogger = $auditLogger;
        $this->validator = $validator ?: new CompanyProfileValidator();
        $this->clock = $clock ?: static function (): int {
            return time();
        };
    }

    public function get(TenantContext $context): array
    {
        $company = $this->companyGateway->find($context);
        if ($company === null) {
            throw new CompanyException(ApiErrorCode::NOT_FOUND, '企业资料不存在', 404);
        }
        $rows = $this->gateway->list('company_profiles', $context, [], 1);
        $row = $rows[0] ?? null;
        return $this->present($company, is_array($row) ? $row : null, $context);
    }

    public function save(array $input, TenantContext $context, string $ip = ''): array
    {
        $validated = $this->validator->validate($input);
        $companyId = (int) $context->companyId();
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $company = $this->companyGateway->findForUpdate($context);
            if ($company === null) {
                throw new CompanyException(ApiErrorCode::NOT_FOUND, 'Company profile not found.', 404);
            }
            $now = (int) call_user_func($this->clock);
            $existingRows = $this->gateway->list('company_profiles', $context, [], 1);
            $existing = $existingRows[0] ?? null;
            $current = $this->present(
                $company,
                is_array($existing) ? $existing : null,
                $context
            );
            if ($existing !== null && $this->same($current, $validated)) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return $current;
            }

            $contactsChanged = !$this->contactsSame($current['contacts'], $validated['contacts']);
            $changedFields = $this->changedFields($current, $validated, $existing === null);
            $profileData = [
                'brand' => $validated['brand'],
                'industry' => $validated['industry'],
                'products_json' => json_encode(
                    $validated['products_services'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'website' => $validated['website'],
                'introduction' => $validated['introduction'],
                'contacts_ciphertext' => $this->cipher->encrypt($validated['contacts'], $companyId),
                'updatetime' => $now,
            ];
            if (!$this->companyGateway->updateName($validated['company_name'], $now, $context)) {
                throw new CompanyException(ApiErrorCode::NOT_FOUND, '企业资料不存在', 404);
            }
            if ($existing === null) {
                $profileData['createtime'] = $now;
                $profileId = $this->gateway->insert('company_profiles', $profileData, $context);
            } else {
                $profileId = (int) $existing['id'];
                if (!$this->gateway->update('company_profiles', $profileId, $profileData, $context, $ip)) {
                    throw new CompanyException(ApiErrorCode::NOT_FOUND, '企业资料不存在', 404);
                }
            }
            $this->auditLogger->record(
                'company_profile_saved',
                $context,
                'company_profile',
                (string) $profileId,
                $ip,
                [
                    'operation' => $existing === null ? 'created' : 'updated',
                    'changed_fields' => $changedFields,
                    'contacts_modified' => $contactsChanged,
                ]
            );
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $this->get($context);
    }

    public function context(TenantContext $context): CompanyContext
    {
        $profile = $this->get($context);
        $profile['company_id'] = (int) $context->companyId();
        return CompanyContext::fromProfile($profile);
    }

    private function present(array $company, ?array $profile, TenantContext $context): array
    {
        $products = [];
        $contacts = ['mobile' => null, 'email' => null];
        if ($profile !== null) {
            $decoded = json_decode((string) ($profile['products_json'] ?? '[]'), true);
            if (is_array($decoded)) {
                $products = array_values($decoded);
            }
            $ciphertext = (string) ($profile['contacts_ciphertext'] ?? '');
            if ($ciphertext !== '') {
                $contacts = $this->cipher->decrypt($ciphertext, (int) $context->companyId());
            }
        }
        $base = [
            'company_id' => (int) $context->companyId(),
            'company_name' => (string) $company['name'],
            'brand' => $this->nullable($profile['brand'] ?? null),
            'industry' => $this->nullable($profile['industry'] ?? null),
            'products_services' => $products,
            'website' => $this->nullable($profile['website'] ?? null),
            'introduction' => $this->nullable($profile['introduction'] ?? null),
            'contacts' => $contacts,
        ];
        $computed = CompanyContext::fromProfile($base)->toArray();
        return [
            'company_name' => $base['company_name'],
            'brand' => $base['brand'],
            'industry' => $base['industry'],
            'products_services' => $base['products_services'],
            'website' => $base['website'],
            'introduction' => $base['introduction'],
            'contacts' => $contacts,
            'profile_completeness' => $computed['profile_completeness'],
            'missing_fields' => $computed['missing_fields'],
            'ai_ready' => $computed['ai_ready'],
            'updated_at' => $profile === null ? null : (int) $profile['updatetime'],
        ];
    }

    private function same(array $current, array $validated): bool
    {
        foreach ([
            'company_name',
            'brand',
            'industry',
            'products_services',
            'website',
            'introduction',
        ] as $field) {
            if ($current[$field] !== $validated[$field]) {
                return false;
            }
        }
        return $this->contactsSame($current['contacts'], $validated['contacts']);
    }

    private function contactsSame(array $current, array $next): bool
    {
        return ($current['mobile'] ?? null) === ($next['mobile'] ?? null)
            && ($current['email'] ?? null) === ($next['email'] ?? null);
    }

    private function changedFields(array $current, array $validated, bool $created): array
    {
        $changed = [];
        foreach ([
            'company_name',
            'brand',
            'industry',
            'products_services',
            'website',
            'introduction',
        ] as $field) {
            if ($created || $current[$field] !== $validated[$field]) {
                $changed[] = $field;
            }
        }
        return $changed;
    }

    private function nullable($value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
