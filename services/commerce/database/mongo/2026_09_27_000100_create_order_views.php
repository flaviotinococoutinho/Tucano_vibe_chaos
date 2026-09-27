<?php

declare(strict_types=1);

use MongoDB\Database;
use Tucano\ReadModels\Migration;

/**
 * Customer order history (CQRS read side). Fed by commerce.orders.v1 and
 * logistics.shipments.v1, so it may lag a few seconds behind PostgreSQL.
 */
return new class implements Migration {
    public function up(Database $database): void
    {
        $database->createCollection('order_views', [
            'validationLevel' => 'strict',
            'validationAction' => 'error',
            'validator' => ['$jsonSchema' => [
                'bsonType' => 'object',
                'required' => ['_id', 'orderNumber', 'customerId', 'status', 'total', 'lines', 'placedAt', 'updatedAt', 'version'],
                'properties' => [
                    '_id' => ['bsonType' => 'binData', 'description' => 'order id, UUIDv7 as BSON binary subtype 4'],
                    'orderNumber' => ['bsonType' => 'long', 'description' => 'public Snowflake number'],
                    'customerId' => ['bsonType' => 'binData'],
                    'status' => ['enum' => ['pending_payment', 'paid', 'shipped', 'delivered', 'cancelled', 'returned']],
                    'total' => [
                        'bsonType' => 'object',
                        'required' => ['amount', 'currency'],
                        'properties' => [
                            'amount' => ['bsonType' => 'long', 'minimum' => 0],
                            'currency' => ['bsonType' => 'string', 'pattern' => '^[A-Z]{3}$'],
                        ],
                    ],
                    'lines' => [
                        'bsonType' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'bsonType' => 'object',
                            'required' => ['sku', 'name', 'quantity', 'unitPrice'],
                            'properties' => [
                                'sku' => ['bsonType' => 'string', 'maxLength' => 32],
                                'name' => ['bsonType' => 'string', 'maxLength' => 160],
                                'quantity' => ['bsonType' => 'int', 'minimum' => 1, 'maximum' => 10],
                                'unitPrice' => ['bsonType' => 'long', 'minimum' => 0],
                            ],
                        ],
                    ],
                    'shipment' => [
                        'bsonType' => ['object', 'null'],
                        'properties' => [
                            'trackingCode' => ['bsonType' => 'string', 'pattern' => '^TX[0-9A-HJKMNP-TV-Z]{13}$'],
                            'status' => ['bsonType' => 'string'],
                            'carrier' => ['bsonType' => 'string'],
                        ],
                    ],
                    'placedAt' => ['bsonType' => 'date'],
                    'updatedAt' => ['bsonType' => 'date'],
                    'version' => ['bsonType' => 'long', 'description' => 'last applied change; older events are ignored'],
                ],
            ]],
        ]);

        $orders = $database->selectCollection('order_views');
        $orders->createIndex(['customerId' => 1, 'placedAt' => -1], ['name' => 'customer_history']);
        $orders->createIndex(['orderNumber' => 1], ['name' => 'order_number', 'unique' => true]);
    }
};
