<?php

declare(strict_types=1);

namespace app\common\ai;

final class AiGenerationResult
{
    private string $rawText;
    private string $providerCode;
    private string $modelName;
    private string $requestId;
    private ?int $inputTokens;
    private ?int $outputTokens;
    private int $latencyMs;

    public function __construct(
        string $rawText,
        string $providerCode,
        string $modelName,
        string $requestId,
        ?int $inputTokens,
        ?int $outputTokens,
        int $latencyMs
    ) {
        $this->rawText = $rawText;
        $this->providerCode = $providerCode;
        $this->modelName = $modelName;
        $this->requestId = $requestId;
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->latencyMs = max(0, $latencyMs);
    }

    public function rawText(): string { return $this->rawText; }
    public function providerCode(): string { return $this->providerCode; }
    public function modelName(): string { return $this->modelName; }
    public function requestId(): string { return $this->requestId; }
    public function inputTokens(): ?int { return $this->inputTokens; }
    public function outputTokens(): ?int { return $this->outputTokens; }
    public function latencyMs(): int { return $this->latencyMs; }
}
