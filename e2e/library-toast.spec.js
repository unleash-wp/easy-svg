import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, E2E_PREFIX, sweepE2E, adminPage, addIcon } from './helpers.js'

/*
 * #108 M6: what an action says arrives as a toast, not above the grid.
 *
 * "Icon renamed." and a move's result were lines inserted over the grid, so
 * every action pushed the icons down by a line and back up when the line
 * went: the tile somebody had just used jumped away. The line is a toast now,
 * over the page, with its Undo, and it can be closed.
 */
const NAME = `${E2E_PREFIX}toast`

test.describe('what an action says', () => {
  const sweep = async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await context.close()
  }
  test.beforeAll(sweep)
  test.afterAll(sweep)

  test('arrives as a toast and moves no tile', async ({ page }) => {
    await page.goto(LIBRARY)
    const { slug } = await addIcon(page, NAME)
    await page.reload()

    const tile = page.locator(`${PANEL} [data-icon-key="easy-svg/${slug}"]`)
    await expect(tile).toBeVisible()
    // In page coordinates: pressing a button may scroll it into view first.
    const top = async () => tile.evaluate((e) => Math.round(e.getBoundingClientRect().top + window.scrollY))
    const before = await top()

    await tile.getByRole('button', { name: new RegExp(`Rename ${NAME}|${NAME} umbenennen`) }).click()
    const field = page.getByRole('textbox', { name: new RegExp(`(New name for|Neuer Name für) ${NAME}`) })
    await field.fill(`${NAME}-neu`)
    await field.press('Enter')

    const toast = page.locator(`${PANEL} [data-toast]`).filter({ hasText: /Icon renamed\.|Icon umbenannt\./ })
    await expect(toast).toBeVisible()
    await expect(toast).toHaveCSS('position', 'fixed')
    expect(await top(), 'the tile moved when the message appeared').toBe(before)

    await toast.getByRole('button', { name: /Dismiss|Schließen|Ausblenden/ }).click()
    await expect(toast).toBeHidden()
    expect(await top()).toBe(before)
  })
})
