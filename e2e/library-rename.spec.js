import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, E2E_PREFIX, sweepE2E, adminPage, addIcon, listIcons } from './helpers.js'

/*
 * #108 M5: renaming an icon leaves the grid where it is.
 *
 * The name field opened inside the tile, which then took two columns and
 * pushed every tile after it along: the icon somebody was looking at moved
 * away from the pointer. The field is a popover on the rename button now.
 */
const NAME = `${E2E_PREFIX}umbenennen`
const NEW = `${E2E_PREFIX}umbenannt`

test.describe('renaming an icon', () => {
  const sweep = async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await context.close()
  }
  test.beforeAll(sweep)
  test.afterAll(sweep)

  test('opens beside the tile, moves no tile, and saves on Enter', async ({ page }) => {
    await page.goto(LIBRARY)
    const { slug } = await addIcon(page, NAME)
    await page.reload()

    const tiles = page.locator(`${PANEL} [data-icon-key]`)
    await expect(page.locator(`${PANEL} [data-icon-key="easy-svg/${slug}"]`)).toBeVisible()
    const boxes = async () => (await tiles.evaluateAll((els) => els.map((e) => e.getBoundingClientRect())))
      .map((r) => [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)].join(','))
    const before = await boxes()

    await page.locator(`${PANEL} [data-icon-key="easy-svg/${slug}"]`).getByRole('button', { name: new RegExp(`(Rename|umbenennen).*${NAME}|${NAME}.*umbenennen`, 'i') }).click()
    const field = page.getByRole('textbox', { name: new RegExp(`(New name for|Neuer Name für) ${NAME}`) })
    await expect(field).toBeVisible()
    await expect(field).toBeFocused()
    expect(await boxes(), 'a tile moved when the name field opened').toEqual(before)

    await field.fill(NEW)
    await field.press('Enter')
    await expect(field).toBeHidden()
    await expect(page.locator(`${PANEL} [data-icon-key="easy-svg/${slug}"]`)).toContainText(NEW)
    expect((await listIcons(page)).find((i) => i.slug === slug)?.label).toBe(NEW)
  })

  test('Escape closes it and changes nothing', async ({ page }) => {
    await page.goto(LIBRARY)
    const { slug } = await addIcon(page, `${NAME}-esc`)
    await page.reload()

    await page.locator(`${PANEL} [data-icon-key="easy-svg/${slug}"]`).getByRole('button', { name: new RegExp(`Rename ${NAME}-esc|${NAME}-esc umbenennen`) }).click()
    const field = page.getByRole('textbox', { name: /New name for|Neuer Name für/ })
    await field.fill('nope')
    await field.press('Escape')
    await expect(field).toBeHidden()
    expect((await listIcons(page)).find((i) => i.slug === slug)?.label).toBe(`${NAME}-esc`)
  })
})
