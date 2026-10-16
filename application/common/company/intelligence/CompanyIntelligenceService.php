<?php

declare(strict_types=1);

namespace app\common\company\intelligence;

use app\common\ai\AiProvider;
use app\common\company\CompanyException;
use app\common\company\CompanyProfileService;
use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use PDO;
use Throwable;

final class CompanyIntelligenceService
{
    private const PROFILE_FIELDS = [
        'company_name', 'brand', 'industry', 'products_services', 'website', 'introduction',
    ];
    private const ALL_FIELDS = [
        'company_name', 'brand', 'region', 'website', 'industry', 'main_business',
        'products_services', 'introduction', 'advantages', 'qualifications', 'cases',
    ];
    private const LIST_FIELDS = ['products_services', 'advantages', 'qualifications', 'cases'];

    private PDO $pdo;
    private TenantDataGateway $gateway;
    private CompanyProfileService $profiles;
    private AuditLogger $audit;
    private string $tablePrefix;
    private CompanyProfileDraftOrganizer $organizer;
    private $clock;

    public function __construct(
        PDO $pdo,
        TenantDataGateway $gateway,
        CompanyProfileService $profiles,
        AuditLogger $audit,
        string $tablePrefix,
        ?CompanyProfileDraftOrganizer $organizer = null,
        ?callable $clock = null
    ) {
        $this->pdo = $pdo;
        $this->gateway = $gateway;
        $this->profiles = $profiles;
        $this->audit = $audit;
        if (preg_match('/^[A-Za-z0-9_]*$/D', $tablePrefix) !== 1) {
            throw new CompanyDiscoveryException('configuration_error', '企业识别数据表前缀无效。', 500);
        }
        $this->tablePrefix = $tablePrefix;
        $this->organizer = $organizer ?: new CompanyProfileDraftOrganizer();
        $this->clock = $clock ?: static fn(): string => date('Y-m-d H:i:s.u');
    }

