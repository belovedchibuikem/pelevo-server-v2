import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/Browser',
  timeout: 90000,
  expect: { timeout: 20000, toHaveScreenshot: { maxDiffPixelRatio: 0.015, animations: 'disabled' } },
  workers: 1,
  use: { baseURL: 'http://127.0.0.1:8019', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  projects: [{ name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } } }],
  webServer: { command: 'node tests/Browser/server.mjs', url: 'http://127.0.0.1:8019/admin/login', timeout: 300000, reuseExistingServer: false },
});
