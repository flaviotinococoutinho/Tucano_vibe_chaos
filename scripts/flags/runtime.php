<?php

declare(strict_types=1);

/*
 * Changes one flag in the runtime copy that flagd watches, so an experiment
 * needs no deploy and no restart:
 *
 *   php runtime.php set <flag> <variant>   serves another of the flag's variants
 *   php runtime.php reset <flag>           puts back the definition in the repository
 *
 * flagd reloads on every write. The script never removes a key: flagd v0.17
 * forgets a flag that disappears (see docs/architecture/feature-flags.md).
 */

const RUNTIME = '/runtime/flags.flagd.json';

/** @return array<string, mixed> */
function readFlags(string $path): array
{
    $flags = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return is_array($flags) ? $flags : throw new RuntimeException("{$path} is not a flag file.");
}

function quit(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

[, $command, $key, $variant] = $argv + [null, null, null, null];
$runtime = readFlags(RUNTIME);
$current = $runtime['flags'][$key ?? ''] ?? quit(sprintf('No flag %s in the runtime copy.', var_export($key, true)));

$runtime['flags'][$key] = match ($command) {
    'set' => array_key_exists((string) $variant, $current['variants'] ?? [])
        ? ['state' => 'ENABLED', 'defaultVariant' => $variant] + $current
        : quit(sprintf('%s has no variant %s; it has %s.', $key, var_export($variant, true), implode(', ', array_keys($current['variants'] ?? [])))),
    'reset' => readFlags(sprintf('/environments/%s.flagd.json', getenv('APP_ENV') ?: 'local'))['flags'][$key]
        ?? quit(sprintf('%s is not in the repository file.', $key)),
    default => quit('Use: runtime.php set <flag> <variant> | reset <flag>'),
};

file_put_contents(RUNTIME, json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
printf("%s now serves %s\n", $key, json_encode($runtime['flags'][$key]['variants'][$runtime['flags'][$key]['defaultVariant']] ?? null));
