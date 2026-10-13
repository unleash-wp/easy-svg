import { test as setup, expect } from '@playwright/test'
import { rest } from './helpers.js'

/*
 * One login for the whole run, stored and reused.
 *
 * Posted to wp-login.php rather than typed into it. WordPress replaces the
 * password field on DOM ready to hang its show/hide button on it, and typing
 * into a field that is being swapped under you lands the password in the
 * USERNAME box -- measured, repeatedly, and only on some runs. The POST is what
 * the form does anyway, it carries no JavaScript, and it is a third of a second
 * rather than three.
 *
 * admin/password are wp-env's own defaults, so this works against a fresh
 * environment with nothing configured. page.request shares the context's cookie
 * jar, so the session it establishes is the one the specs then browse with.
 */
setup('sign in to wp-admin', async ({ page }) => {
  const res = await page.request.post('/wp-login.php', {
    form: {
      log: 'admin',
      pwd: 'password',
      'wp-submit': 'Log In',
      redirect_to: '/wp-admin/',
      testcookie: '1',
    },
  })
  expect(res.status(), 'wp-login.php accepted the credentials').toBeLessThan(400)

  await page.goto('/wp-admin/admin.php?page=easy-svg-icons&tab=settings')
  await expect(page.locator('#wpadminbar')).toBeVisible()

  /*
   * Switch the icon library on, because a fresh install has every feature off
   * -- the plugin adds what was asked for and no more. Without this the library
   * tab is a sentence pointing at Settings, and every spec that looks for the
   * search field waits for something that was never going to be drawn.
   *
   * Merged onto what is stored, so a site that already has settings keeps them.
   */
  const current = await rest(page, '/settings')
  const saved = await rest(page, '/settings', {
    method: 'POST',
    body: { ...(current.body || {}), icons: true },
  })
  expect(saved.status, JSON.stringify(saved.body).slice(0, 200)).toBe(200)

  await page.context().storageState({ path: 'e2e/.auth/admin.json' })
})
