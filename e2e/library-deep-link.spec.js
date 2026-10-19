import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY } from './helpers.js'

/*
 * Where the Sets tab and the set list lead: the grid opened on one collection,
 * or with a new set being named. The link waits for an add-on's collections,
 * which register after the panel's first load.
 */

async function provide(page) {
  await page.evaluate(() => {
    window.__made = []
    const reg = window.EasySvgPanel
    const all = reg.collectionProviders.bind(reg)
    reg.collectionProviders = () => all().filter((p) => 'e2e' === p.id)
    reg.registerCollections({
      id: 'e2e',
      load: async () => [{ id: 'e2e-link', label: 'E2E Link Set', readonly: false, icons: [{ key: 'e2e-link/a', slug: 'a', ref: 'e2e-link/a', label: 'E2E Link A', content: '<svg viewBox="0 0 2 2"><path d="M0 0h2v2z"/></svg>' }] }],
      create: async ({ label }) => {
        window.__made.push(label)
        return { id: 'e2e-link', message: 'made' }
      },
    })
  })
}

const nav = (page) => page.locator(`${PANEL} [data-collections]`)

test('collection= opens the grid on that collection', async ({ page }) => {
  await page.goto(`${LIBRARY}&collection=e2e-link`)
  await provide(page)
  await expect(nav(page).getByRole('button', { name: /^E2E Link Set/ })).toHaveAttribute('aria-pressed', 'true')
  await expect(page.locator(`${PANEL} [data-icon-key="e2e-link/a"]`)).toBeVisible()
})

test('new-set=1 opens the name field for a new set', async ({ page }) => {
  await page.goto(`${LIBRARY}&new-set=1`)
  await provide(page)
  const field = nav(page).getByRole('textbox')
  await expect(field).toBeFocused()
  await field.fill('E2E Linked')
  await field.press('Enter')
  expect(await page.evaluate(() => window.__made)).toEqual(['E2E Linked'])
})

test('a collection that is not there leaves the grid on All icons', async ({ page }) => {
  await page.goto(`${LIBRARY}&collection=gibt-es-nicht`)
  await provide(page)
  await expect(nav(page).getByRole('button', { name: /^E2E Link Set/ })).toBeVisible()
  await expect(nav(page).getByRole('button', { name: /^(All icons|Alle Icons)/ })).toHaveAttribute('aria-pressed', 'true')
})
