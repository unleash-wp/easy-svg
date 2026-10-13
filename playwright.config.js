import { defineConfig, devices } from '@playwright/test'

/*
 * The panel is the one part of this plugin no PHP test can reach: it is a
 * React screen that only exists once WordPress has rendered admin and the
 * bundle has run. Everything here drives a real browser against a real site.
 *
 * BASE_URL so the same specs run against a local wp-env and against the one
 * CI starts, without a second config.
 */
const baseURL = process.env.WP_BASE_URL || 'http://localhost:8888'

export default defineConfig({
  testDir: './e2e',
  // One worker: every spec talks to the same WordPress, and two of them
  // adding or removing icons at once would read each other's library.
  workers: 1,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['list']] : [['list']],
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.js/ },
    {
      name: 'panel',
      dependencies: ['setup'],
      // The admin session is established once by the setup project and reused;
      // a login per spec would be most of the runtime. Set HERE, not globally,
      // or the setup project would try to read the file it is about to write.
      use: { ...devices['Desktop Chrome'], storageState: 'e2e/.auth/admin.json' },
    },
  ],
})
