import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, addIcon, sweepE2E, adminPage } from './helpers.js'

/*
 * Picking several icons, from any collections: they are moved together -- in
 * batches a provider takes, undone newest first -- or exported as one file.
 * Removing in bulk reaches what can be removed -- here, with a provider that
 * takes no removal, only the library's own icon -- and says how many.
 */

const SQUARE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="20" height="20" x="2" y="2"/></svg>'

async function provide(page, { exportMax = 100 } = {}) {
  await page.evaluate(
    ({ SQUARE, exportMax }) => {
      window.__calls = []
      const many = Array.from({ length: 30 }, (_, i) => ({ key: `e2e-a/i${i}`, slug: `i${i}`, ref: `e2e-a/i${i}`, label: `E2E A ${i}`, content: SQUARE }))
      const provider = {
        id: 'e2e',
        load: async () => [
          { id: 'e2e-a', label: 'E2E Set A', readonly: false, icons: many },
          { id: 'e2e-b', label: 'E2E Set B', readonly: false, icons: [] },
        ],
        move: async ({ refs, to }) => {
          const n = window.__calls.filter((c) => c.move).length + 1
          window.__calls.push({ move: { count: refs.length, to } })
          return {
            message: `batch ${n}: ${refs.length}`,
            undo: async () => {
              window.__calls.push({ undo: n })
              return { message: `back ${n}` }
            },
          }
        },
        exportMax,
        exportIcons: async ({ refs }) => {
          window.__calls.push({ export: refs })
          return { message: `${refs.length} exported` }
        },
      }
      const reg = window.EasySvgPanel
      const all = reg.collectionProviders.bind(reg)
      reg.collectionProviders = () => all().filter((p) => 'e2e' === p.id)
      reg.registerCollections(provider)
    },
    { SQUARE, exportMax }
  )
}

const calls = (page) => page.evaluate(() => window.__calls)
const said = (page) => page.locator(`${PANEL} [data-move-result]`)

test.describe('several icons at once', () => {
  test.beforeAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await addIcon(page, 'e2e-pick', SQUARE)
    await context.close()
  })
  test.afterAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await context.close()
  })

  test('thirty picked move in two batches, and Undo takes both back, newest first', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await page.locator(`${PANEL} [data-collections]`).getByRole('button', { name: /^E2E Set A/ }).click()
    await page.getByRole('button', { name: /Select several|Mehrere auswählen/ }).click()
    await page.getByRole('button', { name: /^(Select all|Alle auswählen)$/ }).click()
    await page.getByRole('button', { name: /Move to…|Verschieben nach…/ }).click()
    await page.getByRole('menuitem', { name: 'E2E Set B' }).click()
    await expect(said(page)).toContainText('batch 1: 25 · batch 2: 5')
    await said(page).getByRole('button', { name: /Undo|Rückgängig/ }).click()
    await expect(said(page)).toContainText('back 2 · back 1')
    expect(await calls(page)).toEqual([
      { move: { count: 25, to: 'e2e-b' } },
      { move: { count: 5, to: 'e2e-b' } },
      { undo: 2 },
      { undo: 1 },
    ])
  })

  test('a library icon and a set icon are exported together; Remove reaches only the library one', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await page.getByRole('button', { name: /Select several|Mehrere auswählen/ }).click()
    await page.locator(`${PANEL} [data-icon-key="easy-svg/e2e-pick"]`).locator('[data-scope="checkbox"][data-part="control"]').click()
    await page.locator(`${PANEL} [data-icon-key="e2e-a/i0"]`).locator('[data-scope="checkbox"][data-part="control"]').click()
    await expect(page.getByRole('button', { name: /^(Remove 1|1 entfernen)$/ })).toBeVisible()
    await page.getByRole('button', { name: /^(Export|Exportieren)$/ }).click()
    await expect(said(page)).toContainText('2 exported')
    expect(await calls(page)).toEqual([{ export: ['easy-svg/e2e-pick', 'e2e-a/i0'] }])
  })

  test('only set icons of a provider without removal picked: no Remove; past the export cap: no Export', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page, { exportMax: 1 })
    await page.getByRole('button', { name: /Select several|Mehrere auswählen/ }).click()
    await page.locator(`${PANEL} [data-icon-key="e2e-a/i0"]`).locator('[data-scope="checkbox"][data-part="control"]').click()
    await page.locator(`${PANEL} [data-icon-key="e2e-a/i1"]`).locator('[data-scope="checkbox"][data-part="control"]').click()
    await expect(page.getByRole('button', { name: /^(Remove|Entfernen)|^\d+ entfernen$/ })).toHaveCount(0)
    await expect(page.getByRole('button', { name: /^(Export|Exportieren)$/ })).toBeDisabled()
  })
})
