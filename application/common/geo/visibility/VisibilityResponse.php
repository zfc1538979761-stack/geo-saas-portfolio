<?php

declare(strict_types=1);

namespace app\common\geo\visibility;

use InvalidArgumentException;

final class VisibilityResponse
{
    private string $rawAnswer;
    private array $citations;
    private array $evidence;
    private string $providerCode;
    private string $requestId;

    public function __construct(
        string $rawAnswer,
        array $citations,
        array $evidence,
        string $providerCode,
        string $requestId
    ) {
        $rawAnswer = trim($rawAnswer);
        $providerCode = trim($providerCode);
        if ($rawAnswer === '' || $providerCode === '') {
            throw new InvalidArgumentException('Visibility response is incomplete.');
        }
        foreach ($citations as $citation) {
            if (!is_array($citation) || !isset($citation['url']) || trim((string) $citation['url']) === '') {
                throw new InvalidArgumentException('Visibility citation is invalid.');
            }
        }
        $this->rawAnswer = $rawAnswer;
        $this->citations = array_values($citations);
        $this->evidence = $evidence;
        $this->providerCode = $providerCode;
        $this->requestId = substr(trim($requestId), 0, 191);
    }

    public function rawAnswer(): string { return $this->rawAnswer; }
    public function citations(): array { return $this->citations; }
    public function evidence(): array { return $this->evidence; }
    public function providerCode(): string { return $this->providerCode; }
    public function requestId(): string { return $this->requestId; }
}
