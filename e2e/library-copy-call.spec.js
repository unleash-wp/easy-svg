import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, addIcon, sweepE2E, adminPage } from './helpers.js'

/*
 * Copying a tile's call offers the three ways an icon is placed: the PHP tag,
 * the shortcode, and core's Icon block where WordPress has the registry. Each
 * pasted form has to render on a real page, or the menu offers text that does
 * nothing.
 */

// Drawn with a path, so core's Icon block keeps it; the square is a rect, which
// the block's allow-list drops.
const ARROW = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 12h16M12 4l8 8-8 8"/></svg>'
const SQUARE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="20" height="20" x="2" y="2"/></svg>'
const STAMP = String(Date.now()).slice(-6)
const SLUG = `e2e-ruf-${STAMP}`
const RECT = `e2e-ruf-eckig-${STAMP}`

async function wp(page, path, { method = 'GET', body } = {}) {
  return page.evaluate(
    async ({ path, method, body }) => {
      const nonce = window.EasySvgPanelData?.nonce || window.wpApiSettings?.nonce
      const res = await fetch(`/wp-json${path}`, {
        method,
        headers: { 'X-WP-Nonce': nonce, ...(body ? { 'Content-Type': 'application/json' } : {}) },
        body: body ? JSON.stringify(body) : undefined,
      })
      return { status: res.status, body: await res.json().catch(() => null) }
    },
    { path, method, body }
  )
}

test.describe('copying an icon\'s call', () => {
  const pages = []

  test.beforeAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await addIcon(page, SLUG, ARROW)
    await addIcon(page, RECT, SQUARE)
    await context.close()
  })
  test.afterAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    for (const id of pages) {
      await wp(page, `/wp/v2/pages/${id}?force=true`, { method: 'DELETE' })
    }
    await sweepE2E(page)
    await context.close()
  })

  test('the menu offers PHP, shortcode and block, and copies each as written', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write'])
    await page.goto(LIBRARY)
    const tile = page.locator(`${PANEL} [data-icon-key="easy-svg/${SLUG}"]`)
    const pick = async (name) => {
      await tile.getByRole('button', { name: /Copy the call|kopieren/ }).click()
      await page.getByRole('menuitem', { name }).click()
      return page.evaluate(() => navigator.clipboard.readText())
    }
    expect(await pick(/^PHP/)).toBe(`easy_svg_icon( '${SLUG}' )`)
    expect(await pick(/^Shortcode/)).toBe(`[easy_svg_icon name="easy-svg/${SLUG}"]`)
    expect(await pick(/^Block/)).toBe(`<!-- wp:icon {"icon":"easy-svg/${SLUG}"} /-->`)
  })

  test('an icon the Icon block cannot draw offers no block, and says why', async ({ page }) => {
    await page.goto(LIBRARY)
    await page.locator(`${PANEL} [data-icon-key="easy-svg/${RECT}"]`).getByRole('button', { name: /Copy the call|kopieren/ }).click()
    const block = page.getByRole('menuitem', { name: /^Block/ })
    await expect(block).toHaveAttribute('aria-disabled', 'true')
    await expect(block).toContainText(/paths and polygons|Pfade und Polygone/)
  })

  test('the shortcode and the block, pasted into a page, both draw the icon', async ({ page }) => {
    await page.goto(LIBRARY)
    for (const content of [`<p>[easy_svg_icon name="easy-svg/${SLUG}" class="e2e-ruf"]</p>`, `<!-- wp:icon {"icon":"easy-svg/${SLUG}"} /-->`]) {
      const made = await wp(page, '/wp/v2/pages', { method: 'POST', body: { title: `E2E Ruf ${STAMP}`, status: 'publish', content } })
      expect(made.status).toBe(201)
      pages.push(made.body.id)
      const html = await (await page.request.get(made.body.link)).text()
      expect(html, content).toMatch(/<svg[^>]*>[\s\S]*?<path[^>]*d="M4 12h16/)
    }
  })

  test('an unknown name renders nothing, and no attribute gets through', async ({ page }) => {
    await page.goto(LIBRARY)
    const made = await wp(page, '/wp/v2/pages', {
      method: 'POST',
      body: { title: `E2E Ruf leer ${STAMP}`, status: 'publish', content: '<p>vor[easy_svg_icon name="easy-svg/gibt-es-nicht-xyz" onload="alert(1)"]nach</p>' },
    })
    pages.push(made.body.id)
    const html = await (await page.request.get(made.body.link)).text()
    expect(html).toContain('vornach')
    expect(html).not.toContain('alert(1)')
  })
})
