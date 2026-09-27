<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use Illuminate\Support\Facades\DB;
use Logistics\Shared\Adapter\Driven\LaravelTransactions;
use Logistics\Shared\Application\Isolation;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * No RefreshDatabase here: its transaction around every test would turn each
 * run into a savepoint, and the retry only happens in an outermost transaction.
 */
#[Group('integration')]
final class LaravelTransactionsTest extends TestCase
{
    private LaravelTransactions $transactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transactions = $this->app->make(LaravelTransactions::class);
    }

    #[Test]
    public function the_isolation_is_the_first_statement_of_the_transaction(): void
    {
        $level = $this->transactions->run(static fn(): string => (string) DB::scalar('SHOW transaction_isolation'), Isolation::Serializable);

        self::assertSame('serializable', $level);
    }

    #[Test]
    public function a_serialization_failure_runs_the_work_again_from_the_start(): void
    {
        $attempts = 0;

        $result = $this->transactions->run(static function () use (&$attempts): string {
            $attempts++;

            return $attempts < 3 ? throw self::serializationFailure() : 'committed';
        }, Isolation::Serializable);

        self::assertSame(['committed', 3], [$result, $attempts]);
    }

    #[Test]
    public function it_gives_up_after_the_attempts_of_the_isolation(): void
    {
        $attempts = 0;
        $this->expectException(PDOException::class);

        try {
            $this->transactions->run(static function () use (&$attempts): never {
                $attempts++;

                throw self::serializationFailure();
            }, Isolation::Serializable);
        } finally {
            self::assertSame(Isolation::Serializable->attempts(), $attempts);
        }
    }

    #[Test]
    public function read_committed_runs_the_work_once(): void
    {
        $attempts = 0;
        $this->expectException(PDOException::class);

        try {
            $this->transactions->run(static function () use (&$attempts): never {
                $attempts++;

                throw self::serializationFailure();
            });
        } finally {
            self::assertSame(1, $attempts);
        }
    }

    #[Test]
    public function other_failures_are_not_retried(): void
    {
        $attempts = 0;
        $this->expectException(RuntimeException::class);

        try {
            $this->transactions->run(static function () use (&$attempts): never {
                $attempts++;

                throw new RuntimeException('not about serialization');
            }, Isolation::Serializable);
        } finally {
            self::assertSame(1, $attempts);
        }
    }

    #[Test]
    public function a_call_inside_another_is_a_savepoint_that_rolls_back_alone(): void
    {
        DB::statement('CREATE TEMPORARY TABLE savepoint_probe (step text)');

        $this->transactions->run(function (): void {
            DB::insert("INSERT INTO savepoint_probe VALUES ('outer')");
            try {
                $this->transactions->run(static function (): never {
                    DB::insert("INSERT INTO savepoint_probe VALUES ('inner')");

                    throw new RuntimeException('the inner work fails');
                });
            } catch (RuntimeException) {
                // The outer work goes on without what the inner one did.
            }
        });

        self::assertSame(['outer'], DB::table('savepoint_probe')->pluck('step')->all());
        DB::statement('DROP TABLE savepoint_probe');
    }

    private static function serializationFailure(): PDOException
    {
        $failure = new PDOException('could not serialize access due to read/write dependencies among transactions');
        $failure->errorInfo = ['40001', 7, 'could not serialize access'];

        return $failure;
    }
}
