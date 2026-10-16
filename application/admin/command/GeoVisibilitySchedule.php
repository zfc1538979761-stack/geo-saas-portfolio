<?php

declare(strict_types=1);

namespace app\admin\command;

use app\common\geo\GeoVisibilityRuntimeFactory;
use think\console\Command;
use think\console\Input;
use think\console\Output;

final class GeoVisibilitySchedule extends Command
{
    protected function configure()
    {
        $this->setName('geo:visibility:schedule')
            ->setDescription('Dispatch due T19 GEO visibility checks without executing paid media actions.');
    }

    protected function execute(Input $input, Output $output)
    {
        $outcomes = GeoVisibilityRuntimeFactory::scheduler()->scheduleDue(time(), [GeoVisibilityRuntimeFactory::class, 'contextForPlan']);
        $dispatched = count(array_filter($outcomes, static fn(array $item): bool => ($item['status'] ?? '') === 'dispatched'));
        $pending = count($outcomes) - $dispatched;
        if ($pending > 0) {
            $output->warning('Visibility schedule incomplete: dispatched=' . $dispatched . ' pending_or_unavailable=' . $pending);
            return 1;
        }
        $output->info('PASS: visibility schedule dispatched jobs=' . $dispatched);
        return 0;
    }
}
