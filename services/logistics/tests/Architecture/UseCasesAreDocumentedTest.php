<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * Fitness function: every class marked with #[UseCase] must have its fully
 * dressed description in docs/use-cases, so no use case ships undocumented.
 */
final class UseCasesAreDocumentedTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../../src';
    private const string DOCS = __DIR__ . '/../../../../docs/use-cases';

    #[Test]
    public function every_use_case_has_its_description(): void
    {
        if (!is_dir(self::DOCS)) {
            self::markTestSkipped('docs/ is only available in the monorepo checkout.');
        }

        $undocumented = array_filter(
            $this->useCaseIds(),
            static fn(string $id): bool => glob(self::DOCS . '/' . $id . '-*.md') === [],
        );

        self::assertSame([], array_values($undocumented), 'Write the fully dressed description in docs/use-cases for these ids.');
    }

    /** @return list<string> */
    private function useCaseIds(): array
    {
        $ids = [];
        foreach ($this->sourceClasses() as $class) {
            foreach ((new ReflectionClass($class))->getAttributes(UseCase::class) as $attribute) {
                $ids[] = $attribute->newInstance()->id;
            }
        }

        return $ids;
    }

    /** @return list<class-string> */
    private function sourceClasses(): array
    {
        if (!is_dir(self::SOURCE)) {
            return [];
        }
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SOURCE));
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $relative = substr($file->getPathname(), strlen(self::SOURCE) + 1, -4);
                $class = 'Logistics\\' . str_replace('/', '\\', $relative);
                if (class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }
}
