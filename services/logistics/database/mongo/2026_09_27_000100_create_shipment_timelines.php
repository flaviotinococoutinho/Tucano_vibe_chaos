<?php

declare(strict_types=1);

use MongoDB\Database;
use Tucano\ReadModels\Migration;

/**
 * Shipment timeline shown to customers and operators. One document per
 * shipment with every milestone embedded: read by id or tracking code, never joined.
 */
return new class implements Migration {
    public function up(Database $database): void
    {
        $database->createCollection('shipment_timelines', [
            'validationLevel' => 'strict',
            'validationAction' => 'error',
            'validator' => ['$jsonSchema' => [
                'bsonType' => 'object',
                'required' => ['_id', 'trackingCode', 'orderId', 'status', 'carrier', 'events', 'updatedAt', 'version'],
                'properties' => [
                    '_id' => ['bsonType' => 'binData', 'description' => 'shipment id, UUIDv7 as BSON binary subtype 4'],
                    'trackingCode' => ['bsonType' => 'string', 'pattern' => '^TX[0-9A-HJKMNP-TV-Z]{13}$'],
                    'orderId' => ['bsonType' => 'binData'],
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

        $timelines = $database->selectCollection('shipment_timelines');
        $timelines->createIndex(['trackingCode' => 1], ['name' => 'tracking_code', 'unique' => true]);
        $timelines->createIndex(['orderId' => 1], ['name' => 'order', 'unique' => true]);
    }
};
