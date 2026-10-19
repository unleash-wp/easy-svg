import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, listIcons } from './helpers.js'

/*
 * Panel contract 3, the icons inside a collection: where the provider takes
 * relabel, remove and add, a set's tiles are renamed and removed in the grid,
 * and a file chosen while the set is on screen goes into that set -- not into
 * the library. A read-only collection's tiles offer none of it.
 */

const SQUARE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="20" height="20" x="2" y="2"/></svg>'

async function provide(page) {
  await page.evaluate((SQUARE) => {
    window.__calls = []
    const star = { key: 'e2e-s/star', slug: 'star', ref: 'e2e-s/star', label: 'E2E Star', content: SQUARE }
    window.__sets = [
      { id: 'e2e-s', label: 'E2E Set S', readonly: false, set_id: 21, icons: [star] },
      { id: 'e2e-t', label: 'E2E Theme', readonly: true, icons: [{ key: 'e2e-t/leaf', slug: 'leaf', ref: 'e2e-t/leaf', label: 'E2E Leaf', content: SQUARE }] },
    ]
    const set = () => window.__sets[0]
    window.EasySvgPanel.registerCollections({
      id: 'e2e',
      load: async () => window.__sets.map((c) => ({ ...c, icons: c.icons.map((i) => ({ ...i })) })),
      relabel: async ({ ref, label, collection }) => {
        window.__calls.push({ relabel: { ref, label, setId: collection.set_id } })
        set().icons.find((i) => i.ref === ref).label = label
        return { message: `Renamed to ${label}` }
      },
      remove: async ({ refs, collection }) => {
        window.__calls.push({ remove: { refs, setId: collection.set_id } })
        const gone = set().icons.filter((i) => refs.includes(i.ref))
        set().icons = set().icons.filter((i) => !refs.includes(i.ref))
        return {
          message: `${gone.length} removed · 2 places still call it`,
          undo: async () => {
            window.__calls.push({ unremove: refs })
            set().icons.push(...gone)
            return { message: 'Put back' }
          },
        }
      },
      add: async ({ icons, to, collection }) => {
        window.__calls.push({ add: { names: icons.map((i) => i.name), to, setId: collection.set_id, svg: icons.every((i) => i.markup.includes('<svg')) } })
        icons.forEach((i) => set().icons.push({ key: `e2e-s/${i.name}`, slug: i.name, ref: `e2e-s/${i.name}`, label: i.name, content: i.markup }))
        return { message: `${icons.length} added to E2E Set S`, rows: icons.map((i) => ({ file: i.name, ok: true, message: 'Added.' })) }
      },
    })
  }, SQUARE)
}

const nav = (page) => page.locator(`${PANEL} [data-collections]`)
const calls = (page) => page.evaluate(() => window.__calls)
const said = (page) => page.locator(`${PANEL} [data-move-result]`)

test.describe('icons inside a set, in the grid', () => {
  test('a set icon is renamed in place', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    const tile = page.locator(`${PANEL} [data-icon-key="e2e-s/star"]`)
    await tile.getByRole('button', { name: /Rename E2E Star|E2E Star umbenennen/ }).click()
    const field = tile.getByRole('textbox')
    await field.fill('E2E Shiny')
    await field.press('Enter')
    expect(await calls(page)).toEqual([{ relabel: { ref: 'e2e-s/star', label: 'E2E Shiny', setId: 21 } }])
    await expect(said(page)).toContainText('Renamed to E2E Shiny')
  })

  test('a set icon is removed without a second click, and Undo puts it back', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    const tile = page.locator(`${PANEL} [data-icon-key="e2e-s/star"]`)
    await tile.getByRole('button', { name: /Delete E2E Star|E2E Star löschen/ }).click()
    await expect(page.getByRole('dialog')).toHaveCount(0)
    await expect(said(page)).toContainText('1 removed · 2 places still call it')
    await expect(tile).toHaveCount(0)
    await said(page).getByRole('button', { name: /Undo|Rückgängig/ }).click()
    await expect(said(page)).toContainText('Put back')
    await expect(tile).toBeVisible()
    expect(await calls(page)).toEqual([{ remove: { refs: ['e2e-s/star'], setId: 21 } }, { unremove: ['e2e-s/star'] }])
  })

  test('a file chosen while the set is on screen goes into the set, not the library', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    const before = (await listIcons(page)).length
    await nav(page).getByRole('button', { name: /^E2E Set S/ }).click()
    await page
      .locator(`${PANEL} input[type="file"]`)
      .first()
      .setInputFiles({ name: 'e2e-into-set.svg', mimeType: 'image/svg+xml', buffer: Buffer.from(SQUARE) })
    await expect(said(page)).toContainText('1 added to E2E Set S')
    expect(await calls(page)).toEqual([{ add: { names: ['e2e-into-set'], to: 'e2e-s', setId: 21, svg: true } }])
    await expect(page.locator(`${PANEL} [data-icon-key="e2e-s/e2e-into-set"]`)).toBeVisible()
    expect((await listIcons(page)).length, 'the library got nothing').toBe(before)
  })

  test('a read-only collection tile has no rename and no delete', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    const tile = page.locator(`${PANEL} [data-icon-key="e2e-t/leaf"]`)
    await expect(tile).toBeVisible()
    await expect(tile.getByRole('button', { name: /Rename|umbenennen/ })).toHaveCount(0)
    await expect(tile.getByRole('button', { name: /Delete|löschen/ })).toHaveCount(0)
  })
})