    public function discover(
        array $input,
        CompanyDiscoveryProvider $provider,
        TenantContext $context,
        string $ip = ''
    ): array {
        $this->fields($input, ['company_name', 'region', 'website', 'unified_social_credit_code']);
        $request = new CompanyDiscoveryRequest(
            (string) ($input['company_name'] ?? ''),
            (string) ($input['region'] ?? ''),
            isset($input['website']) ? (string) $input['website'] : null,
            isset($input['unified_social_credit_code']) ? (string) $input['unified_social_credit_code'] : null
        );
        $now = $this->now();
        $runId = $this->gateway->insert('company_discovery_runs', [
            'company_name' => $request->companyName(),
            'region' => $request->region(),
            'website' => $request->website(),
            'unified_social_credit_code' => $request->unifiedSocialCreditCode(),
            'input_snapshot_json' => $this->json($request->toArray()),
            'provider_code' => $provider->providerCode(),
            'provider_request_id' => null,
            'status' => 'running',
            'error_category' => null,
            'external_code' => null,
            'network_executed' => 0,
            'candidate_count' => 0,
            'created_by' => $context->actorId(),
            'created_at' => $now,
            'completed_at' => null,
        ], $context);
        try {
            $result = $provider->discover($request);
        } catch (CompanyDiscoveryException $exception) {
            $this->gateway->update('company_discovery_runs', $runId, [
                'status' => 'failed',
                'error_category' => $exception->category(),
                'external_code' => $exception->externalCode(),
                'network_executed' => $exception->networkExecuted() ? 1 : 0,
                'completed_at' => $this->now(),
            ], $context, $ip);
            $this->audit->record('company_discovery_failed', $context, 'company_discovery_run', (string) $runId, $ip, [
                'category' => $exception->category(),
                'provider_code' => $exception->providerCode(),
                'external_code' => $exception->externalCode(),
                'network_executed' => $exception->networkExecuted(),
            ]);
            throw $exception;
        } catch (Throwable $exception) {
            $this->gateway->update('company_discovery_runs', $runId, [
                'status' => 'failed',
                'error_category' => 'provider_error',
                'external_code' => null,
                'network_executed' => 0,
                'completed_at' => $this->now(),
            ], $context, $ip);
            $this->audit->record('company_discovery_failed', $context, 'company_discovery_run', (string) $runId, $ip, [
                'category' => 'provider_error',
                'provider_code' => $provider->providerCode(),
                'network_executed' => false,
            ]);
            throw new CompanyDiscoveryException(
                'provider_error',
                '企业公开信息发现服务异常，请稍后重试。',
                502,
                $provider->providerCode(),
                null,
                false,
                false
            );
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            foreach ($result->candidates() as $candidate) {
                $candidateId = $this->gateway->insert('company_discovery_candidates', [
                    'run_id' => $runId,
                    'external_key' => $candidate['external_key'],
                    'name' => $candidate['name'],
                    'region' => $candidate['region'],
                    'website' => $candidate['website'],
                    'unified_social_credit_code' => $candidate['unified_social_credit_code'],
                    'public_summary' => $candidate['public_summary'],
                    'status' => 'discovered',
                    'confirmed_by' => null,
                    'confirmed_at' => null,
                    'created_at' => $now,
                ], $context);
                foreach ($candidate['sources'] as $source) {
                    $this->gateway->insert('company_candidate_sources', [
                        'candidate_id' => $candidateId,
                        'source_key' => $source['source_key'],
                        'source_url' => $source['source_url'],
                        'source_title' => $source['source_title'],
                        'public_excerpt' => $source['public_excerpt'],
                        'published_at' => $source['published_at'],
                        'fetched_at' => $now,
                    ], $context);
                }
            }
            $this->gateway->update('company_discovery_runs', $runId, [
                'provider_request_id' => $result->requestId(),
                'status' => 'succeeded',
                'network_executed' => $result->networkExecuted() ? 1 : 0,
                'candidate_count' => count($result->candidates()),
                'completed_at' => $this->now(),
            ], $context, $ip);
            $this->audit->record('company_discovery_completed', $context, 'company_discovery_run', (string) $runId, $ip, [
                'provider_code' => $result->providerCode(),
                'candidate_count' => count($result->candidates()),
                'network_executed' => $result->networkExecuted(),
            ]);
            if ($ownsTransaction) { $this->pdo->commit(); }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            try {
                $this->gateway->update('company_discovery_runs', $runId, [
                    'provider_request_id' => $result->requestId(),
                    'status' => 'failed',
                    'error_category' => 'persistence_error',
                    'external_code' => null,
                    'network_executed' => $result->networkExecuted() ? 1 : 0,
                    'candidate_count' => 0,
                    'completed_at' => $this->now(),
                ], $context, $ip);
                $this->audit->record('company_discovery_failed', $context, 'company_discovery_run', (string) $runId, $ip, [
                    'category' => 'persistence_error',
                    'provider_code' => $result->providerCode(),
                    'network_executed' => $result->networkExecuted(),
                ]);
            } catch (Throwable $ignored) {
            }
            throw new CompanyDiscoveryException(
                'persistence_error',
                '企业候选保存失败，请稍后重试。',
                500,
                $result->providerCode(),
                null,
                false,
                $result->networkExecuted()
            );
        }
        return $this->candidates($runId, $context);
    }

    public function candidates(int $runId, TenantContext $context): array
    {
        $run = $this->gateway->find('company_discovery_runs', $runId, $context);
        if (!is_array($run)) { $this->notFound('企业发现记录不存在。'); }
        $candidates = $this->gateway->list('company_discovery_candidates', $context, ['run_id' => $runId], 100);
        foreach ($candidates as &$candidate) {
            $candidate = $this->presentCandidate($candidate, $context);
            $candidate['ai_candidate'] = ((string) ($run['provider_code'] ?? '')) === 'doubao_chat_fallback';
            $candidate['network_executed'] = (bool) ($run['network_executed'] ?? false);
        }
        unset($candidate);
        return ['run' => $this->presentRun($run), 'candidates' => $candidates];
    }

