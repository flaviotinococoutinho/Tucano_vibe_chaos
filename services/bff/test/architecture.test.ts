import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

const SRC = resolve(dirname(fileURLToPath(import.meta.url)), '../src');

/**
 * The vocabulary, the anticorruption layer, the session and the stores know no screen;
 * screens know them all.
 */
const FOUNDATIONS = ['platform', 'hypermedia', 'upstream', 'session', 'stores'];
const FEATURES = ['storefront', 'checkout', 'orders', 'profiles', 'tracking'];

type Import = { readonly from: string; readonly module: string; readonly target: string };

/**
 * The same rule the PHP services keep with PackagesMeetThroughTheirFacadesTest: a module
 * reaches another only through its barrel (index.ts), so what a module exports is its
 * whole contract and everything else can change without asking anyone.
 */
describe('the modules of the BFF', () => {
  const imports = importsBetweenModules();

  it('are each a foundation or a feature, so no new one escapes these rules', () => {
    const modules = readdirSync(SRC, { withFileTypes: true })
      .filter((entry) => entry.isDirectory())
      .map((entry) => entry.name)
      .sort();

    assert.deepStrictEqual(modules, [...FOUNDATIONS, ...FEATURES].sort());
  });

  it('meet only through their barrels', () => {
    const through = imports.filter(
      ({ module, target }) => module !== 'platform' && !target.endsWith(`${module}${sep}index.ts`),
    );
    // platform is the copy shared with partners-sim (ADR 0016), imported file by file.
    assert.deepStrictEqual(
      through.map(({ from, target }) => `${from} -> ${target}`),
      [],
    );
  });

  it('keep the foundations free of screens', () => {
    const upward = imports.filter(
      ({ from, module }) => FOUNDATIONS.includes(moduleOf(from)) && FEATURES.includes(module),
    );
    assert.deepStrictEqual(
      upward.map(({ from, target }) => `${from} -> ${target}`),
      [],
    );
  });

  it('keep the features free of cycles', () => {
    const edges = new Map<string, Set<string>>();
    for (const { from, module } of imports) {
      const source = moduleOf(from);
      if (FEATURES.includes(source) && FEATURES.includes(module)) {
        edges.set(source, (edges.get(source) ?? new Set()).add(module));
      }
    }
    for (const feature of FEATURES) {
      assert.equal(reaches(edges, feature, feature), false, `${feature} imports itself back`);
    }
  });
});

function importsBetweenModules(): Import[] {
  const found: Import[] = [];
  for (const file of sourceFiles(SRC)) {
    const from = relative(SRC, file);
    const source = readFileSync(file, 'utf8');
    for (const [, specifier] of source.matchAll(/from '(\.[^']+)'/g)) {
      const target = relative(SRC, resolve(dirname(file), specifier ?? ''));
      const module = moduleOf(target);
      if (module !== '' && module !== moduleOf(from)) {
        found.push({ from, module, target });
      }
    }
  }
  return found;
}

/** The folder under src/ a file lives in; files at the root (app.ts, config.ts) have none. */
function moduleOf(path: string): string {
  const parts = path.split(sep);
  return parts.length > 1 ? (parts[0] ?? '') : '';
}

function sourceFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) =>
    entry.isDirectory()
      ? sourceFiles(join(directory, entry.name))
      : entry.name.endsWith('.ts')
        ? [join(directory, entry.name)]
        : [],
  );
}

function reaches(
  edges: Map<string, Set<string>>,
  from: string,
  to: string,
  seen = new Set<string>(),
): boolean {
  for (const next of edges.get(from) ?? []) {
    if (next === to) {
      return true;
    }
    if (!seen.has(next)) {
      seen.add(next);
      if (reaches(edges, next, to, seen)) {
        return true;
      }
    }
  }
  return false;
}
