import { test, expect } from './fixtures.js'
import { PANEL } from './helpers.js'

/*
 * Nothing watched the rail's drawings until this file.
 *
 * `TabIcon` draws `PATHS[name] || null`, so a tab naming a keyword the map does
 * not have gets an `<svg>` with nothing inside it -- the right height, the
 * right label, and a blank square where the drawing belongs. Pro shipped three
 * of those (styles, bulk, history) because icons.jsx lives in the FREE plugin:
 * Pro could name a keyword but could not bring the drawing with it.
 */
const EMPTY_SVG = `${PANEL} [role="tab"] svg:not(:has(*))`

test('every tab in the rail carries a visible drawing', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=easy-svg-icons')
  await expect(page.locator(PANEL)).toBeVisible()
  await expect(page.locator(`${PANEL} [role="tab"]`).first()).toBeVisible()

  const empties = page.locator(EMPTY_SVG)
  const labels = await page
    .locator(`${PANEL} [role="tab"]:has(svg:not(:has(*)))`)
    .allTextContents()
  expect(await empties.count(), `tabs drawing an empty square: ${labels.join(', ')}`).toBe(0)
})

/*
 * The durable half. Filling the keyword map heals the three tabs that are
 * wrong today and nothing after them: the next Pro feature names a keyword
 * this plugin has never heard of and tears the same hole, needing a free
 * release to close it. So a tab may hand over its drawing instead of a name.
 *
 * Registered from the browser rather than from a second plugin: the registry is
 * a window global by design, which is precisely the surface being tested.
 */
test('a tab that brings its own drawing has it drawn', async ({ page }) => {
  await page.addInitScript(() => {
    // The panel bundle installs the registry; this polls for it rather than
    // guessing the order, and gives up instead of spinning forever.
    let tries = 0
    const plant = () => {
      const reg = window.EasySvgPanel
      if (!reg || !reg.registerTab) {
        if (++tries > 200) return
        setTimeout(plant, 25)
        return
      }
      const el = window.wp.element.createElement
      reg.registerTab({
        id: 'e2e-own-icon',
        label: 'E2E own icon',
        area: 'setup',
        order: 99,
        icon: el('circle', { cx: 12, cy: 12, r: 7, key: 'c' }),
        render: () => el('p', null, 'e2e'),
      })
    }
    plant()
  })

  await page.goto('/wp-admin/admin.php?page=easy-svg-icons')
  const tab = page.locator(`${PANEL} [role="tab"]`, { hasText: 'E2E own icon' })
  await expect(tab).toBeVisible()
  await expect(tab.locator('svg circle')).toBeVisible()
})
