import { test as setup, expect } from '@playwright/test'

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
  await page.context().storageState({ path: 'e2e/.auth/admin.json' })
})
