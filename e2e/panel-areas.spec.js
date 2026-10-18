import { test, expect } from './fixtures.js'
import { PANEL } from './helpers.js'

/*
 * Eleven tabs under two eyebrows reading "General" and "Pro" tell a reader
 * which HALF OF THE PRODUCT a tab was bought with, which is not a question
 * anybody has while working. The rail says what each group is FOR instead:
 * five areas, in a fixed order, and an area with no tabs draws no heading.
 *
 * The eyebrows are aria-hidden -- the tablist stays a clean list of tabs for
 * assistive tech -- so these read the rail's text rather than roles.
 */
/*
 * Matched loosely because the rail is drawn in the site's language and only
 * some of these are translated yet. In rail order.
 */
const AREAS = [/^icons$/i, /^(design|gestalten)$/i, /^(inspect|pr.fen)$/i, /^(security|sicherheit)$/i, /^(setup|einrichtung)$/i]

/*
 * The headings themselves, not the rail's text.
 *
 * Reading innerText and looking for words in it cannot tell a heading from a
 * tab: on an unlicensed site every catalogue tab carries a "Pro" badge, so the
 * rail's text contains "Pro" on its own line and an assertion that the old
 * "Pro" band is gone fails on the badge instead. The headings are the
 * tablist's own aria-hidden children.
 */
const eyebrows = (page) => page.locator(`${PANEL} [role="tablist"] > [aria-hidden="true"]`)

test('the rail is cut into areas, not into general and pro', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=easy-svg-icons')
  await expect(page.locator(`${PANEL} [role="tab"]`).first()).toBeVisible()

  const shown = (await eyebrows(page).allTextContents()).map((t) => t.trim()).filter(Boolean)
  expect(shown.length, `headings in the rail: ${shown.join(', ')}`).toBeGreaterThanOrEqual(3)

  for (const heading of shown) {
    expect(heading, 'a band, not an area').not.toMatch(/^(general|allgemein|pro)$/i)
    expect(AREAS.some((a) => a.test(heading)), `${heading} is not one of the five areas`).toBe(true)
  }

  // Fixed order, whichever subset happens to have tabs on this site.
  const positions = shown.map((h) => AREAS.findIndex((a) => a.test(h)))
  expect(positions, `headings out of order: ${shown.join(', ')}`).toEqual([...positions].sort((x, y) => x - y))
})

test('the page heading is the area, not a fixed word', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=easy-svg-icons&tab=library')
  await expect(page.locator(`${PANEL} h1`)).toHaveText(/icons/i)

  await page.goto('/wp-admin/admin.php?page=easy-svg-icons&tab=settings')
  await expect(page.locator(`${PANEL} h1`)).toHaveText(/security|sicherheit/i)
})

/*
 * The case this exists for: a Pro built against contract 1 names `group: 'pro'`
 * and no area at all. Dropping those tabs would take paid features off the
 * screen on a site that merely updated the free plugin first.
 */
test('a tab that names no area is placed, not dropped', async ({ page }) => {
  await page.addInitScript(() => {
    let tries = 0
    const plant = () => {
      const reg = window.EasySvgPanel
      if (!reg || !reg.registerTab) {
        if (++tries > 200) return
        setTimeout(plant, 25)
        return
      }
      /*
       * Set HERE, not at the top of this script: wp_localize_script emits its
       * own `var EasySvgPanelData = {…}`, which replaces anything assigned
       * before it runs. By now it has run, App holds that same object by
       * reference, and registerTab's notify re-renders with it.
       *
       * It flips the RAIL's flag so the branch under test is reached -- the
       * rail draws a tab it does not know only when the add-on is licensed,
       * and the free plugin's own CI runs without the add-on at all. It buys
       * nothing else: every REST route checks the licence server-side.
       */
      window.EasySvgPanelData.proLicensed = true
      const el = window.wp.element.createElement
      reg.registerTab({
        id: 'e2e-no-area',
        label: 'E2E no area',
        group: 'pro',
        order: 70,
        icon: 'features',
        render: () => el('p', null, 'e2e'),
      })
    }
    plant()
  })

  await page.goto('/wp-admin/admin.php?page=easy-svg-icons&tab=e2e-no-area')
  const tab = page.locator(`${PANEL} [role="tab"]`, { hasText: 'E2E no area' })
  await expect(tab).toBeVisible()
  await expect(page.locator(`${PANEL} h1`)).toHaveText(/setup|einrichtung/i)
})
