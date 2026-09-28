<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Tucano\Messaging\Kafka\ReceivedMessage;

/**
 * Order events as Commerce publishes them on commerce.orders.v2. The contract
 * test keeps these fixtures in the shape of contracts/events/commerce.order.*,
 * so the handler is tested against what Commerce really sends.
 */
final class OrderEvents
{
    public const string ORDER = '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d';

    public const string PAID_EVENT = '01999a21-0b1c-7d2e-8f3a-4b5c6d7e8f90';

    public const string CANCELLED_EVENT = '01999a22-1c2d-7e3f-9a4b-5c6d7e8f9a01';

    private const string CUSTOMER = '01999a1d-2b3c-7a4b-9c5d-6e7f8a9b0c1d';

    private function __construct() {}

    /** @param array<string, mixed> $data fields to change in the data of the event */
    public static function paid(array $data = [], string $eventId = self::PAID_EVENT): string
    {
        return self::event('tucano.commerce.order.paid', $eventId, [
            'orderId' => self::ORDER,
            'orderNumber' => '97663530295234560',
            'customer' => ['id' => self::CUSTOMER, 'name' => 'Ana Souza', 'email' => 'ana@example.com'],
            'shippingAddress' => [
                'thoroughfare' => ['type' => 'Avenida', 'name' => 'Paulista'],
                'number' => '1000',
                'complement' => 'Apto 12',
                'divisions' => [
                    ['kind' => 'state', 'code' => 'SP', 'name' => 'São Paulo'],
                    ['kind' => 'municipality', 'code' => '3550308', 'name' => 'São Paulo'],
                    ['kind' => 'neighborhood', 'code' => null, 'name' => 'Bela Vista'],
                ],
                'postalCode' => '01310100',
                'latitude' => -23.561414,
                'longitude' => -46.655881,
            ],
            'fulfillmentCenter' => 'GRU1',
            'lines' => [
                ['sku' => 'BOOK-DDD-001', 'name' => 'Domain-Driven Design', 'quantity' => 2],
                ['sku' => 'HOME-MUG-001', 'name' => 'Caneca de cerâmica', 'quantity' => 1],
            ],
            'total' => ['amount' => 42970, 'currency' => 'BRL'],
            ...$data,
        ]);
    }

    /**
     * An address with no complement, no coordinates and no geocodes, all optional in the contract.
     *
     * @return array<string, mixed>
     */
    public static function plainAddress(): array
    {
        return [
            'thoroughfare' => ['type' => 'Rua', 'name' => 'da Bahia'],
            'number' => '1200',
            'complement' => null,
            'divisions' => [
                ['kind' => 'state', 'code' => 'MG', 'name' => 'Minas Gerais'],
                ['kind' => 'municipality', 'code' => null, 'name' => 'Belo Horizonte'],
            ],
            'postalCode' => '30160011',
            'latitude' => null,
            'longitude' => null,
        ];
    }

    /** @param array<string, mixed> $data fields to change in the data of the event */
    public static function cancelled(array $data = [], string $eventId = self::CANCELLED_EVENT): string
    {
        return self::event('tucano.commerce.order.cancelled', $eventId, [
            'orderId' => self::ORDER,
            'orderNumber' => '97663530295234560',
            'reason' => 'customer_request',
            'previousStatus' => 'paid',
            ...$data,
        ]);
    }

    /** An unpaid order whose stock reservation ran out: the most common cancellation. */
    public static function expired(): string
    {
        return self::cancelled(['reason' => 'reservation_expired', 'previousStatus' => 'pending_payment'], '01999a24-3e4f-7a5b-9c6d-7e8f9a0b1c23');
    }

    /** Another event of the same topic, which Logistics does not handle. */
    public static function shipped(): string
    {
        return self::event('tucano.commerce.order.shipped', '01999a23-2d3e-7f4a-8b5c-6d7e8f9a0b12', [
            'orderId' => self::ORDER,
            'orderNumber' => '97663530295234560',
        ]);
    }

    public static function message(string $payload, int $offset = 7): ReceivedMessage
    {
        return new ReceivedMessage('commerce.orders.v2', 2, $offset, self::ORDER, $payload);
    }

    /** @param array<string, mixed> $data */
    private static function event(string $type, string $id, array $data): string
    {
        return json_encode([
            'specversion' => '1.0',
            'id' => $id,
            'source' => '/commerce',
            'type' => $type,
            'subject' => self::ORDER,
            'time' => '2026-09-27T12:05:00.000Z',
            'datacontenttype' => 'application/json',
            'correlationid' => 'req-42#3',
            'data' => $data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
