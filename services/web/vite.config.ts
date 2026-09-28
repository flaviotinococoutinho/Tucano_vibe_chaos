/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

// The only place that knows the BFF sits behind Kong on :8000 in local dev. The app itself
// never hardcodes a host: every request it makes starts with the BFF_PREFIX (see src/hypermedia).
const BFF_DEV_TARGET = 'http://localhost:8000';

export default defineConfig({
  plugins: [react()],
  build: {
    target: 'es2022',
  },
  server: {
    proxy: {
      '/bff': {
        target: BFF_DEV_TARGET,
        changeOrigin: true,
      },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./test/setup.ts'],
    css: false,
    restoreMocks: true,
  },
});
