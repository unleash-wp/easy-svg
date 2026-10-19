import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, addIcon, sweepE2E, adminPage } from './helpers.js'

/*
 * Panel contract 3, moving: a tile dropped on a collection on the left -- or
 * sent there through its "Move to" menu, the way that needs no mouse -- goes
 * to the add-on's move(), and the answer is said with a way back.
 * A read-only collection is never a target.
 */

const SQUARE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="20" height="20" x="2" y="2"/></svg>'

async function provide(page) {
  await page.evaluate((SQUARE) => {
    window.__moves = []
    window.__undos = 0
    window.EasySvgPanel.registerCollections({
      id: 'e2e',
      load: async () => [
        { id: 'e2e-a', label: 'E2E Set A', readonly: false, icons: [{ key: 'e2e-a/star', slug: 'star', ref: 'e2e-a/star', label: 'E2E A Star', content: SQUARE }] },
        { id: 'e2e-b', label: 'E2E Set B', readonly: false, icons: [] },
        { id: 'e2e-t', label: 'E2E Theme', readonly: true, icons: [] },
      ],
      move: async ({ refs, to }) => {
        window.__moves.push({ refs, to })
        return {
          message: `Moved ${refs.length} · 3 places updated`,
          undo: async () => {
            window.__undos++
            return { message: 'Moved back' }
          },
        }
      },
    })
  }, SQUARE)
}

const nav = (page, name) => page.locator(`${PANEL} [data-collections]`).getByRole('button', { name })

test.describe('moving onto a collection', () => {
  test.beforeAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await addIcon(page, 'e2e-zug', SQUARE)
    await context.close()
  })
  test.afterAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await context.close()
  })

  test('a tile dropped on a set goes there, and the way back works', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    const tile = page.locator(`${PANEL} [data-icon-key="e2e-a/star"]`)
    await tile.dragTo(nav(page, /E2E Set B/))

    expect(await page.evaluate(() => window.__moves)).toEqual([{ refs: ['e2e-a/star'], to: 'e2e-b' }])
    const said = page.locator(`${PANEL} [data-move-result]`)
    await expect(said).toContainText('Moved 1 · 3 places updated')
    await said.getByRole('button', { name: /Undo|Rückgängig/ }).click()
    await expect.poll(() => page.evaluate(() => window.__undos)).toBe(1)
    await expect(said).toContainText('Moved back')
  })

  test('a read-only collection is no target', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await page.locator(`${PANEL} [data-icon-key="e2e-a/star"]`).dragTo(nav(page, /E2E Theme/))
    expect(await page.evaluate(() => window.__moves)).toEqual([])
  })

  test('the Move-to menu does the same without a mouse', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    const tile = page.locator(`${PANEL} [data-icon-key="e2e-a/star"]`)
    // In the tile's More menu, as a group of its own.
    await tile.getByRole('button', { name: /More for|Mehr für/ }).click()
    const menu = page.getByRole('menu')
    // Its own collection and a read-only one are not offered.
    await expect(menu.getByRole('menuitem', { name: /E2E Set A/ })).toHaveCount(0)
    await expect(menu.getByRole('menuitem', { name: /E2E Theme/ })).toHaveCount(0)
    await menu.getByRole('menuitem', { name: /E2E Set B/ }).click()
    expect(await page.evaluate(() => window.__moves)).toEqual([{ refs: ['e2e-a/star'], to: 'e2e-b' }])
  })

  test('a library icon goes by its free address', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await page.locator(`${PANEL} [data-icon-key="easy-svg/e2e-zug"]`).dragTo(nav(page, /E2E Set B/))
    expect(await page.evaluate(() => window.__moves)).toEqual([{ refs: ['easy-svg/e2e-zug'], to: 'e2e-b' }])
  })
})
