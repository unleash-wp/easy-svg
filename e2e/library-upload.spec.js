import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, E2E_PREFIX, sweepE2E, adminPage, listIcons } from './helpers.js'

/*
 * Choosing a file is how icons actually get into the library, and it was the
 * one path nothing ran: every other spec seeds through /library/add, which
 * starts where this one ends. What only this covers is the browser's half --
 * reading the file, taking the label off the filename, and refusing what is
 * not an SVG before anything is stored.
 */
const CIRCLE =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/></svg>'

const file = (name, data, type = 'image/svg+xml') => ({
  name,
  mimeType: type,
  buffer: Buffer.from(data),
})

test.describe('adding an icon by choosing a file', () => {
  test.beforeAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await context.close()
  })

  test.afterAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await context.close()
  })

  test('the file lands in the library under its own name', async ({ page }) => {
    await page.goto(LIBRARY)
    const before = (await listIcons(page)).length

    await page
      .locator(`${PANEL} input[type="file"]`)
      .first()
      .setInputFiles(file(`${E2E_PREFIX}kreis.svg`, CIRCLE))

    // The label is the filename without .svg, and the report says what happened.
    await expect(page.locator(PANEL).getByText(`${E2E_PREFIX}kreis`, { exact: true })).toBeVisible()

    const after = await listIcons(page)
    expect(after.length).toBe(before + 1)
    const added = after.find((i) => i.label === `${E2E_PREFIX}kreis`)
    expect(added, 'the icon the upload stored').toBeTruthy()
    expect(added.content).toContain('<circle')
  })

  test('a file that is not an SVG is refused and nothing is stored', async ({ page }) => {
    await page.goto(LIBRARY)
    const before = (await listIcons(page)).length

    await page
      .locator(`${PANEL} input[type="file"]`)
      .first()
      .setInputFiles(file(`${E2E_PREFIX}kein-svg.txt`, 'plain text, no markup', 'text/plain'))

    // Whatever the wording, the count may not move.
    await page.waitForTimeout(1500)
    expect((await listIcons(page)).length).toBe(before)
  })
})
