<?php

declare(strict_types=1);

namespace app\common\geo\visibility;

final class MockVisibilityProvider implements VisibilityProvider
{
    private string $rawAnswer;
    private array $citations;
    private array $evidence;

    public function __construct(string $rawAnswer, array $citations = [], array $evidence = [])
    {
        $this->rawAnswer = trim($rawAnswer);
        $this->citations = $citations;
        $this->evidence = $evidence;
    }

    public function providerCode(): string { return 'mock'; }

    public function query(VisibilityRequest $request): VisibilityResponse
    {
        $evidence = $this->evidence;
        $evidence['query_mode'] = 'portfolio_demo';
        return new VisibilityResponse(
            $this->rawAnswer,
            $this->citations,
            $evidence,
            $this->providerCode(),
            'mock-' . substr(hash('sha256', $request->companyId() . ':' . $request->question()), 0, 24)
        );
    }
}
