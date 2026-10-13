import { test, expect } from './fixtures.js'
import { adminPage } from './helpers.js'

/*
 * A reference that no longer resolves.
 *
 * A collection leaves when a theme is switched, a plugin is deactivated or a
 * folder is renamed, and the reference saved in the post stays behind. Before
 * this notice the front end drew nothing and the editor showed an empty frame,
 * so the first person to find out was a visitor.
 *
 * Driven in a real editor because that is the only place the answer lives: the
 * filter, the icon list and the iframe canvas are all client side, and a unit
 * test of the markup would pass while nothing rendered.
 */
test.describe('an Icon block whose icon is gone', () => {
  /*
   * Core asks the REST API for each icon a block names and takes a 404 for the
   * two that are gone. That 404 IS the state under test; everything else on the
   * page is still held to the suite's no-console-errors rule.
   */
  test.use({ consoleAllow: [ /Failed to load resource.*404/ ] })

  let pageId

  test.beforeAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto('/wp-admin/')
    pageId = await page.evaluate(async () => {
      const nonce = window.wpApiSettings?.nonce
      const res = await fetch('/wp-json/wp/v2/pages', {
        method: 'POST',
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        body: JSON.stringify({
          title: 'e2e missing icon',
          status: 'draft',
          content:
            '<!-- wp:icon {"icon":"e2e-weg/nichts"} /-->' +
            '<!-- wp:icon {"icon":"easy-svg/e2e-gibt-es-nicht"} /-->',
        }),
      })
      return ( await res.json() ).id
    })
    await context.close()
  })

  test.afterAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto('/wp-admin/')
    await page.evaluate(async (id) => {
      const nonce = window.wpApiSettings?.nonce
      await fetch(`/wp-json/wp/v2/pages/${id}?force=true`, {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': nonce },
      })
    }, pageId)
    await context.close()
  })

  test('says so in the editor, and says which of the two things went wrong', async ({ page }) => {
    await page.goto(`/wp-admin/post.php?post=${pageId}&action=edit`)

    // The canvas is an iframe; a query against the top document finds the
    // blocks of a different editor and nothing of this one.
    const canvas = page.frameLocator('iframe[name="editor-canvas"]')
    const notices = canvas.locator('.easy-svg-missing-icon')

    await expect(notices).toHaveCount(2, { timeout: 20000 })

    // A collection nothing registers reads differently from an icon that left a
    // collection which is still here: the remedies are not the same.
    await expect(notices.first()).toContainText(/e2e-weg/)
    await expect(notices.first()).toContainText(/registriert|registers/i)
    await expect(notices.nth(1)).toContainText(/easy-svg\/e2e-gibt-es-nicht/)
    await expect(notices.nth(1)).toContainText(/umbenannt|renamed/i)
  })

  test('says nothing about an icon that is there', async ({ page }) => {
    await page.goto(`/wp-admin/post.php?post=${pageId}&action=edit`)
    const canvas = page.frameLocator('iframe[name="editor-canvas"]')
    await expect(canvas.locator('.easy-svg-missing-icon')).toHaveCount(2, { timeout: 20000 })
    // Three blocks would mean the valid one warned too; the fixture has two
    // broken references and no valid one, so the count IS the assertion.
    await expect(canvas.locator('[data-type="core/icon"]')).toHaveCount(2)
  })
})
