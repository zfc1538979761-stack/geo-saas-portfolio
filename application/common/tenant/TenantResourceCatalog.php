<?php

declare(strict_types=1);

namespace app\common\tenant;

final class TenantResourceCatalog
{
    public static function definitions(): array
    {
        return [
            'media_favorites' => [
                'table' => 'media_favorites',
                'writable' => [
                    'media_resource_id', 'source_catalog', 'created_at', 'updated_at',
                ],
                'sortable' => ['source_catalog', 'created_at', 'updated_at'],
            ],
            'company_discovery_runs' => [
                'table' => 'company_discovery_runs',
                'writable' => [
                    'company_name', 'region', 'website', 'unified_social_credit_code',
                    'input_snapshot_json', 'provider_code', 'provider_request_id', 'status',
                    'error_category', 'external_code', 'network_executed', 'candidate_count',
                    'created_by', 'created_at', 'completed_at',
                ],
                'sortable' => ['status', 'created_at', 'completed_at'],
            ],
            'company_discovery_candidates' => [
                'table' => 'company_discovery_candidates',
                'writable' => [
                    'run_id', 'external_key', 'name', 'region', 'website',
                    'unified_social_credit_code', 'public_summary', 'status',
                    'confirmed_by', 'confirmed_at', 'created_at',
                ],
                'searchable' => ['name', 'region', 'public_summary'],
                'sortable' => ['name', 'region', 'status', 'created_at'],
            ],
            'company_candidate_sources' => [
                'table' => 'company_candidate_sources',
                'writable' => [
                    'candidate_id', 'source_key', 'source_url', 'source_title',
                    'public_excerpt', 'published_at', 'fetched_at',
                ],
                'searchable' => ['source_title', 'public_excerpt'],
                'sortable' => ['source_title', 'fetched_at'],
            ],
            'company_profile_drafts' => [
                'table' => 'company_profile_drafts',
                'writable' => [
                    'candidate_id', 'status', 'profile_baseline_json', 'draft_snapshot_json',
                    'inference_json', 'ai_provider_code', 'ai_model_name', 'ai_request_id',
                    'confirmed_snapshot_json', 'confirmed_by', 'confirmed_at',
                    'knowledge_item_id', 'created_at', 'updated_at',
                ],
                'sortable' => ['status', 'created_at', 'updated_at'],
            ],
            'company_profile_draft_facts' => [
                'table' => 'company_profile_draft_facts',
                'writable' => [
                    'draft_id', 'field_key', 'value_json', 'fact_type', 'status',
                    'source_ids_json', 'created_at', 'updated_at',
                ],
                'sortable' => ['field_key', 'status', 'created_at', 'updated_at'],
            ],
            'company_profiles' => [
                'table' => 'company_profiles',
                'writable' => [
                    'brand',
                    'industry',
                    'products_json',
                    'website',
                    'introduction',
                    'contacts_ciphertext',
                    'createtime',
                    'updatetime',
                ],
            ],
            'keywords' => [
                'table' => 'keywords',
                'writable' => [
                    'keyword',
                    'status',
                    'created_by',
                    'updated_by',
                    'createtime',
                    'updatetime',
                    'deletetime',
                ],
                'searchable' => ['keyword'],
                'sortable' => ['keyword', 'status', 'createtime', 'updatetime'],
            ],
            'questions' => [
                'table' => 'questions',
                'writable' => [
                    'keyword_id',
                    'text',
                    'text_hash',
                    'source',
                    'generation_batch_id',
                    'status',
                    'reviewed_by',
                    'reviewed_at',
                    'created_by',
                    'updated_by',
                    'createtime',
                    'updatetime',
                    'deletetime',
                ],
                'searchable' => ['text'],
                'sortable' => ['text', 'status', 'createtime', 'updatetime'],
            ],
            'oss_file_indexes' => [
                'table' => 'oss_file_indexes',
                'writable' => [
                    'provider', 'remote_file_id', 'object_key', 'location_index', 'original_name',
                    'safe_name', 'extension', 'declared_mime', 'detected_mime', 'size_bytes',
                    'sha256', 'status', 'metadata_json', 'created_by', 'updated_by',
                    'createtime', 'updatetime', 'deletetime',
                ],
                'searchable' => ['original_name', 'safe_name'],
                'sortable' => ['original_name', 'extension', 'size_bytes', 'status', 'createtime', 'updatetime'],
            ],
            'knowledge_items' => [
                'table' => 'knowledge_items',
                'writable' => [
                    'type', 'title', 'content', 'oss_file_index_id', 'status', 'created_by',
                    'updated_by', 'createtime', 'updatetime', 'deletetime',
                ],
                'searchable' => ['title', 'content'],
                'sortable' => ['title', 'type', 'status', 'createtime', 'updatetime'],
            ],
            'prompt_templates' => [
                'table' => 'prompt_templates',
                'writable' => [
                    'logical_key', 'name', 'scene', 'version_no', 'content', 'status',
                    'created_by', 'updated_by', 'createtime', 'updatetime', 'deletetime',
                ],
                'searchable' => ['name', 'scene', 'content'],
                'sortable' => ['name', 'scene', 'version_no', 'status', 'createtime', 'updatetime'],
            ],
            'articles' => [
                'table' => 'articles',
                'writable' => [
                    'keyword_id', 'question_id', 'title', 'current_version_id', 'status',
                    'created_by', 'updated_by', 'createtime', 'updatetime', 'deletetime',
                ],
                'searchable' => ['title'],
                'sortable' => ['title', 'status', 'createtime', 'updatetime'],
            ],
            'article_versions' => [
                'table' => 'article_versions',
                'writable' => [
                    'article_id', 'version_no', 'title_snapshot', 'content', 'source',
                    'prompt_template_id', 'ai_call_id', 'geo_answer_version_id', 'created_by', 'createtime',
                ],
                'searchable' => ['title_snapshot', 'content'],
                'sortable' => ['version_no', 'createtime'],
            ],
            'article_version_knowledge' => [
                'table' => 'article_version_knowledge',
                'writable' => [
                    'article_version_id', 'knowledge_item_id', 'knowledge_snapshot_json', 'createtime',
                ],
                'sortable' => ['createtime'],
            ],
            'geo_async_jobs' => [
                'table' => 'geo_async_jobs',
                'writable' => [
                    'actor_id', 'queue', 'job_type', 'provider_code', 'subject_type', 'subject_id',
                    'payload_json', 'input_snapshot_json', 'status', 'attempts', 'max_attempts',
                    'idempotency_key', 'available_at', 'reserved_at', 'started_at',
                    'lease_expires_at', 'completed_at', 'failed_at', 'cancelled_at',
                    'result_article_id', 'result_version_id', 'last_error_code',
                    'last_error_message', 'created_at', 'updated_at',
                ],
                'sortable' => ['status', 'available_at', 'created_at', 'updated_at'],
            ],
            'ai_calls' => [
                'table' => 'ai_calls',
                'writable' => [
                    'job_id', 'provider_id', 'provider_code', 'model_name', 'purpose',
                    'attempt_no', 'input_tokens', 'output_tokens', 'cost_amount', 'currency',
                    'pricing_version', 'latency_ms', 'provider_request_id', 'status',
                    'error_category', 'started_at', 'completed_at',
                ],
                'sortable' => ['attempt_no', 'status', 'started_at', 'completed_at'],
            ],
            'geo_question_generation_batches' => [
                'table' => 'geo_question_generation_batches',
                'writable' => [
                    'job_id', 'ai_call_id', 'provider_code', 'model_name', 'provider_request_id',
                    'input_snapshot_json', 'generated_count', 'duplicate_count', 'status',
                    'error_category', 'created_by', 'created_at', 'completed_at',
                ],
                'sortable' => ['status', 'created_at', 'completed_at'],
            ],
            'geo_answer_versions' => [
                'table' => 'geo_answer_versions',
                'writable' => [
                    'question_id', 'keyword_id', 'version_no', 'answer_text', 'recommendation_reason',
                    'facts_json', 'evidence_json', 'profile_snapshot_json', 'knowledge_snapshot_json',
                    'job_id', 'ai_call_id', 'provider_code', 'model_name', 'provider_request_id',
                    'source', 'status', 'created_by', 'reviewed_by', 'created_at', 'reviewed_at',
                ],
                'searchable' => ['answer_text', 'recommendation_reason'],
                'sortable' => ['version_no', 'status', 'created_at', 'reviewed_at'],
            ],
            'geo_visibility_plans' => [
                'table' => 'geo_visibility_plans',
                  'writable' => [
                      'question_id', 'baseline_result_id', 'platform', 'model_name', 'provider_code', 'interval_days',
                    'timezone', 'enabled', 'next_run_at', 'last_run_at', 'config_version',
                    'created_by', 'updated_by', 'created_at', 'updated_at',
                ],
                'sortable' => ['enabled', 'next_run_at', 'last_run_at', 'updated_at'],
            ],
            'geo_visibility_checks' => [
                'table' => 'geo_visibility_checks',
                'writable' => [
                    'plan_id', 'question_id', 'job_id', 'question_snapshot', 'brand_snapshot',
                    'check_type', 'provider_code', 'platform',
                    'model_name', 'scheduled_at', 'executed_at', 'status', 'idempotency_key',
                    'error_category', 'external_error_code', 'error_message',
                    'raw_response', 'response_history_json', 'execution_evidence_json', 'created_at', 'updated_at',
                ],
                'sortable' => ['scheduled_at', 'executed_at', 'status', 'created_at'],
            ],
            'geo_visibility_results' => [
                'table' => 'geo_visibility_results',
                'writable' => [
                    'check_id', 'question_id', 'question_snapshot', 'brand_snapshot',
                    'platform', 'model_name', 'detected_at', 'raw_answer',
                    'visibility_status', 'brand_mentioned', 'explicitly_recommended',
                    'recommendation_reason', 'citations_json', 'evidence_json',
                    'classifier_version', 'created_at',
                ],
                'searchable' => ['raw_answer', 'recommendation_reason'],
                'sortable' => ['detected_at', 'visibility_status', 'created_at'],
            ],
            'geo_visibility_comparisons' => [
                'table' => 'geo_visibility_comparisons',
                'writable' => [
                    'previous_result_id', 'current_result_id', 'trend', 'answer_change_summary',
                    'citation_change_json', 'created_at',
                ],
                'sortable' => ['trend', 'created_at'],
            ],
            'geo_optimization_suggestions' => [
                'table' => 'geo_optimization_suggestions',
                'writable' => [
                    'result_id', 'comparison_id', 'job_id', 'ai_call_id', 'provider_code',
                    'model_name', 'provider_request_id', 'missed_question', 'knowledge_gaps_json',
                    'question_directions_json', 'answer_directions_json', 'content_directions_json',
                    'generation_basis_json', 'status', 'created_by', 'reviewed_by',
                    'created_at', 'reviewed_at',
                ],
                'searchable' => ['missed_question'],
                'sortable' => ['status', 'created_at', 'reviewed_at'],
            ],
        ];
    }

    public static function registeredResourceNames(): array
    {
        return array_keys(self::definitions());
    }

    public static function registry(string $prefix): TenantTableRegistry
    {
        if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new TenantException('Tenant catalog prefix is invalid.');
        }
        $registered = [];
        foreach (self::definitions() as $resource => $definition) {
            $registered[$resource] = new TenantTableDefinition(
                $prefix . (string) $definition['table'],
                (array) ($definition['writable'] ?? []),
                (array) ($definition['searchable'] ?? []),
                (array) ($definition['aggregate'] ?? []),
                (array) ($definition['sortable'] ?? [])
            );
        }
        return new TenantTableRegistry($registered);
    }
}
