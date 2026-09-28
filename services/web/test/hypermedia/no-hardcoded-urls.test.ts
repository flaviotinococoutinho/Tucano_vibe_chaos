import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';

const SRC_DIR = join(import.meta.dirname, '..', '..', 'src');
const ALLOWED_FILE = join(SRC_DIR, 'hypermedia', 'prefix.ts');
const LITERAL = '/bff/v1';

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) {
      return sourceFiles(full);
    }
    return /\.(ts|tsx)$/.test(name) ? [full] : [];
  });
}

describe('the /bff/v1 prefix stays in one place', () => {
  it('never appears as a literal in src/, except in hypermedia/prefix.ts', () => {
    const offenders = sourceFiles(SRC_DIR)
      .filter((file) => file !== ALLOWED_FILE)
      .filter((file) => readFileSync(file, 'utf8').includes(LITERAL))
      .map((file) => relative(SRC_DIR, file));

    expect(offenders).toEqual([]);
  });
});
