<?php

declare(strict_types=1);

use MongoDB\Database;
use Tucano\ReadModels\Migration;

/**
 * ADR 0031: the list of a customer is the list of a customer in one store. Each view keeps the
 * store of its order, copied from order.placed, and the list reads by store, customer and time,
 * in the index store_customer_history, which takes the place of customer_history: no read goes
 * by the customer alone anymore. Views from before the stores have no store, so no list has them.
 *
 * The validator never refused fields it does not name, so it would take the store as it is.
 * It names it anyway, like every other field, so a view cannot hold a store in another shape.
 * collMod replaces the whole validator, hence the schema of the first migration here again,
 * with the store; the store stays optional, because the old views and the events from before
 * the stores have none.
 */
return new class implements Migration {
    public function up(Database $database): void
    {
        $database->modifyCollection('order_views', [
            'validator' => ['$jsonSchema' => [
                'bsonType' => 'object',
                'required' => ['_id', 'orderNumber', 'customerId', 'status', 'total', 'lines', 'placedAt', 'updatedAt', 'version'],
                'properties' => [
                    '_id' => ['bsonType' => 'binData', 'description' => 'order id, UUIDv7 as BSON binary subtype 4'],
                    'orderNumber' => ['bsonType' => 'long', 'description' => 'public Snowflake number'],
                    'store' => [
                        'bsonType' => ['string', 'null'],
                        'pattern' => '^[a-z][a-z0-9-]{1,30}$',
                        'description' => 'slug of the store the order was placed in; none for an order from before the stores',
                    ],
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
        $orders->createIndex(['store' => 1, 'customerId' => 1, 'placedAt' => -1], ['name' => 'store_customer_history']);
        $orders->dropIndex('customer_history');
    }
};
