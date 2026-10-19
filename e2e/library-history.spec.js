import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, addIcon, sweepE2E, adminPage } from './helpers.js'

/*
 * Panel contract 3, an icon's earlier drawings: a provider with history and
 * restoreVersion gets a History action on the free library's tiles (asked with
 * no collection) and on its own writable collections' tiles. The version in
 * use is marked, never offered back.
 */

const SQUARE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="20" height="20" x="2" y="2"/></svg>'
const CIRCLE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/></svg>'

async function provide(page, { history = true } = {}) {
  await page.evaluate(
    ({ SQUARE, CIRCLE, history }) => {
      window.__calls = []
      const provider = {
        id: 'e2e',
        load: async () => [
          { id: 'e2e-h', label: 'E2E Set H', readonly: false, set_id: 31, icons: [{ key: 'e2e-h/dot', slug: 'dot', ref: 'e2e-h/dot', label: 'E2E Dot', content: CIRCLE }] },
          { id: 'e2e-t', label: 'E2E Theme', readonly: true, icons: [{ key: 'e2e-t/leaf', slug: 'leaf', ref: 'e2e-t/leaf', label: 'E2E Leaf', content: SQUARE }] },
        ],
      }
      if (history) {
        provider.history = async ({ ref, collection }) => {
          window.__calls.push({ history: { ref, setId: collection ? collection.set_id : null } })
          return {
            versions: [
              { id: 902, date: '2026-10-19 10:00:00', since: '2026-10-19 10:00:00', markup: SQUARE, current: true },
              { id: 901, date: '2026-10-18 09:00:00', since: '2026-10-17 08:00:00', markup: CIRCLE, current: false },
            ],
          }
        }
        provider.restoreVersion = async ({ ref, version, collection }) => {
          window.__calls.push({ restore: { ref, version, setId: collection ? collection.set_id : null } })
          return { message: 'Restored. The icon keeps its name.' }
        }
      }
      // Only this provider, so Pro's real one cannot answer instead.
      const reg = window.EasySvgPanel
      const all = reg.collectionProviders.bind(reg)
      reg.collectionProviders = () => all().filter((p) => 'e2e' === p.id)
      reg.registerCollections(provider)
    },
    { SQUARE, CIRCLE, history }
  )
}

const tile = (page, key) => page.locator(`${PANEL} [data-icon-key="${key}"]`)
const calls = (page) => page.evaluate(() => window.__calls)
const historyButton = (t) => t.getByRole('button', { name: /Earlier versions of|Frühere Versionen/ })

test.describe('an icon\'s earlier drawings', () => {
  test.beforeAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await addIcon(page, 'e2e-hist', SQUARE)
    await context.close()
  })
  test.afterAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await context.close()
  })

  test('a library icon\'s versions are listed, the one in use marked, an older one restored', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await historyButton(tile(page, 'easy-svg/e2e-hist')).click()
    const dialog = page.locator(`${PANEL} [data-icon-history]`)
    await expect(dialog.locator('[data-version]')).toHaveCount(2)
    await expect(dialog.locator('[data-version="902"]')).toContainText(/In use|In Verwendung/)
    await expect(dialog.locator('[data-version="902"]').getByRole('button')).toHaveCount(0)
    // The preview is drawn, sanitised.
    await expect(dialog.locator('[data-version="901"] svg circle')).toHaveCount(1)
    await dialog.locator('[data-version="901"]').getByRole('button', { name: /Restore|Wiederherstellen/ }).click()
    await expect(page.locator(`${PANEL} [data-move-result]`)).toContainText('Restored. The icon keeps its name.')
    expect(await calls(page)).toEqual([
      { history: { ref: 'easy-svg/e2e-hist', setId: null } },
      { restore: { ref: 'easy-svg/e2e-hist', version: 901, setId: null } },
    ])
  })

  test('a set icon asks its own provider, with its collection', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await historyButton(tile(page, 'e2e-h/dot')).click()
    await expect(page.locator(`${PANEL} [data-icon-history] [data-version]`)).toHaveCount(2)
    expect(await calls(page)).toEqual([{ history: { ref: 'e2e-h/dot', setId: 31 } }])
  })

  test('no history on a read-only collection, and none without a provider that keeps it', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await expect(tile(page, 'e2e-t/leaf')).toBeVisible()
    await expect(historyButton(tile(page, 'e2e-t/leaf'))).toHaveCount(0)

    await page.goto(LIBRARY)
    await provide(page, { history: false })
    await expect(tile(page, 'easy-svg/e2e-hist')).toBeVisible()
    await expect(historyButton(tile(page, 'easy-svg/e2e-hist'))).toHaveCount(0)
  })
})
