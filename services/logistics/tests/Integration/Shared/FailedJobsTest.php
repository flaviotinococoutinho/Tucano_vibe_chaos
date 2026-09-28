<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * A job that runs out of tries leaves the queue and lives on only in failed_jobs.
 * If that write fails, the job is gone: this was sqlite by default, and the label
 * jobs that failed in the first run of the chaos lab vanished without a trace.
 */
#[Group('integration')]
final class FailedJobsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_failed_job_is_kept_in_postgresql_for_queue_retry(): void
    {
        $this->app->make('queue.failer')->log('sqs', 'label-jobs', '{"uuid":"01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d","displayName":"GenerateLabelJob"}', new RuntimeException('The label could not be stored.'));

        self::assertSame(['sqs', 'label-jobs'], [DB::table('failed_jobs')->value('connection'), DB::table('failed_jobs')->value('queue')]);
    }
}
