import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, listIcons } from './helpers.js'

/*
 * With a set on screen whose provider takes them: a ZIP or an SVG icon font
 * goes to the set's own import, a plain SVG to add, and a media library pick
 * to addMedia -- none of it into the free library.
 */

const PLAIN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M2 2h20v20H2z"/></svg>'
const FONT = '<svg xmlns="http://www.w3.org/2000/svg"><defs><font id="f"><glyph glyph-name="a" unicode="a" d="M0 0h10v10z"/></font></defs></svg>'

async function provide(page) {
  await page.evaluate(() => {
    window.__calls = []
    const reg = window.EasySvgPanel
    const all = reg.collectionProviders.bind(reg)
    reg.collectionProviders = () => all().filter((p) => 'e2e' === p.id)
    reg.registerCollections({
      id: 'e2e',
      load: async () => [{ id: 'e2e-s', label: 'E2E Set S', readonly: false, set_id: 51, icons: [] }],
      add: async ({ icons }) => {
        window.__calls.push({ add: icons.map((i) => i.name) })
        return { message: `${icons.length} added`, rows: icons.map((i) => ({ file: i.name, ok: true, message: 'Added.' })) }
      },
      importFile: async ({ file, collection }) => {
        window.__calls.push({ import: file.name, setId: collection.set_id })
        return { message: `imported ${file.name}`, rows: [{ file: file.name, ok: true, message: 'Added.' }] }
      },
      addMedia: async ({ ids, collection }) => {
        window.__calls.push({ media: ids, setId: collection.set_id })
        return { message: `${ids.length} from media`, rows: [] }
      },
    })
    // The media frame, picking two attachments the moment it opens.
    window.wp = window.wp || {}
    window.wp.media = () => {
      const on = {}
      return {
        on: (name, fn) => {
          on[name] = fn
        },
        content: { mode: () => {} },
        state: () => ({ get: () => ({ map: (fn) => [{ id: 11 }, { id: 12 }].map(fn) }) }),
        open: () => {
          on.open?.()
          on.select?.()
        },
      }
    }
  })
}

const calls = (page) => page.evaluate(() => window.__calls)
const said = (page) => page.locator(`${PANEL} [data-move-result]`)

test('a ZIP and a font go to the set\'s import, a plain SVG to add', async ({ page }) => {
  await page.goto(LIBRARY)
  await provide(page)
  const before = (await listIcons(page)).length
  await page.locator(`${PANEL} [data-collections]`).getByRole('button', { name: /^E2E Set S/ }).click()
  await page
    .locator(`${PANEL} input[type="file"]`)
    .first()
    .setInputFiles([
      { name: 'plain.svg', mimeType: 'image/svg+xml', buffer: Buffer.from(PLAIN) },
      { name: 'pack.zip', mimeType: 'application/zip', buffer: Buffer.from('PK\u0003\u0004') },
      { name: 'font.svg', mimeType: 'image/svg+xml', buffer: Buffer.from(FONT) },
    ])
  await expect(said(page)).toContainText('1 added · imported pack.zip · imported font.svg')
  expect(await calls(page)).toEqual([
    { add: ['plain'] },
    { import: 'pack.zip', setId: 51 },
    { import: 'font.svg', setId: 51 },
  ])
  expect((await listIcons(page)).length, 'the free library got nothing').toBe(before)
})

test('the set view takes a ZIP in its file chooser', async ({ page }) => {
  await page.goto(LIBRARY)
  await provide(page)
  await page.locator(`${PANEL} [data-collections]`).getByRole('button', { name: /^E2E Set S/ }).click()
  await expect(page.locator(`${PANEL} input[type="file"]`).first()).toHaveAttribute('accept', /\.zip/)
})

test('a media library pick goes into the set', async ({ page }) => {
  await page.goto(LIBRARY)
  await provide(page)
  await page.locator(`${PANEL} [data-collections]`).getByRole('button', { name: /^E2E Set S/ }).click()
  await page.getByRole('button', { name: /media library|Mediathek/ }).first().click()
  await expect(said(page)).toContainText('2 from media')
  expect(await calls(page)).toEqual([{ media: [11, 12], setId: 51 }])
})
