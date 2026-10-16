<?php

declare(strict_types=1);

namespace app\common\publishing;

final class OrderStateMachine
{
    private const VALID_TRANSITIONS = [
        "funds_frozen"  => ["queued", "cancelled", "manual_review"],
        "queued"        => ["submitted", "rejected", "manual_review"],
        "submitted"     => ["processing", "rejected", "manual_review"],
        "processing"    => ["published", "rejected", "manual_review"],
        "published"     => ["after_sale", "manual_review"],
        "rejected"      => ["manual_review"],
        "cancelled"     => ["manual_review"],
        "after_sale"    => ["refunded", "manual_review"],
        "refunded"      => ["manual_review"],
        "manual_review" => ["funds_frozen", "queued", "submitted", "processing", "published", "rejected", "cancelled", "after_sale", "refunded", "partially_completed"],
        "partially_completed" => ["manual_review"],
    ];

    public function canTransition(string $from, string $to): bool
    {
        $allowed = self::VALID_TRANSITIONS[$from] ?? [];
        return in_array($to, $allowed, true);
    }

    public function validateTransition(string $from, string $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw PublishException::invalidStateTransition($from, $to);
        }
    }

    public static function deriveOrderStatus(array $itemStatuses): string
    {
        if (empty($itemStatuses)) { return "funds_frozen"; }
        $unique = array_unique($itemStatuses);
        if (in_array("manual_review", $unique, true)) { return "manual_review"; }
        if (count($unique) === 1) { return $unique[0]; }
        
        // Check if all are terminals
        $terminals = ["published", "rejected", "cancelled", "refunded"];
        $allTerminal = true;
        foreach ($unique as $s) { if (!in_array($s, $terminals, true)) { $allTerminal = false; break; } }
        if ($allTerminal) { return "partially_completed"; }
        
        // Mixed: use highest priority including all states
        $priority = ["funds_frozen" => 0, "queued" => 1, "submitted" => 2, "processing" => 3, "published" => 10, "rejected" => 10, "cancelled" => 10, "refunded" => 10, "after_sale" => 10];
        $maxPriority = -1; $result = "funds_frozen";
        foreach ($unique as $s) { $p = $priority[$s] ?? -1; if ($p > $maxPriority) { $maxPriority = $p; $result = $s; } }
        return $result;
    }

    public static function canCancelOrder(array $itemStatuses): bool
    {
        foreach ($itemStatuses as $status) { if ($status !== "funds_frozen") { return false; } }
        return true;
    }

    public static function getFundsOperation(string $toStatus): ?string
    {
        return match ($toStatus) {
            "published" => "capture", "rejected" => "release", "cancelled" => "release", "refunded" => "refund",
            default => null,
        };
    }
}