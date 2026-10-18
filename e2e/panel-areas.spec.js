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
const AREAS = ['Icons', 'Design', 'Inspect', 'Security', 'Setup']

async function eyebrows(page) {
  const text = await page.locator(`${PANEL} [role="tablist"]`).innerText()
  const upper = text.toUpperCase()
  return AREAS.filter((a) => upper.includes(a.toUpperCase() + '\n') || upper.endsWith(a.toUpperCase()))
}

test('the rail is cut into areas, not into general and pro', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=easy-svg-icons')
  await expect(page.locator(`${PANEL} [role="tab"]`).first()).toBeVisible()

  const text = (await page.locator(`${PANEL} [role="tablist"]`).innerText()).toUpperCase()
  expect(text, 'the old bands are gone').not.toContain('GENERAL\n')
  expect(text, 'the old bands are gone').not.toContain('PRO\n')

  const shown = await eyebrows(page)
  expect(shown.length, `areas in the rail: ${shown.join(', ')}`).toBeGreaterThanOrEqual(3)
  // Fixed order, whichever subset happens to have tabs on this site.
  expect(shown).toEqual(AREAS.filter((a) => shown.includes(a)))
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
