<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Database\DatabaseManager;

final readonly class CategoryRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function exists(string $slug): bool
    {
        return $this->database->connection()->table('categories')->where('slug', $slug)->exists();
    }
}
