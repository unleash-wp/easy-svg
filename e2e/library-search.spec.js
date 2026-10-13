import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, addIcon, sweepE2E, adminPage, rest } from './helpers.js'

/*
 * The search runs on the SERVER, so what it finds cannot be read off the
 * bundle. Each of these pins a defect that shipped: a search matching nothing
 * claimed the library was empty and took its own field away, and a slug was
 * never searched at all because WP_Query drops post_name from search_columns.
 */
test.describe('the icon library search', () => {
  let rocket

  test.beforeAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    rocket = await addIcon(page, 'E2E Rakete')
    await addIcon(page, 'E2E Anker')
    await addIcon(page, 'E2E Zebra')
    await context.close()
  })

  test.afterAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await context.close()
  })

  test('finds an icon by its name', async ({ page }) => {
    await page.goto(LIBRARY)
    await page.locator('#esw-icon-search').fill('Rakete')
    await expect(page.getByText(/1 Icon gefunden|1 icon found/i)).toBeVisible()
    await expect(page.locator(PANEL).getByText('E2E Rakete')).toBeVisible()
  })

  test('finds an icon by its slug after the name has moved away from it', async ({ page }) => {
    await page.goto(LIBRARY)
    // Rename it: the slug stays, so the two no longer match -- which is the only
    // state in which a slug search can be told apart from a name search.
    const renamed = await rest(page, '/library/rename', {
      method: 'POST',
      body: { id: rocket.id, label: 'E2E Umbenannt' },
    })
    expect(renamed.status, JSON.stringify(renamed.body).slice(0, 200)).toBe(200)

    await page.reload()
    await page.locator('#esw-icon-search').fill(rocket.slug)
    await expect(page.getByText(/1 Icon gefunden|1 icon found/i)).toBeVisible()
    await expect(page.locator(PANEL).getByText('E2E Umbenannt')).toBeVisible()
  })

  test('a search with no matches keeps the field and says so', async ({ page }) => {
    await page.goto(LIBRARY)
    await page.locator('#esw-icon-search').fill('zzz-nichts-davon')

    await expect(page.getByText(/0 Icons gefunden|0 icons found/i)).toBeVisible()
    // The field has to survive its own empty result: it is the only way back.
    await expect(page.locator('#esw-icon-search')).toBeVisible()
    await expect(page.locator(PANEL).getByText(/erstes SVG|first SVG/i)).toHaveCount(0)
  })
})
