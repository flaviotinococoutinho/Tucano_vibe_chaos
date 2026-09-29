<?php

declare(strict_types=1);

use MongoDB\Database;
use Tucano\ReadModels\Migration;

/**
 * ADR 0031: the timeline of a shipment carries the store it belongs to, when the events say it.
 * collMod replaces the whole validator, so this is the one of the first migration plus the store,
 * which stays optional: a timeline from before the stores has none.
 */
return new class implements Migration {
    public function up(Database $database): void
    {
        $database->command([
            'collMod' => 'shipment_timelines',
            'validationLevel' => 'strict',
            'validationAction' => 'error',
            'validator' => ['$jsonSchema' => [
                'bsonType' => 'object',
                'required' => ['_id', 'trackingCode', 'orderId', 'status', 'carrier', 'events', 'updatedAt', 'version'],
                'properties' => [
                    '_id' => ['bsonType' => 'binData', 'description' => 'shipment id, UUIDv7 as BSON binary subtype 4'],
                    'trackingCode' => ['bsonType' => 'string', 'pattern' => '^TX[0-9A-HJKMNP-TV-Z]{13}$'],
                    'orderId' => ['bsonType' => 'binData'],
                    'store' => ['bsonType' => 'string', 'pattern' => '^[a-z][a-z0-9-]{1,30}$', 'description' => 'slug of the store the shipment belongs to; absent before the stores'],
                    'status' => ['enum' => [
                        'created', 'ready_for_pickup', 'picked_up', 'in_transit', 'out_for_delivery',
                        'delivered', 'delivery_failed', 'returning', 'returned', 'cancelled',
                    ]],
                    'carrier' => ['bsonType' => 'string', 'maxLength' => 32],
                    'events' => [
                        'bsonType' => 'array',
                        'items' => [
                            'bsonType' => 'object',
                            'required' => ['status', 'at'],
                            'properties' => [
                                'status' => ['bsonType' => 'string'],
                                'at' => ['bsonType' => 'date'],
                                'location' => ['bsonType' => ['string', 'null'], 'maxLength' => 120],
                                'note' => ['bsonType' => ['string', 'null'], 'maxLength' => 200],
                            ],
                        ],
                    ],
                    'updatedAt' => ['bsonType' => 'date'],
                    'version' => ['bsonType' => 'long'],
                ],
            ]],
        ]);
    }
};