    public function confirmCandidate(
        int $candidateId,
        AiProvider $provider,
        TenantContext $context,
        string $ip = ''
    ): array {
        if ($this->pdo->inTransaction()) {
            throw new CompanyDiscoveryException('configuration_error', '候选确认不能嵌套在外部事务中。', 500);
        }
        $claimTime = $this->now();
        $candidate = null;
        $sources = [];
        $this->pdo->beginTransaction();
        try {
            $this->lockTenantCompany($context);
            $candidate = $this->gateway->find('company_discovery_candidates', $candidateId, $context);
            if (!is_array($candidate)) { $this->notFound('企业候选不存在。'); }
            $existing = $this->gateway->list('company_profile_drafts', $context, ['candidate_id' => $candidateId], 1);
            if ($existing !== []) {
                $this->pdo->commit();
                return $this->draft((int) $existing[0]['id'], $context);
            }
            if ((string) $candidate['status'] === 'organizing' && $this->claimIsStale($candidate, $claimTime)) {
                $this->gateway->update('company_discovery_candidates', $candidateId, [
                    'status' => 'discovered', 'confirmed_by' => null, 'confirmed_at' => null,
                ], $context, $ip);
                $candidate['status'] = 'discovered';
            }
            if ((string) $candidate['status'] !== 'discovered') {
                throw new CompanyDiscoveryException('candidate_not_confirmable', '企业候选当前不可确认。', 409);
            }
            $siblings = $this->gateway->list('company_discovery_candidates', $context, ['run_id' => (int) $candidate['run_id']], 100);
            foreach ($siblings as $sibling) {
                if ((int) $sibling['id'] !== $candidateId
                    && in_array((string) $sibling['status'], ['organizing', 'confirmed'], true)) {
                    throw new CompanyDiscoveryException('candidate_not_confirmable', '同一次发现已有候选正在整理或已确认。', 409);
                }
            }
            $sources = $this->gateway->list('company_candidate_sources', $context, ['candidate_id' => $candidateId], 100);
            $this->gateway->update('company_discovery_candidates', $candidateId, [
                'status' => 'organizing',
                'confirmed_by' => $context->actorId(),
                'confirmed_at' => $claimTime,
            ], $context, $ip);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }

        try {
            $organized = $this->organizer->organize($candidate, $sources, $provider, $sources === []);
        } catch (Throwable $exception) {
            $this->resetCandidateClaim($candidateId, $claimTime, $context, $ip);
            throw $exception;
        }

        $draftId = 0;
        $this->pdo->beginTransaction();
        try {
            $this->lockTenantCompany($context);
            $candidate = $this->gateway->find('company_discovery_candidates', $candidateId, $context);
            if (!is_array($candidate)) { $this->notFound('企业候选不存在。'); }
            $existing = $this->gateway->list('company_profile_drafts', $context, ['candidate_id' => $candidateId], 1);
            if ($existing !== []) {
                $this->pdo->commit();
                return $this->draft((int) $existing[0]['id'], $context);
            }
            if ((string) $candidate['status'] !== 'organizing'
                || (string) $candidate['confirmed_at'] !== $claimTime) {
                throw new CompanyDiscoveryException('candidate_claim_lost', '企业候选整理状态已变化，请刷新后重试。', 409);
            }
            $baseline = $this->profileSnapshot($this->profiles->get($context));
            $now = $this->now();
            $siblings = $this->gateway->list('company_discovery_candidates', $context, ['run_id' => (int) $candidate['run_id']], 100);
            foreach ($siblings as $sibling) {
                $id = (int) $sibling['id'];
                $this->gateway->update('company_discovery_candidates', $id, [
                    'status' => $id === $candidateId ? 'confirmed' : 'rejected',
                    'confirmed_by' => $id === $candidateId ? $context->actorId() : null,
                    'confirmed_at' => $id === $candidateId ? $claimTime : null,
                ], $context, $ip);
            }
            $draftId = $this->gateway->insert('company_profile_drafts', [
                'candidate_id' => $candidateId,
                'status' => 'discovered',
                'profile_baseline_json' => $this->json($baseline),
                'draft_snapshot_json' => $this->json($organized['draft']),
                'inference_json' => $this->json($organized['inferences']),
                'ai_provider_code' => $organized['provider_code'],
                'ai_model_name' => $organized['model_name'],
                'ai_request_id' => $organized['provider_request_id'],
                'confirmed_snapshot_json' => null,
                'confirmed_by' => null,
                'confirmed_at' => null,
                'knowledge_item_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $context);
            foreach ($organized['facts'] as $fact) {
                $this->gateway->insert('company_profile_draft_facts', [
                    'draft_id' => $draftId,
                    'field_key' => $fact['field_key'],
                    'value_json' => $this->jsonValue($fact['value']),
                    'fact_type' => $fact['fact_type'],
                    'status' => 'discovered',
                    'source_ids_json' => $this->json($fact['source_ids']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $context);
            }
            $this->audit->record('company_candidate_confirmed', $context, 'company_discovery_candidate', (string) $candidateId, $ip, [
                'run_id' => (int) $candidate['run_id'], 'draft_id' => $draftId,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            $this->resetCandidateClaim($candidateId, $claimTime, $context, $ip);
            throw $exception;
        }
        return $this->draft($draftId, $context);
    }

    public function draft(int $draftId, TenantContext $context): array
    {
        $draft = $this->gateway->find('company_profile_drafts', $draftId, $context);
        if (!is_array($draft)) { $this->notFound('企业画像草稿不存在。'); }
        $candidate = $this->gateway->find('company_discovery_candidates', (int) $draft['candidate_id'], $context);
        if (!is_array($candidate)) { $this->notFound('企业候选不存在。'); }
        $facts = $this->gateway->list('company_profile_draft_facts', $context, ['draft_id' => $draftId], 100);
        foreach ($facts as &$fact) {
            $fact = [
                'id' => (int) $fact['id'],
                'field_key' => (string) $fact['field_key'],
                'value' => $this->decode((string) $fact['value_json'], null),
                'fact_type' => (string) $fact['fact_type'],
                'status' => (string) $fact['status'],
                'source_ids' => $this->decode((string) $fact['source_ids_json'], []),
            ];
        }
        unset($fact);
        if ((string) $draft['status'] === 'verified') {
            $facts = array_values(array_filter($facts, static function (array $fact): bool {
                return $fact['status'] === 'verified';
            }));
        }
        $draftData = $this->decode((string) $draft['draft_snapshot_json'], []);
        if ((string) $draft['status'] === 'verified' && $draft['confirmed_snapshot_json'] !== null) {
            $confirmed = $this->decode((string) $draft['confirmed_snapshot_json'], []);
            $draftData = array_merge(
                (array) ($confirmed['knowledge_fields'] ?? []),
                (array) ($confirmed['profile'] ?? [])
            );
        }
        return [
            'id' => (int) $draft['id'],
            'candidate_id' => (int) $draft['candidate_id'],
            'status' => (string) $draft['status'],
            'draft' => $draftData,
            'inferences' => (string) $draft['status'] === 'verified'
                ? []
                : $this->decode((string) $draft['inference_json'], []),
            'facts' => $facts,
            'candidate' => $this->presentCandidate($candidate, $context),
            'knowledge_item_id' => $draft['knowledge_item_id'] === null ? null : (int) $draft['knowledge_item_id'],
            'confirmed_at' => $draft['confirmed_at'],
        ];
    }

    public function confirmDraft(
        int $draftId,
        array $reviewed,
        array $resolutions,
        TenantContext $context,
        string $ip = ''
    ): array {
        $reviewed = $this->reviewed($reviewed);
        $this->fields($resolutions, self::PROFILE_FIELDS);
        foreach ($resolutions as $resolution) {
            if (!in_array($resolution, ['keep_existing', 'use_draft'], true)) {
                throw new CompanyDiscoveryException('invalid_request', '企业画像冲突选择无效。', 422);
            }
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $this->lockTenantCompany($context);
            $draft = $this->gateway->find('company_profile_drafts', $draftId, $context);
            if (!is_array($draft)) { $this->notFound('企业画像草稿不存在。'); }
            if ((string) $draft['status'] === 'verified') {
                if ($ownsTransaction) { $this->pdo->commit(); }
                return $this->draft($draftId, $context);
            }
            if ((string) $draft['status'] !== 'discovered') {
                throw new CompanyDiscoveryException('draft_not_confirmable', '企业画像草稿当前不可确认。', 409);
            }

            $current = $this->profiles->get($context);
            $conflicts = [];
            foreach (self::PROFILE_FIELDS as $field) {
                $next = $reviewed[$field] ?? ($field === 'products_services' ? [] : null);
                $existing = $current[$field] ?? ($field === 'products_services' ? [] : null);
                if (!$this->emptyValue($existing) && $existing !== $next && !isset($resolutions[$field])) {
                    $conflicts[$field] = ['existing' => $existing, 'draft' => $next];
                }
            }
            if ($conflicts !== []) {
                throw new CompanyDiscoveryException(
                    'profile_conflict_requires_resolution',
                    '企业画像与已有确认资料冲突，请逐项选择。',
                    409,
                    null,
                    null,
                    false,
                    false,
                    ['conflicts' => $conflicts]
                );
            }

            $merged = $current;
            $excludedFromKnowledge = [];
            foreach (self::PROFILE_FIELDS as $field) {
                if (($resolutions[$field] ?? null) === 'keep_existing') {
                    $excludedFromKnowledge[$field] = true;
                    continue;
                }
                if (array_key_exists($field, $reviewed)) { $merged[$field] = $reviewed[$field]; }
            }
            $saveInput = [
                'company_name' => $merged['company_name'],
                'brand' => $merged['brand'],
                'industry' => $merged['industry'],
                'products_services' => $merged['products_services'],
                'website' => $merged['website'],
                'introduction' => $merged['introduction'],
                'mobile' => $current['contacts']['mobile'] ?? null,
                'email' => $current['contacts']['email'] ?? null,
            ];
            $candidate = $this->gateway->find('company_discovery_candidates', (int) $draft['candidate_id'], $context);
            if (!is_array($candidate)) { $this->notFound('企业候选不存在。'); }
            $sources = $this->gateway->list('company_candidate_sources', $context, ['candidate_id' => (int) $candidate['id']], 100);
            $existingFacts = $this->gateway->list('company_profile_draft_facts', $context, ['draft_id' => $draftId], 100);
            $byField = [];
            foreach ($existingFacts as $fact) { $byField[(string) $fact['field_key']] = $fact; }
            $knowledgeFields = array_filter($reviewed, static function ($value, string $field) use ($excludedFromKnowledge): bool {
                return !isset($excludedFromKnowledge[$field]) && $value !== null && $value !== '' && $value !== [];
            }, ARRAY_FILTER_USE_BOTH);
            $fieldEvidence = $this->fieldEvidence($knowledgeFields, $byField);
            $knowledgeContent = $this->knowledgeContent($knowledgeFields, $sources, $fieldEvidence);
            $now = $this->now();

            $savedProfile = $this->profiles->save($saveInput, $context, $ip);
            $knowledgeId = $this->gateway->insert('knowledge_items', [
                'type' => 'text',
                'title' => '企业画像（已确认）',
                'content' => $knowledgeContent,
                'oss_file_index_id' => null,
                'status' => 'normal',
                'created_by' => $context->actorId(),
                'updated_by' => $context->actorId(),
                'createtime' => time(),
                'updatetime' => time(),
                'deletetime' => null,
            ], $context);
            foreach ($byField as $field => $fact) {
                $keepExisting = ($resolutions[$field] ?? null) === 'keep_existing';
                $present = array_key_exists($field, $reviewed) && !$this->emptyValue($reviewed[$field]);
                $keepsSource = $present
                    && !$keepExisting
                    && (string) $fact['fact_type'] === 'source_fact'
                    && $this->decode((string) $fact['value_json'], null) === $reviewed[$field];
                $retainsOriginalEvidence = !$present || $keepExisting;
                $this->gateway->update('company_profile_draft_facts', (int) $fact['id'], [
                    'value_json' => $present ? $this->jsonValue($reviewed[$field]) : (string) $fact['value_json'],
                    'fact_type' => $retainsOriginalEvidence
                        ? (string) $fact['fact_type']
                        : ($keepsSource ? 'source_fact' : 'user_input'),
                    'status' => ($present && !$keepExisting) ? 'verified' : 'deleted',
                    'source_ids_json' => ($retainsOriginalEvidence || $keepsSource)
                        ? (string) $fact['source_ids_json']
                        : '[]',
                    'updated_at' => $now,
                ], $context, $ip);
            }
            foreach ($reviewed as $field => $value) {
                if (isset($byField[$field]) || $this->emptyValue($value) || ($resolutions[$field] ?? null) === 'keep_existing') { continue; }
                $this->gateway->insert('company_profile_draft_facts', [
                    'draft_id' => $draftId,
                    'field_key' => $field,
                    'value_json' => $this->jsonValue($value),
                    'fact_type' => 'user_input',
                    'status' => 'verified',
                    'source_ids_json' => '[]',
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $context);
            }
            $confirmedSnapshot = [
                'profile' => $this->profileSnapshot($savedProfile),
                'knowledge_fields' => $knowledgeFields,
                'resolutions' => $resolutions,
            ];
            $this->gateway->update('company_profile_drafts', $draftId, [
                'status' => 'verified',
                'confirmed_snapshot_json' => $this->json($confirmedSnapshot),
                'confirmed_by' => $context->actorId(),
                'confirmed_at' => $now,
                'knowledge_item_id' => $knowledgeId,
                'updated_at' => $now,
            ], $context, $ip);
            $this->audit->record('company_profile_draft_verified', $context, 'company_profile_draft', (string) $draftId, $ip, [
                'candidate_id' => (int) $candidate['id'],
                'knowledge_item_id' => $knowledgeId,
                'conflict_resolutions' => $resolutions,
            ]);
            if ($ownsTransaction) { $this->pdo->commit(); }
        } catch (CompanyException $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw new CompanyDiscoveryException(
                'invalid_request',
                $exception->getMessage(),
                $exception->httpStatus(),
                null,
                null,
                false,
                false,
                $exception->details()
            );
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
        $result = $this->draft($draftId, $context);
        $result['profile'] = $this->profiles->get($context);
        $result['next_step'] = ['view' => 'keywords', 'knowledge_item_id' => $result['knowledge_item_id']];
        return $result;
    }

    public function status(TenantContext $context): array
    {
        $runs = $this->gateway->paginate('company_discovery_runs', $context, [], null, '', 1, 1, 'created_at', 'desc');
        $drafts = $this->gateway->paginate('company_profile_drafts', $context, [], null, '', 1, 1, 'updated_at', 'desc');
        $run = $runs['items'][0] ?? null;
        $draft = $drafts['items'][0] ?? null;
        return [
            'run' => $run === null ? null : $this->presentRun($run),
            'draft' => $draft === null ? null : $this->draft((int) $draft['id'], $context),
        ];
    }

    public function latestVerifiedKnowledgeItemId(TenantContext $context): int
    {
        $page = $this->gateway->paginate(
            'company_profile_drafts',
            $context,
            ['status' => 'verified'],
            null,
            '',
            1,
            1,
            'updated_at',
            'desc'
        );
        $draft = $page['items'][0] ?? null;
        return is_array($draft) && $draft['knowledge_item_id'] !== null
            ? (int) $draft['knowledge_item_id']
            : 0;
    }

    private function presentCandidate(array $candidate, TenantContext $context): array
    {
        $sources = $this->gateway->list('company_candidate_sources', $context, ['candidate_id' => (int) $candidate['id']], 100);
        return [
            'id' => (int) $candidate['id'],
            'run_id' => (int) $candidate['run_id'],
            'name' => (string) $candidate['name'],
            'region' => (string) $candidate['region'],
            'website' => $candidate['website'],
            'unified_social_credit_code' => $candidate['unified_social_credit_code'],
            'public_summary' => (string) $candidate['public_summary'],
            'status' => (string) $candidate['status'],
            'sources' => array_map(static function (array $source): array {
                return [
                    'id' => (int) $source['id'],
                    'source_url' => (string) $source['source_url'],
                    'source_title' => (string) $source['source_title'],
                    'public_excerpt' => (string) $source['public_excerpt'],
                    'published_at' => $source['published_at'],
                ];
            }, $sources),
        ];
    }

    private function presentRun(array $run): array
    {
        return [
            'id' => (int) $run['id'],
            'company_name' => (string) $run['company_name'],
            'region' => (string) $run['region'],
            'status' => (string) $run['status'],
            'provider_code' => (string) $run['provider_code'],
            'error_category' => $run['error_category'],
            'external_code' => $run['external_code'],
            'network_executed' => (bool) $run['network_executed'],
            'candidate_count' => (int) $run['candidate_count'],
        ];
    }

    private function reviewed(array $input): array
    {
        $this->fields($input, self::ALL_FIELDS);
        $output = [];
        foreach ($input as $field => $value) {
            if (in_array($field, self::LIST_FIELDS, true)) {
                $items = array_values(array_filter(array_map(static fn($item): string => trim((string) $item), (array) $value), static fn(string $item): bool => $item !== ''));
                $output[$field] = $items;
                continue;
            }
            $text = trim((string) $value);
            $output[$field] = $text === '' ? null : $text;
        }
        if (empty($output['company_name']) || empty($output['region'])) {
            throw new CompanyDiscoveryException('invalid_request', '确认画像必须包含企业名称和地区。', 422);
        }
        if (isset($output['website']) && $output['website'] !== null && filter_var($output['website'], FILTER_VALIDATE_URL) === false) {
            throw new CompanyDiscoveryException('invalid_request', '官网地址格式不正确。', 422);
        }
        return $output;
    }

    private function knowledgeContent(array $fields, array $sources, array $fieldEvidence): string
    {
        $labels = [
            'company_name' => '企业名称', 'brand' => '品牌', 'region' => '地区', 'website' => '官网',
            'industry' => '行业', 'main_business' => '主营业务', 'products_services' => '产品/服务',
            'introduction' => '企业简介', 'advantages' => '公开优势', 'qualifications' => '公开资质', 'cases' => '公开案例',
        ];
        $sourceById = [];
        foreach ($sources as $source) { $sourceById[(int) $source['id']] = $source; }
        $lines = ['# 企业画像（用户已确认）', ''];
        foreach ($fields as $field => $value) {
            $rendered = is_array($value) ? implode('；', $value) : (string) $value;
            $evidence = [];
            foreach ((array) ($fieldEvidence[$field] ?? []) as $sourceId) {
                if (!isset($sourceById[(int) $sourceId])) { continue; }
                $source = $sourceById[(int) $sourceId];
                $evidence[] = (string) $source['source_title'] . ' ' . (string) $source['source_url'];
            }
            $provenance = $evidence === []
                ? '用户确认补充，无公开来源'
                : '公开来源：' . implode('；', $evidence);
            $lines[] = '- ' . ($labels[$field] ?? $field) . '：' . $rendered . '（' . $provenance . '）';
        }
        $lines[] = '';
        $lines[] = '## 公开来源';
        foreach ($sources as $source) {
            $lines[] = '- ' . (string) $source['source_title'] . '：' . (string) $source['source_url'];
            if (trim((string) $source['public_excerpt']) !== '') {
                $lines[] = '  - 摘要：' . trim((string) $source['public_excerpt']);
            }
        }
        return implode("\n", $lines);
    }

    private function fieldEvidence(array $fields, array $facts): array
    {
        $evidence = [];
        foreach ($fields as $field => $value) {
            $fact = $facts[$field] ?? null;
            $evidence[$field] = is_array($fact)
                && (string) $fact['fact_type'] === 'source_fact'
                && $this->decode((string) $fact['value_json'], null) === $value
                    ? (array) $this->decode((string) $fact['source_ids_json'], [])
                    : [];
        }
        return $evidence;
    }

    private function profileSnapshot(array $profile): array
    {
        $snapshot = [];
        foreach (self::PROFILE_FIELDS as $field) {
            $snapshot[$field] = $profile[$field] ?? ($field === 'products_services' ? [] : null);
        }
        return $snapshot;
    }

    private function claimIsStale(array $candidate, string $now): bool
    {
        $claimedAt = strtotime((string) ($candidate['confirmed_at'] ?? ''));
        $nowAt = strtotime($now);
        return $claimedAt !== false && $nowAt !== false && ($nowAt - $claimedAt) >= 600;
    }

    private function resetCandidateClaim(
        int $candidateId,
        string $claimTime,
        TenantContext $context,
        string $ip
    ): void {
        try {
            $this->pdo->beginTransaction();
            $this->lockTenantCompany($context);
            $candidate = $this->gateway->find('company_discovery_candidates', $candidateId, $context);
            if (is_array($candidate)
                && (string) $candidate['status'] === 'organizing'
                && (string) $candidate['confirmed_at'] === $claimTime) {
                $this->gateway->update('company_discovery_candidates', $candidateId, [
                    'status' => 'discovered', 'confirmed_by' => null, 'confirmed_at' => null,
                ], $context, $ip);
            }
            $this->pdo->commit();
        } catch (Throwable $ignored) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
        }
    }

    private function lockTenantCompany(TenantContext $context): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new CompanyDiscoveryException('configuration_error', '企业画像锁必须在事务中获取。', 500);
        }
        $statement = $this->pdo->prepare(
            'SELECT `id` FROM `' . $this->tablePrefix . 'companies` WHERE `id`=:company_id LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['company_id' => $context->companyId()]);
        if ($statement->fetchColumn() === false) { $this->notFound('企业不存在。'); }
    }

    private function fields(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new CompanyDiscoveryException('invalid_request', '请求包含未知或受保护字段。', 422);
        }
    }

    private function notFound(string $message): void
    {
        throw new CompanyDiscoveryException('not_found', $message, 404);
    }

    private function emptyValue($value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function now(): string { return (string) call_user_func($this->clock); }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function jsonValue($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function decode(string $value, $fallback) { $decoded = json_decode($value, true); return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback; }
}
