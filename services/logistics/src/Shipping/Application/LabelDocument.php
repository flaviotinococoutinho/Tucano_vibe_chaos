<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

/** A printed label: the bytes the printer takes, and how to file them. */
final readonly class LabelDocument
{
    public function __construct(
        public string $contents,
        public string $extension,
        public string $contentType,
    ) {}
}
