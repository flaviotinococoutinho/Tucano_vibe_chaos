<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Store;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use stdClass;

/** Stores in MySQL, found by their slug: the id stays inside the database. */
final readonly class StoreRepository
{
    public function __construct(private DatabaseManager $database) {}

    /** @return list<Store> by name; the slug breaks a tie, so the order never flips */
    public function all(): array
    {
        $rows = $this->stores()->orderBy('name')->orderBy('slug')->get()->all();

        return array_values(array_map(self::store(...), $rows));
    }

    public function findBySlug(string $slug): ?Store
    {
        $row = $this->stores()->where('slug', $slug)->first();

        return $row instanceof stdClass ? self::store($row) : null;
    }

    public function exists(string $slug): bool
    {
        return $this->database->connection()->table('stores')->where('slug', $slug)->exists();
    }

    private function stores(): Builder
    {
        return $this->database->connection()->table('stores')->select(['slug', 'name', 'tagline', 'palette']);
    }

    private static function store(stdClass $row): Store
    {
        return Store::of((string) $row->slug, (string) $row->name, (string) $row->tagline, (string) $row->palette);
    }
}
