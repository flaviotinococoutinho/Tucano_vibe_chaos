<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Http;

use Commerce\Ordering\Application\Port\Driving\ForListingOrders;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Error\InvalidOrder;
use Commerce\Ordering\Domain\Error\OrderListUnavailable;
use Commerce\Ordering\Domain\Store\StoreSlug;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * GET /v1/stores/{store}/customers/{customerId}/orders: the customer's list of UC-ORD-05 in
 * that store, newest first, from the read model, which may lag the orders by a few seconds.
 * 503 with Retry-After when the list is out of reach. Only the BFF calls it, on the internal
 * network: the edge closes the store routes (ADR 0030, ADR 0031).
 */
final readonly class ListOrdersController
{
    public function __construct(private ForListingOrders $orders, private LoggerInterface $logger) {}

    public function __invoke(ListOrdersRequest $request, string $store, string $customerId): JsonResponse
    {
        try {
            return new JsonResponse($this->orders->listOrders(self::store($store), self::customer($customerId), $request->page())->toArray());
        } catch (OrderListUnavailable $unavailable) {
            // An HTTP exception is an answer, not an incident, so nothing else would log the outage.
            $this->logger->warning('Order list out of reach: {cause}', ['cause' => $unavailable->getPrevious()?->getMessage()]);

            throw new ServiceUnavailableHttpException($unavailable->retryAfterSeconds, $unavailable->getMessage(), $unavailable);
        }
    }

    /** A valid slug nobody sells in has an empty list; a slug that cannot exist has no list at all. */
    private static function store(string $store): StoreSlug
    {
        try {
            return StoreSlug::of($store);
        } catch (InvalidOrder) {
            throw new NotFoundHttpException(sprintf('Store %s does not exist.', $store));
        }
    }

    /** A valid id nobody used has an empty list; an id that cannot exist has no list at all. */
    private static function customer(string $customerId): CustomerId
    {
        try {
            return CustomerId::fromString($customerId);
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException(sprintf('Customer %s does not exist.', $customerId));
        }
    }
}
