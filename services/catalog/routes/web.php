<?php

declare(strict_types=1);

use Laravel\Lumen\Routing\Router;

/** @var Router $router */

// Lumen resolves "Controller@method" against the App\Http\Controllers namespace set in bootstrap/app.php.
$router->get('/health/live', 'HealthController@live');
$router->get('/health/ready', 'HealthController@ready');

// Only a well-formed SKU reaches the controller: anything else is a 404 from the
// router, so it never costs a query or leaves a key in the cache.
$product = '/v1/products/{sku:[A-Z0-9][A-Z0-9-]{2,31}}';

$router->get('/v1/products', 'ProductController@index');
$router->post('/v1/products', 'ProductController@store');
$router->get($product, 'ProductController@show');
$router->patch($product, 'ProductController@update');
$router->post($product . '/activate', 'ProductController@activate');
$router->post($product . '/discontinue', 'ProductController@discontinue');
