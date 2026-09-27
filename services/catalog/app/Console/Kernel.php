<?php

declare(strict_types=1);

namespace App\Console;

use Laravel\Lumen\Console\Kernel as LumenKernel;

/**
 * Lumen turns facades on for console commands. The global class aliases stay off,
 * so a migration or seeder imports the facade it uses.
 */
final class Kernel extends LumenKernel
{
    /** @var bool */
    protected $aliases = false;
}
