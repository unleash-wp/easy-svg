import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, addIcon, sweepE2E, adminPage, listIcons } from './helpers.js'

/*
 * The selection is the destructive path, and it had the worst defect of the
 * round: "select all" with a full library, then a search, left every id that
 * was no longer on screen selected -- the bar counted them and Remove deleted
 * them. Nothing short of driving the real screen can catch that.
 */
test.describe('selecting icons', () => {
  test.beforeAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    for (const label of ['E2E Alpha', 'E2E Beta', 'E2E Gamma']) {
      await addIcon(page, label)
    }
    await context.close()
  })

  test.afterAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await context.close()
  })

  test('a search prunes a selection made before it', async ({ page }) => {
    await page.goto(LIBRARY)
    await page.getByRole('button', { name: /Mehrere auswählen|Select several/i }).click()
    await page.getByRole('button', { name: /^Alle auswählen|^Select all/i }).click()

    const bar = page.getByText(/\d+ Icons? ausgewählt|\d+ icons? selected/i)
    await expect(bar).toBeVisible()
    const before = Number((await bar.innerText()).match(/\d+/)[0])
    expect(before, 'the whole library is selected').toBeGreaterThanOrEqual(3)

    /*
     * Narrow the list. What stays selected must be what is on screen: the
     * defect this pins reported the OLD count here, and Remove acted on it.
     */
    await page.locator('#esw-icon-search').fill('E2E Alpha')
    await expect(page.getByText(/1 Icon gefunden|1 icon found/i)).toBeVisible()
    await expect(bar).toHaveText(/^1 Icon ausgewählt|^1 icon selected/i)

    // And a search that matches none of them leaves nothing selected at all.
    await page.locator('#esw-icon-search').fill('zzz-nichts-davon')
    await expect(page.getByText(/0 Icons gefunden|0 icons found/i)).toBeVisible()
    await expect(bar).toHaveCount(0)
  })

  test('removing a selection takes exactly what was selected', async ({ page }) => {
    await page.goto(LIBRARY)
    const before = (await listIcons(page)).length

    await page.locator('#esw-icon-search').fill('E2E Beta')
    await expect(page.getByText(/1 Icon gefunden|1 icon found/i)).toBeVisible()
    await page.getByRole('button', { name: /Mehrere auswählen|Select several/i }).click()
    await page.getByRole('button', { name: /^Alle auswählen|^Select all/i }).click()
    await expect(page.getByText(/1 Icon ausgewählt|1 icon selected/i)).toBeVisible()

    await page.getByRole('button', { name: /^Entfernen|^Remove/i }).click()
    // A second click, deliberately: removing is irreversible and the panel asks.
    await page.getByRole('button', { name: /^Entfernen|^Remove|Löschen|Delete/i }).last().click()

    await expect(page.getByText(/1 Icon entfernt|1 icon removed/i)).toBeVisible()
    const after = (await listIcons(page)).length
    expect(after, 'exactly one icon is gone').toBe(before - 1)
  })
})
