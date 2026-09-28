import { cleanup } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';
import { afterEach } from 'vitest';

// Explicit, since these tests do not run with Vitest's `globals: true` (see vite.config.ts):
// @testing-library/react only auto-registers this when it finds a global `afterEach`.
afterEach(cleanup);
