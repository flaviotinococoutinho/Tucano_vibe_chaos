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
 * the PSP), in the domain events (the published language other contexts need), and in
 * the few places listed below, each with its reason. A reveal() anywhere else, like in a
 * log line or an API view, fails here.
 */
final class SensitiveDataLeavesOnPurposeTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../../src';

    /** @var array<string, string> file under src/ => why it needs the real value */
    private const array ALLOWED = [
        'Ordering/Domain/Customer/PersonName.php' => 'the value object hands out its own value',
        'Ordering/Domain/Customer/EmailAddress.php' => 'the value object hands out its own value',
        'Payments/Domain/CardToken.php' => 'the value object hands out its own value',
        'Ordering/Application/PlaceOrderCommand.php' => 'the idempotency fingerprint: two masks can match where two people do not',
        'Payments/Application/PayOrderCommand.php' => 'the idempotency fingerprint: every token masks to tok_***',
    ];

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

        self::assertSame([], $leaks, 'reveal() belongs to adapters and domain events; add a reason to ALLOWED for anything else.');
    }

    private function mayReveal(string $path): bool
    {
        return str_contains($path, '/Adapter/')
            || str_contains($path, '/Domain/Event/')
            || array_key_exists($path, self::ALLOWED);
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
