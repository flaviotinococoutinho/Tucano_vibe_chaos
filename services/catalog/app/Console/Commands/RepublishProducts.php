<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Logging\LogContext;
use App\Services\ProductPublisher;
use App\Services\ProductService;
use Illuminate\Console\Command;
use Ramsey\Uuid\Uuid;
use Tucano\Messaging\Kafka\DeliveryFailed;

/**
 * Closes the dual write gap: when Kafka failed after a commit, the topic misses
 * the latest version of a product until its current state goes out again.
 */
final class RepublishProducts extends Command
{
    /** @var string */
    protected $signature = 'catalog:republish {--sku= : Republish only this product}';

    /** @var string */
    protected $description = 'Publish the snapshot of every active and discontinued product again';

    public function handle(ProductService $products, LogContext $logContext): int
    {
        // One correlation id for the whole run, in the logs and in every event.
        $logContext->add(LogContext::CORRELATION_ID, Uuid::uuid7()->toString());
        $sku = $this->option('sku');
        $sku = is_string($sku) && $sku !== '' ? $sku : null;

        try {
            $published = $products->republish($sku);
        } catch (DeliveryFailed $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        if ($sku !== null && $published === 0) {
            $this->error(sprintf('No active or discontinued product has the SKU %s.', $sku));

            return self::FAILURE;
        }
        $this->info(sprintf('Republished %d product snapshots to %s.', $published, ProductPublisher::TOPIC));

        return self::SUCCESS;
    }
}
