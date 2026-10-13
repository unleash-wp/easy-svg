import { test, expect } from './fixtures.js'
import { PANEL } from './helpers.js'

/*
 * The menu entries are the part a PHP test cannot see at all: they are built in
 * PHP from a filter, but whether the entry OPENS the tab it names is decided by
 * the React app reading ?tab= back out of the address bar.
 */
test('each menu entry opens the tab it names', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=easy-svg-icons')
  await expect(page.locator(PANEL)).toBeVisible()

  const entries = page.locator('#adminmenu a[href*="page=easy-svg-icons&tab="]')
  const count = await entries.count()
  expect(count, 'the Icons menu carries an entry per tab').toBeGreaterThanOrEqual(2)

  for (let i = 0; i < count; i++) {
    // Resolved against the page, not the baseURL: WordPress writes these hrefs
    // relative ("admin.php?page=..."), so the baseURL would drop /wp-admin/.
    const href = new URL(await entries.nth(i).getAttribute('href'), page.url()).toString()
    const tab = new URL(href).searchParams.get('tab')
    await page.goto(href)
    await expect(page.locator(PANEL)).toBeVisible()
    // The rail marks exactly the tab the address bar asked for.
    await expect(page.locator(`${PANEL} [role="tab"][aria-selected="true"]`).first()).toBeVisible()
    expect(new URL(page.url()).searchParams.get('tab'), `${tab} stays selected`).toBe(tab)
  }
})

test('an unknown tab opens the panel rather than an empty well', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=easy-svg-icons&tab=nothing-registered-this')
  await expect(page.locator(PANEL)).toBeVisible()
  await expect(page.locator(`${PANEL} [role="tab"][aria-selected="true"]`).first()).toBeVisible()
})
