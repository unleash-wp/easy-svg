import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, addIcon, listIcons, sweepE2E, adminPage } from './helpers.js'

/*
 * Picked set icons leave their sets in bulk, as the set editor's own bulk
 * Delete did: one remove per collection in bounded batches, said with Undo.
 * Picked library icons beside them still ask first, because those cannot be
 * brought back.
 */

const SQUARE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M2 2h20v20H2z"/></svg>'

async function provide(page, many = 2) {
  await page.evaluate(
    ({ SQUARE, many }) => {
      window.__calls = []
      const icon = (set, slug) => ({ key: `${set}/${slug}`, slug, ref: `${set}/${slug}`, label: `E2E ${slug}`, content: SQUARE })
      const reg = window.EasySvgPanel
      const all = reg.collectionProviders.bind(reg)
      reg.collectionProviders = () => all().filter((p) => 'e2e' === p.id)
      reg.registerCollections({
        id: 'e2e',
        load: async () => [
          { id: 'e2e-s', label: 'E2E Set S', readonly: false, set_id: 61, icons: Array.from({ length: many }, (_, i) => icon('e2e-s', `i${i}`)) },
          { id: 'e2e-t', label: 'E2E Set T', readonly: false, set_id: 62, icons: [icon('e2e-t', 'x')] },
          { id: 'e2e-f', label: 'E2E Folder', readonly: true, icons: [icon('e2e-f', 'y')] },
        ],
        move: async () => ({ message: 'moved' }),
        remove: async ({ refs, collection }) => {
          window.__calls.push({ remove: refs, setId: collection.set_id })
          return { message: `${refs.length} gone from ${collection.id}`, undo: async () => ({ message: `${refs.length} back` }) }
        },
      })
    },
    { SQUARE, many }
  )
}

const tile = (page, key) => page.locator(`${PANEL} [data-icon-key="${key}"]`)
const pick = (page, key) => tile(page, key).locator('[data-scope="checkbox"][data-part="control"]').click()
const calls = (page) => page.evaluate(() => window.__calls)
const said = (page) => page.locator(`${PANEL} [data-move-result]`)
const selectSeveral = (page) => page.getByRole('button', { name: /Select several|Mehrere auswählen/ }).click()
const removeButton = (page) => page.getByRole('button', { name: /^(Remove|Entfernen)|^\d+ entfernen$/ })

test.describe('removing picked icons', () => {
  test.beforeAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await addIcon(page, 'e2e-bulk', SQUARE)
    await context.close()
  })
  test.afterAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await context.close()
  })

  test('set icons from two sets leave them, one call per set, with Undo', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await selectSeveral(page)
    for (const key of ['e2e-s/i0', 'e2e-s/i1', 'e2e-t/x', 'e2e-f/y']) {
      await pick(page, key)
    }
    // The folder icon is read only: three of the four picks can go.
    await expect(removeButton(page)).toHaveText(/Remove 3|3 entfernen/)
    await removeButton(page).click()
    await expect(page.getByRole('alertdialog'), 'set icons come back with Undo, so nothing asks').toHaveCount(0)
    await expect(said(page)).toContainText('2 gone from e2e-s · 1 gone from e2e-t')
    expect(await calls(page)).toEqual([
      { remove: ['e2e-s/i0', 'e2e-s/i1'], setId: 61 },
      { remove: ['e2e-t/x'], setId: 62 },
    ])
    await said(page).getByRole('button', { name: /Undo|Rückgängig/ }).click()
    await expect(said(page)).toContainText(/Undone|Rückgängig gemacht/)
  })

  test('a big selection goes in batches the provider takes', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page, 27)
    await page.locator(`${PANEL} [data-collections]`).getByRole('button', { name: /^E2E Set S/ }).click()
    await selectSeveral(page)
    await page.getByRole('button', { name: /^(Select all|Alle auswählen)/ }).click()
    await removeButton(page).click()
    await expect(said(page)).toContainText('25 gone from e2e-s · 2 gone from e2e-s')
    expect((await calls(page)).map((c) => c.remove.length)).toEqual([25, 2])
  })

  test('library icons in the pick still ask, then both go', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    const before = (await listIcons(page)).length
    await selectSeveral(page)
    await pick(page, 'easy-svg/e2e-bulk')
    await pick(page, 'e2e-s/i0')
    await removeButton(page).click()
    const dialog = page.getByRole('alertdialog')
    await expect(dialog).toContainText(/Remove 2 icons|2 Icons entfernen/)
    await dialog.getByRole('button', { name: /^(Delete|Löschen)$/ }).click()
    await expect(said(page)).toContainText('1 gone from e2e-s')
    // Both halves said in one toast: the library half must not replace the
    // set half and take its Undo with it.
    await expect(said(page)).toContainText(/1 icon removed\.|1 Icon entfernt\./)
    expect(await calls(page)).toEqual([{ remove: ['e2e-s/i0'], setId: 61 }])
    await expect.poll(async () => (await listIcons(page)).length).toBe(before - 1)
  })
})
