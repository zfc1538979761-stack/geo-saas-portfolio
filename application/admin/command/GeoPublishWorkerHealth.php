<?php

declare(strict_types=1);

namespace app\admin\command;

use app\common\publishing\PublishWorkerHealthJob;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\Queue;

final class GeoPublishWorkerHealth extends Command
{
    protected function configure()
    {
        $this->setName('geo:publish-worker:health')
            ->setDescription('Verify geo_publish_submit consumption without contacting a supplier.')
            ->addOption('timeout', null, Option::VALUE_OPTIONAL, 'Seconds to wait for the health job.', 20);
    }

    protected function execute(Input $input, Output $output)
    {
        $timeout = max(1, min(60, (int) $input->getOption('timeout')));
        $token = bin2hex(random_bytes(16));
        $markerPath = PublishWorkerHealthJob::markerPath($token);
        $jobId = Queue::push(
            PublishWorkerHealthJob::class,
            ['token' => $token],
            'geo_publish_submit'
        );
        if ($jobId === false || $jobId === null || $jobId === '') {
            $output->error('FAIL: publish Worker health job could not be queued.');
            return 1;
        }

        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (is_file($markerPath)) {
                $payload = json_decode((string) file_get_contents($markerPath), true);
                @unlink($markerPath);
                if (is_array($payload)
                    && ($payload['token'] ?? '') === $token
                    && ($payload['queue'] ?? '') === 'geo_publish_submit') {
                    $output->info('PASS: geo_publish_submit Worker consumed the isolated health job; supplier calls=0.');
                    return 0;
                }
                $output->error('FAIL: publish Worker health marker was invalid.');
                return 1;
            }
            usleep(100000);
        }

        $output->error('FAIL: geo_publish_submit Worker did not consume the health job before timeout.');
        return 1;
    }
}
