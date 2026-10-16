<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

final class SupplierCapabilities
{
    private bool $supportsChannels;
    private bool $supportsMediaList;
    private bool $supportsMediaChanges;
    private bool $supportsOrderSubmit;
    private bool $supportsOrderQuery;
    private bool $supportsCallback;
    private bool $supportsCancel;
    private bool $supportsRefund;

    public function __construct(array $flags = [])
    {
        $this->supportsChannels = (bool) ($flags['supports_channels'] ?? false);
        $this->supportsMediaList = (bool) ($flags['supports_media_list'] ?? false);
        $this->supportsMediaChanges = (bool) ($flags['supports_media_changes'] ?? false);
        $this->supportsOrderSubmit = (bool) ($flags['supports_order_submit'] ?? false);
        $this->supportsOrderQuery = (bool) ($flags['supports_order_query'] ?? false);
        $this->supportsCallback = (bool) ($flags['supports_callback'] ?? false);
        $this->supportsCancel = (bool) ($flags['supports_cancel'] ?? false);
        $this->supportsRefund = (bool) ($flags['supports_refund'] ?? false);
    }

    public function supportsChannels(): bool { return $this->supportsChannels; }
    public function supportsMediaList(): bool { return $this->supportsMediaList; }
    public function supportsMediaChanges(): bool { return $this->supportsMediaChanges; }
    public function supportsOrderSubmit(): bool { return $this->supportsOrderSubmit; }
    public function supportsOrderQuery(): bool { return $this->supportsOrderQuery; }
    public function supportsCallback(): bool { return $this->supportsCallback; }
    public function supportsCancel(): bool { return $this->supportsCancel; }
    public function supportsRefund(): bool { return $this->supportsRefund; }

    public function toArray(): array
    {
        return [
            'supports_channels' => $this->supportsChannels,
            'supports_media_list' => $this->supportsMediaList,
            'supports_media_changes' => $this->supportsMediaChanges,
            'supports_order_submit' => $this->supportsOrderSubmit,
            'supports_order_query' => $this->supportsOrderQuery,
            'supports_callback' => $this->supportsCallback,
            'supports_cancel' => $this->supportsCancel,
            'supports_refund' => $this->supportsRefund,
        ];
    }
}
