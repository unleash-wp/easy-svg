import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY } from './helpers.js'

/*
 * #68: a REST request is answered in the SITE language unless it carries
 * `_locale=user` AND reads as JSON (wp-includes/l10n.php, determine_locale()).
 * The panel sent neither, so an administrator who reads English on a German
 * site got English screens with German sentences from the server in them.
 */

test('every panel request asks for the reader\'s language, as JSON', async ({ page }) => {
  const seen = []
  page.on('request', (request) => {
    if (/\/easy-svg\/v1\//.test(decodeURIComponent(request.url()))) {
      seen.push({ url: request.url(), accept: request.headers().accept || '' })
    }
  })

  await page.goto(LIBRARY)
  await expect(page.locator(PANEL)).toBeVisible()
  await expect.poll(() => seen.length, { timeout: 15000 }).toBeGreaterThan(0)

  for (const { url, accept } of seen) {
    expect(new URL(url).searchParams.get('_locale'), url).toBe('user')
    expect(accept, url).toContain('application/json')
  }
})
