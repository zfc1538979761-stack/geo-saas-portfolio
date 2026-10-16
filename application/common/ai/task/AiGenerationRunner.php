<?php

declare(strict_types=1);

namespace app\common\ai\task;

use app\common\ai\AiCostCalculator;
use app\common\ai\AiProvider;
use app\common\ai\ArticleOutputParser;
use app\common\ai\exception\AiProviderException;
use app\common\job\RetryableJobException;
use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use PDO;
use Throwable;

final class AiGenerationRunner
{
    private PDO $pdo;
    private TenantDataGateway $gateway;
    private AuditLogger $audit;
    private AiArticleWriter $writer;
    private AiPromptAssembler $assembler;
    private ArticleOutputParser $parser;
    private AiCostCalculator $costs;
    private $providerResolver;
    private string $prefix;
    private array $config;
    private $clock;

    public function __construct(
        PDO $pdo,
        TenantDataGateway $gateway,
        AuditLogger $audit,
        AiArticleWriter $writer,
        AiPromptAssembler $assembler,
        ArticleOutputParser $parser,
        AiCostCalculator $costs,
        callable $providerResolver,
        string $prefix,
        array $config,
        ?callable $clock = null
    ) {
        if (preg_match('/^[A-Za-z0-9_]*$/D', $prefix) !== 1) { throw new AiTaskException('Database prefix is invalid.', 500); }
        $this->pdo = $pdo; $this->gateway = $gateway; $this->audit = $audit; $this->writer = $writer;
        $this->assembler = $assembler; $this->parser = $parser; $this->costs = $costs;
        $this->providerResolver = $providerResolver; $this->prefix = $prefix; $this->config = $config;
        $this->clock = $clock ?: static fn(): int => time();
    }

    public function run(int $jobId, TenantContext $context): void
    {
        $jobId = $this->positiveId($jobId);
        $job = $this->claim($jobId, $context);
        if ($job === null) { return; }
        $attempt = (int) $job['attempts'];
        try {
            $snapshot = json_decode((string) $job['input_snapshot_json'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot)) { throw new AiProviderException('AI task snapshot is invalid.', 'configuration', false); }
            $provider = call_user_func($this->providerResolver, (string) $job['provider_code']);
            if (!$provider instanceof AiProvider || $provider->providerCode() !== $job['provider_code']) {
                throw new AiProviderException('AI Provider configuration is invalid.', 'configuration', false);
            }
            $providerId = $this->providerId((string) $job['provider_code']);
            $modelName = $this->configuredModelName((string) $job['provider_code']);
            $callId = $this->gateway->insert('ai_calls', [
                'job_id' => $jobId, 'provider_id' => $providerId, 'provider_code' => $job['provider_code'],
                'model_name' => $modelName, 'purpose' => 'article_generation', 'attempt_no' => $attempt,
                'status' => 'running', 'started_at' => $this->date($this->now()),
            ], $context);
        } catch (Throwable $exception) {
            $this->failWithoutCall($jobId, $exception, $context);
            return;
        }

        try {
            // No database transaction is open while the external model call runs.
            $result = $provider->generate($this->assembler->assemble(
                $snapshot,
                max(1, min(8192, (int) ($this->config['max_output_tokens'] ?? 2048)))
            ));
            $output = $this->parser->parse($result->rawText());
        } catch (AiProviderException $exception) {
            $this->recordCallFailure($callId, $exception, $context);
            $this->handleProviderFailure($job, $exception, $context);
            return;
        } catch (Throwable $exception) {
            $safe = new AiProviderException('AI generation failed unexpectedly.', 'internal', false);
            $this->recordCallFailure($callId, $safe, $context);
            $this->handleProviderFailure($job, $safe, $context);
            return;
        }

        $cost = $this->costs->calculate($result->inputTokens(), $result->outputTokens());
        $completedAt = $this->date($this->now());
        $this->gateway->update('ai_calls', $callId, [
            'model_name' => $result->modelName(), 'input_tokens' => $result->inputTokens(),
            'output_tokens' => $result->outputTokens(), 'cost_amount' => $cost,
            'currency' => $cost === null ? null : $this->costs->currency(),
            'pricing_version' => $cost === null ? null : $this->pricingVersion(),
            'latency_ms' => $result->latencyMs(), 'provider_request_id' => $this->safeRequestId($result->requestId()),
            'status' => 'succeeded', 'error_category' => null, 'completed_at' => $completedAt,
        ], $context);
        try {
            $written = $this->writer->write($snapshot, $output, $callId, $context);
            $this->gateway->update('geo_async_jobs', $jobId, [
                'status' => 'succeeded', 'completed_at' => $completedAt, 'lease_expires_at' => null,
                'result_article_id' => $written['article_id'], 'result_version_id' => $written['version_id'],
                'last_error_code' => null, 'last_error_message' => null, 'updated_at' => $completedAt,
            ], $context);
            $this->audit->record('ai_generation_task_succeeded', $context, 'ai_job', (string) $jobId, '', [
                'ai_call_id' => $callId, 'article_id' => $written['article_id'], 'version_id' => $written['version_id'],
            ]);
        } catch (Throwable $exception) {
            $this->markFailed($jobId, 'persistence', 'AI output could not be saved safely.', $context);
        }
    }

