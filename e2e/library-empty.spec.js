import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY } from './helpers.js'

/*
 * A library with nothing stored yet showed only the upload box, and with it
 * went the sidebar: no way to begin a first set, no hint that sets are off.
 * CI, a fresh site, saw that; a site with icons never did. The library is
 * answered empty here, so every checkout sees what a fresh site sees.
 */

async function emptyLibrary(page) {
  await page.route(/\/easy-svg\/v1\/library(\?|$)/, (route) =>
    'GET' === route.request().method() ? route.fulfill({ json: { items: [], total: 0 } }) : route.continue()
  )
}

async function provide(page, provider) {
  await page.evaluate((provider) => {
    const reg = window.EasySvgPanel
    const all = reg.collectionProviders.bind(reg)
    reg.collectionProviders = () => all().filter((p) => 'e2e' === p.id)
    reg.registerCollections({
      id: 'e2e',
      load: async () => provider.collections || [],
      ...(provider.hint ? { hint: { text: provider.hint, action: { label: 'E2E turn on', run: async () => {} } } } : {}),
      ...(provider.create ? { create: async () => ({ message: 'made' }) } : {}),
    })
  }, provider)
}

const nav = (page) => page.locator(`${PANEL} [data-collections]`)

test('an empty library still says that sets are off', async ({ page }) => {
  await emptyLibrary(page)
  await page.goto(LIBRARY)
  await provide(page, { hint: 'E2E: sets are off.' })
  await expect(nav(page).locator('[data-collections-hint]')).toContainText('E2E: sets are off.')
})

test('an empty library still offers a first set', async ({ page }) => {
  await emptyLibrary(page)
  await page.goto(LIBRARY)
  await provide(page, { create: true })
  await expect(nav(page).getByRole('button', { name: /^(New set|Neues Set)/ })).toBeVisible()
  await expect(page.locator(PANEL).getByText(/No icon matches that search|Kein Icon passt/)).toHaveCount(0)
})

test('an empty library with nothing to offer beside it is the upload box, as before', async ({ page }) => {
  await emptyLibrary(page)
  await page.goto(LIBRARY)
  // Only a provider with no set, no create and no hint is left.
  await provide(page, {})
  await expect(page.locator(PANEL).getByText(/Drop your first SVG here|Leg dein erstes SVG/)).toBeVisible()
  await expect(nav(page)).toHaveCount(0)
})

test('an empty set is no search that found nothing', async ({ page }) => {
  await page.goto(LIBRARY)
  await provide(page, { collections: [{ id: 'e2e-empty', label: 'E2E Empty', readonly: false, icons: [] }] })
  await nav(page).getByRole('button', { name: /^E2E Empty/ }).click()
  await expect(page.locator(PANEL).getByText(/No icon matches that search|Kein Icon passt/)).toHaveCount(0)
})
