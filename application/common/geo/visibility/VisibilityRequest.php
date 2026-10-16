<?php

declare(strict_types=1);

namespace app\common\geo\visibility;

use InvalidArgumentException;

final class VisibilityRequest
{
    private int $companyId;
    private string $brand;
    private string $question;
    private string $platform;
    private string $model;
    private array $context;

    public function __construct(
        int $companyId,
        string $brand,
        string $question,
        string $platform,
        string $model,
        array $context = []
    ) {
        $brand = trim($brand);
        $question = trim($question);
        $platform = trim($platform);
        $model = trim($model);
        if ($companyId < 1 || $brand === '' || $question === '' || $platform === '' || $model === '') {
            throw new InvalidArgumentException('Visibility request is incomplete.');
        }
        if (strlen($brand) > 191 || strlen($question) > 4000 || strlen($platform) > 64 || strlen($model) > 191) {
            throw new InvalidArgumentException('Visibility request exceeds supported limits.');
        }
        $this->companyId = $companyId;
        $this->brand = $brand;
        $this->question = $question;
        $this->platform = $platform;
        $this->model = $model;
        $this->context = $context;
    }

    public function companyId(): int { return $this->companyId; }
    public function brand(): string { return $this->brand; }
    public function question(): string { return $this->question; }
    public function platform(): string { return $this->platform; }
    public function model(): string { return $this->model; }
    public function context(): array { return $this->context; }
}