    private function claim(int $jobId, TenantContext $context): ?array
    {
        $now = $this->now();
        $statement = $this->pdo->prepare(
            'UPDATE `' . $this->prefix . 'geo_async_jobs` SET `status`=\'running\','
            . ' `attempts`=`attempts`+1,`reserved_at`=:reserved_at,`started_at`=:started_at,'
            . ' `lease_expires_at`=:lease_expires_at,`updated_at`=:updated_at'
            . ' WHERE `id`=:id AND `company_id`=:company_id AND `job_type`=\'ai_article_generation\''
            . ' AND `status`=\'queued\' AND `available_at`<=:available_at'
        );
        $statement->execute([
            'reserved_at' => $this->date($now), 'started_at' => $this->date($now),
            'lease_expires_at' => $this->date($now + 300), 'updated_at' => $this->date($now),
            'available_at' => $this->date($now),
            'id' => $jobId, 'company_id' => (int) $context->companyId(),
        ]);
        if ($statement->rowCount() !== 1) { return null; }
        $row = $this->gateway->find('geo_async_jobs', $jobId, $context);
        return is_array($row) ? $row : null;
    }

    private function handleProviderFailure(array $job, AiProviderException $exception, TenantContext $context): void
    {
        $jobId = (int) $job['id']; $attempt = (int) $job['attempts']; $max = (int) $job['max_attempts'];
        if ($exception->retryable() && $attempt < $max) {
            $delay = $this->retryDelay($attempt); $now = $this->now();
            $this->gateway->update('geo_async_jobs', $jobId, [
                'status' => 'queued', 'available_at' => $this->date($now + $delay), 'lease_expires_at' => null,
                'last_error_code' => $exception->category(), 'last_error_message' => $this->safeMessage($exception),
                'updated_at' => $this->date($now),
            ], $context);
            throw new RetryableJobException('AI generation requires a bounded retry.');
        }
        $this->markFailed($jobId, $exception->category(), $this->safeMessage($exception), $context);
    }

    private function recordCallFailure(int $callId, AiProviderException $exception, TenantContext $context): void
    {
        $this->gateway->update('ai_calls', $callId, [
            'provider_request_id' => $this->safeRequestId($exception->requestId()),
            'status' => 'failed', 'error_category' => $exception->category(),
            'completed_at' => $this->date($this->now()),
        ], $context);
    }

    private function failWithoutCall(int $jobId, Throwable $exception, TenantContext $context): void
    {
        $category = $exception instanceof AiProviderException ? $exception->category() : 'configuration';
        $message = $exception instanceof AiProviderException ? $this->safeMessage($exception) : 'AI task configuration is invalid.';
        $this->markFailed($jobId, $category, $message, $context);
    }

    private function markFailed(int $jobId, string $category, string $message, TenantContext $context): void
    {
        $now = $this->date($this->now());
        $this->gateway->update('geo_async_jobs', $jobId, [
            'status' => 'failed', 'failed_at' => $now, 'lease_expires_at' => null,
            'last_error_code' => substr($category, 0, 64), 'last_error_message' => substr($message, 0, 255),
            'updated_at' => $now,
        ], $context);
        $this->audit->record('ai_generation_task_failed', $context, 'ai_job', (string) $jobId, '', ['error_category' => $category]);
    }

    private function providerId(string $code): int
    {
        $statement = $this->pdo->prepare('SELECT `id` FROM `' . $this->prefix . 'ai_providers` WHERE `code`=:code AND `status`=\'normal\' LIMIT 1');
        $statement->execute(['code' => $code]); $id = (int) $statement->fetchColumn();
        if ($id < 1) { throw new AiProviderException('AI Provider is not enabled.', 'configuration', false); }
        return $id;
    }

    private function configuredModelName(string $code): string
    {
        if ($code === 'mock') { return 'mock-t07'; }
        $ark = (array) ($this->config['ark'] ?? []);
        $name = trim((string) ($ark['model_name'] ?? $ark['endpoint_id'] ?? ''));
        return $name === '' ? 'unconfigured' : substr($name, 0, 191);
    }

    private function retryDelay(int $attempt): int
    {
        $delays = array_values((array) ($this->config['retry_delays'] ?? [5, 30, 120]));
        $value = $delays[max(0, min(count($delays) - 1, $attempt - 1))] ?? 5;
        return max(0, min(3600, (int) $value));
    }

    private function safeMessage(AiProviderException $exception): string
    {
        $allowed = [
            'authentication' => 'AI Provider authentication failed.', 'authorization' => 'AI Provider access was denied.',
            'rate_limit' => 'AI Provider rate limit was reached.', 'timeout' => 'AI Provider request timed out.',
            'server' => 'AI Provider is temporarily unavailable.', 'configuration' => 'AI Provider configuration is invalid.',
            'invalid_response' => 'AI Provider returned an invalid article.', 'internal' => 'AI generation failed unexpectedly.',
            'empty_response' => 'AI Provider returned empty content.', 'authentication_failed' => 'AI Provider authentication failed.',
            'permission_denied' => 'AI Provider access was denied.', 'rate_limited' => 'AI Provider rate limit was reached.',
            'provider_server_error' => 'AI Provider is temporarily unavailable.', 'network_error' => 'AI Provider network is unavailable.',
        ];
        return $allowed[$exception->category()] ?? 'AI generation failed.';
    }

    private function safeRequestId(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', $value) ?: '', 0, 191);
    }

    private function pricingVersion(): ?string
    {
        $value = trim((string) (($this->config['pricing']['version'] ?? '')));
        return $value === '' ? null : substr($value, 0, 64);
    }

    private function positiveId(int $id): int
    {
        if ($id < 1) { throw new AiTaskException('AI job ID is invalid.'); }
        return $id;
    }

    private function now(): int { return (int) call_user_func($this->clock); }
    private function date(int $timestamp): string { return date('Y-m-d H:i:s', $timestamp); }
}
