<?php

declare(strict_types=1);

return [
    // The optimistic strategy reads again when someone changed the row since; after this many rounds it gives up.
    'optimistic_hold_attempts' => (int) env('INVENTORY_OPTIMISTIC_HOLD_ATTEMPTS', 5),
];
