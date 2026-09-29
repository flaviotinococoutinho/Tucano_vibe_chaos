<?php

declare(strict_types=1);

namespace App\Models;

use JsonSerializable;

/**
 * A store the platform hosts (docs/adr/0031-a-store-is-a-tenant.md). The slug never changes:
 * it is the address of the store and the store every event names. The palette is one of the
 * web design system's, which owns the colors; the store only picks one.
 *
 * @phpstan-type StoreRecord array{slug: string, name: string, tagline: string, palette: string}
 */
final readonly class Store implements JsonSerializable
{
    private function __construct(
        public string $slug,
        public string $name,
        public string $tagline,
        public string $palette,
    ) {}

    public static function of(string $slug, string $name, string $tagline, string $palette): self
    {
        return new self($slug, $name, $tagline, $palette);
    }

    /** @return StoreRecord */
    public function toArray(): array
    {
        return ['slug' => $this->slug, 'name' => $this->name, 'tagline' => $this->tagline, 'palette' => $this->palette];
    }

    /** @return StoreRecord */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
