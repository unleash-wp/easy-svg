import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY } from './helpers.js'

/*
 * Panel contract 3: an add-on hands the library its collections, and the one
 * grid shows them beside the free icons -- filtered on the left, never in a
 * screen of their own (#108 D, "one grid").
 *
 * The provider here is registered through the public registry, exactly the
 * way an add-on does, so this is the contract and not an inside path.
 */

const SQUARE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="20" height="20" x="2" y="2"/></svg>'

async function provide(page, collections) {
  await page.evaluate((collections) => {
    window.EasySvgPanel.registerCollections({
      id: 'e2e',
      load: async () => collections,
    })
  }, collections)
}

const BRAND = {
  id: 'e2e-brand',
  label: 'E2E Brand',
  readonly: false,
  icons: [
    { key: 'e2e-brand/star', slug: 'star', ref: 'e2e-brand/star', label: 'E2E Brand Star', content: SQUARE },
    { key: 'e2e-brand/moon', slug: 'moon', ref: 'e2e-brand/moon', label: 'E2E Brand Moon', content: SQUARE },
  ],
}
const THEME = {
  id: 'e2e-theme',
  label: 'E2E Theme',
  readonly: true,
  icons: [{ key: 'e2e-theme/leaf', slug: 'leaf', ref: 'e2e-theme/leaf', label: 'E2E Theme Leaf', content: SQUARE }],
}

test.describe('collections in the library', () => {
  test('the contract says level 3', async ({ page }) => {
    await page.goto(LIBRARY)
    expect(await page.evaluate(() => window.EasySvgPanel.api)).toBe(3)
  })

  test('a provider\'s collections are listed, and choosing one filters the grid', async ({ page }) => {
    await page.goto(LIBRARY)
    await expect(page.locator(PANEL)).toBeVisible()
    await provide(page, [BRAND, THEME])

    const nav = page.locator(`${PANEL} [data-collections]`)
    await expect(nav.getByRole('button', { name: /E2E Brand/ })).toBeVisible()
    await expect(nav.getByRole('button', { name: /E2E Theme/ })).toBeVisible()

    // "All" shows the free icons AND the collections' icons, in one grid.
    await expect(page.locator(PANEL).getByText('E2E Brand Star', { exact: true })).toBeVisible()
    await expect(page.locator(PANEL).getByText('E2E Theme Leaf', { exact: true })).toBeVisible()

    await nav.getByRole('button', { name: /E2E Brand/ }).click()
    await expect(page.locator(PANEL).getByText('E2E Brand Moon', { exact: true })).toBeVisible()
    await expect(page.locator(PANEL).getByText('E2E Theme Leaf', { exact: true })).toHaveCount(0)
    await expect(nav.getByRole('button', { name: /E2E Brand/ })).toHaveAttribute('aria-pressed', 'true')
  })

  test('a collection icon offers no rename or delete, and copies its full address', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write'])
    await page.goto(LIBRARY)
    await provide(page, [BRAND])
    await page.locator(`${PANEL} [data-collections]`).getByRole('button', { name: /E2E Brand/ }).click()

    const tile = page.locator(`${PANEL} [data-icon-key="e2e-brand/star"]`)
    await expect(tile).toBeVisible()
    await expect(tile.getByRole('button', { name: /Rename|umbenennen/ })).toHaveCount(0)
    await expect(tile.getByRole('button', { name: /Delete|löschen/ })).toHaveCount(0)

    await tile.getByRole('button', { name: /Copy|kopieren/ }).click()
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe("easy_svg_icon( 'e2e-brand/star' )")
  })

  test('without a provider the library looks as it did', async ({ page }) => {
    await page.goto(LIBRARY)
    await expect(page.locator(PANEL)).toBeVisible()
    await expect(page.locator(`${PANEL} [data-collections]`)).toHaveCount(0)
  })
})
