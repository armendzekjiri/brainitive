import { defineConfig } from 'vitest/config';
import { WxtVitest } from 'wxt/testing/vitest-plugin';

// Unit tests run in Node with WXT's in-memory fake browser (`wxt/testing/fake-browser`):
// storage, runtime and identity behave like the real APIs, `fetch` is mocked per test.
export default defineConfig({
    plugins: [WxtVitest()],
    test: {
        include: ['tests/**/*.test.ts'],
        restoreMocks: true,
    },
});
