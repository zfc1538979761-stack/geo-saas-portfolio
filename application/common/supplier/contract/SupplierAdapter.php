<?php

declare(strict_types=1);

namespace app\common\supplier\contract;

use app\common\supplier\dto\CallbackEnvelope;
use app\common\supplier\dto\MediaChangeQuery;
use app\common\supplier\dto\MediaChangePage;
use app\common\supplier\dto\MediaPage;
use app\common\supplier\dto\MediaQuery;
use app\common\supplier\dto\OrderQuery;
use app\common\supplier\dto\OrderResult;
use app\common\supplier\dto\OrderSubmitRequest;
use app\common\supplier\dto\OrderSubmitResult;
use app\common\supplier\dto\ParsedCallback;
use app\common\supplier\dto\SupplierCapabilities;

interface SupplierAdapter
{
    public function supplierCode(): string;

    public function capabilities(): SupplierCapabilities;

    /** @return Channel[] */
    public function listChannels(): array;

    public function fetchMedia(MediaQuery $query): MediaPage;

    public function queryMediaChanges(MediaChangeQuery $query): MediaChangePage;

    public function submitOrder(OrderSubmitRequest $request): OrderSubmitResult;

    public function queryOrder(OrderQuery $query): OrderResult;

    public function parseCallback(CallbackEnvelope $envelope): ParsedCallback;
}
