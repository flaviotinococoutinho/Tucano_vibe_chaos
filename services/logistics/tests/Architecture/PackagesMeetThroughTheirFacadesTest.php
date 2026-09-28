<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Fitness function for the borders between packages. Packages meet only in
 * their adapters, the anticorruption layer, and there they go through the
 * application layer of the other side: its driving ports and the types those
 * ports speak. The core of a package (Domain and Application) knows no other
 * package, and no package touches the adapters of another.
 */
final class PackagesMeetThroughTheirFacadesTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../../src';

    private const string SERVICE = 'Logistics';

    #[Test]
    public function packages_meet_only_in_adapters_and_through_the_facade_of_the_other_side(): void
    {
        self::assertSame([], $this->crossings(), 'Cross the border in an adapter of your package, through the ports of the other one.');
    }

    /** @return list<string> */
    private function crossings(): array
    {
        $crossings = [];
        foreach ($this->sourceFiles() as $file) {
            [$package, $layer] = $this->placeOf($file);
            preg_match_all('/^use ' . self::SERVICE . '\\\\(\\w+)\\\\(\\w+)/m', (string) file_get_contents($file->getPathname()), $imports, PREG_SET_ORDER);
            foreach ($imports as [$import, $otherPackage, $otherLayer]) {
                if ($otherPackage === $package || $otherPackage === 'Shared') {
                    continue;
                }
                if ($layer !== 'Adapter' || $otherLayer === 'Adapter') {
                    $crossings[] = sprintf('%s/%s reaches %s/%s (%s)', $package, $layer, $otherPackage, $otherLayer, $file->getFilename());
                }
            }
        }

        return $crossings;
    }

    /** @return array{string, string} the package and the layer of a file under src/ */
    private function placeOf(SplFileInfo $file): array
    {
        $parts = explode('/', substr($file->getPathname(), strlen((string) realpath(self::SOURCE)) + 1));

        return [$parts[0], $parts[1] ?? ''];
    }

    /** @return list<SplFileInfo> */
    private function sourceFiles(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) realpath(self::SOURCE))) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
