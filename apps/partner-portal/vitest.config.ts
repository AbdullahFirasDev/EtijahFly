import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'

// Unit tests for framework-independent logic (utils, server helpers, shared contracts).
// User flows are covered end-to-end by Playwright (e2e/).
export default defineConfig({
  resolve: {
    alias: {
      '~': fileURLToPath(new URL('./app', import.meta.url)),
    },
  },
  test: {
    include: ['tests/unit/**/*.spec.ts'],
    environment: 'node',
  },
})
