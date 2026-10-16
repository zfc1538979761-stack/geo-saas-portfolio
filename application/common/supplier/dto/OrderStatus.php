<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

final class OrderStatus
{
    public const QUEUED = 'queued';
    public const SUBMITTED = 'submitted';
    public const PROCESSING = 'processing';
    public const PUBLISHED = 'published';
    public const REJECTED = 'rejected';
    public const REFUNDED = 'refunded';
    public const MANUAL_REVIEW = 'manual_review';
    public const UNKNOWN = 'unknown';

    private function __construct()
    {
    }
}
