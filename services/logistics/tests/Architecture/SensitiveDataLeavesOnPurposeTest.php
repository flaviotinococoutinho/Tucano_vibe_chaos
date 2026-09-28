<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Fitness function for personal and card data (LGPD, PCI DSS). A Sensitive value shows
 * only its mask, and reveal() is how the real value leaves: in the adapters (the row,
 * the label) and in the domain events (the published language other contexts need). A
 * reveal() anywhere else, like in a log line or an API view, fails here.
 */
final class SensitiveDataLeavesOnPurposeTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../../src';

    #[Test]
    public function the_real_value_leaves_only_where_someone_decided_it_should(): void
    {
        $leaks = [];
        foreach ($this->sourceFiles() as $file) {
            $path = substr($file->getPathname(), strlen((string) realpath(self::SOURCE)) + 1);
            $revealed = str_contains((string) file_get_contents($file->getPathname()), '->reveal()');
            if ($revealed && !$this->mayReveal($path)) {
                $leaks[] = $path;
            }
        }

        self::assertSame([], $leaks, 'reveal() belongs to adapters and domain events.');
    }

    private function mayReveal(string $path): bool
    {
        return str_contains($path, '/Adapter/') || str_contains($path, '/Domain/Event/');
    }

    /** @return list<SplFileInfo> */
    private function sourceFiles(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) realpath(self::SOURCE))) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
