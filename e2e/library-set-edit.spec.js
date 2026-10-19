import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, addIcon, sweepE2E, adminPage } from './helpers.js'

/*
 * A set icon's drawing, changed in the grid: recoloured to take the text
 * colour, or replaced by a new file -- each through the provider, with Undo.
 * And a set tile carries more actions than one row of a 110px tile holds, so
 * none of them may end up outside it.
 */

const SQUARE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#f00" d="M2 2h20v20H2z"/></svg>'
const CIRCLE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/></svg>'

async function provide(page) {
  await page.evaluate((SQUARE) => {
    window.__calls = []
    const icon = (slug) => ({ key: `e2e-s/${slug}`, slug, ref: `e2e-s/${slug}`, label: `E2E ${slug}`, content: SQUARE })
    const reg = window.EasySvgPanel
    const all = reg.collectionProviders.bind(reg)
    reg.collectionProviders = () => all().filter((p) => 'e2e' === p.id)
    reg.registerCollections({
      id: 'e2e',
      load: async () => [
        { id: 'e2e-s', label: 'E2E Set S', readonly: false, set_id: 41, icons: [icon('a'), icon('b')] },
        { id: 'e2e-b', label: 'E2E Set B', readonly: false, set_id: 42, icons: [] },
      ],
      move: async () => ({ message: 'moved' }),
      relabel: async () => ({ message: 'renamed' }),
      remove: async () => ({ message: 'removed' }),
      history: async () => ({ versions: [] }),
      restoreVersion: async () => ({ message: 'restored' }),
      recolour: async ({ refs, collection }) => {
        window.__calls.push({ recolour: { refs, setId: collection.set_id } })
        return { message: `${refs.length} recoloured`, undo: async () => ({ message: 'colour back' }) }
      },
      replaceDrawing: async ({ ref, markup, collection }) => {
        window.__calls.push({ replace: { ref, circle: markup.includes('<circle'), setId: collection.set_id } })
        return { message: 'new drawing', undo: async () => ({ message: 'drawing back' }) }
      },
    })
  }, SQUARE)
}

const tile = (page, key) => page.locator(`${PANEL} [data-icon-key="${key}"]`)
const calls = (page) => page.evaluate(() => window.__calls)
const said = (page) => page.locator(`${PANEL} [data-move-result]`)
const more = (t) => t.getByRole('button', { name: /More for|Mehr für/ })

test.describe('a set icon\'s drawing, in the grid', () => {
  test.beforeAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await addIcon(page, 'e2e-edit', SQUARE)
    await context.close()
  })
  test.afterAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await context.close()
  })

  test('recoloured from its tile, with Undo', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await more(tile(page, 'e2e-s/a')).click()
    await page.getByRole('menuitem', { name: /Recolour|Umfärben/ }).click()
    await expect(said(page)).toContainText('1 recoloured')
    await said(page).getByRole('button', { name: /Undo|Rückgängig/ }).click()
    await expect(said(page)).toContainText('colour back')
    expect(await calls(page)).toEqual([{ recolour: { refs: ['e2e-s/a'], setId: 41 } }])
  })

  test('its drawing replaced by a chosen file', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await more(tile(page, 'e2e-s/b')).click()
    const chooser = page.waitForEvent('filechooser')
    await page.getByRole('menuitem', { name: /Replace the drawing|Zeichnung ersetzen/ }).click()
    await (await chooser).setFiles({ name: 'neu.svg', mimeType: 'image/svg+xml', buffer: Buffer.from(CIRCLE) })
    await expect(said(page)).toContainText('new drawing')
    expect(await calls(page)).toEqual([{ replace: { ref: 'e2e-s/b', circle: true, setId: 41 } }])
  })

  test('picked set icons recolour together; a library icon picked with them is left alone', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await page.getByRole('button', { name: /Select several|Mehrere auswählen/ }).click()
    for (const key of ['e2e-s/a', 'e2e-s/b', 'easy-svg/e2e-edit']) {
      await tile(page, key).locator('[data-scope="checkbox"][data-part="control"]').click()
    }
    await page.getByRole('button', { name: /^(Recolour 2|2 umfärben)/ }).click()
    await expect(said(page)).toContainText('2 recoloured')
    expect(await calls(page)).toEqual([{ recolour: { refs: ['e2e-s/a', 'e2e-s/b'], setId: 41 } }])
  })

  test('every action of a fully equipped set tile is inside the tile', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    const t = tile(page, 'e2e-s/a')
    await t.hover()
    const box = await t.boundingBox()
    const buttons = t.locator('[data-tile-actions] button')
    expect(await buttons.count()).toBeGreaterThanOrEqual(4)
    // And in one row: a second row was where a drag from the tile's middle began.
    const tops = []
    for (const b of await buttons.all()) {
      tops.push((await b.boundingBox()).y)
    }
    // A menu trigger and a button may sit a pixel apart; a second row is a whole one.
    expect(Math.max(...tops) - Math.min(...tops), 'the actions sit in one row').toBeLessThan(8)
    for (const b of await buttons.all()) {
      const r = await b.boundingBox()
      expect(r.x >= box.x - 0.5 && r.x + r.width <= box.x + box.width + 0.5, 'a tile action is cut off').toBe(true)
    }
  })
})
