<?php

declare(strict_types=1);

use Laravel\Lumen\Routing\Router;

/** @var Router $router */

// Lumen resolves "Controller@method" against the App\Http\Controllers namespace set in bootstrap/app.php.
$router->get('/health/live', 'HealthController@live');
$router->get('/health/ready', 'HealthController@ready');
