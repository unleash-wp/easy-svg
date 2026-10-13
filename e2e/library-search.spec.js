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

  /*
   * The magnifier sits ON the field, so the field needs room for it. The rule
   * that makes that room has to be unlayered to beat wp-admin's forms.css, and
   * it was aimed at an attribute the library stopped emitting -- so it matched
   * nothing and the placeholder ran under the icon. Measured, because the rule
   * can go on existing while it selects nothing.
   */
  test('the search field leaves room for its magnifier', async ({ page }) => {
    await page.goto(LIBRARY)
    const gap = await page.locator('#esw-icon-search').evaluate((input) => {
      const icon = input.parentElement.querySelector('svg')
      const style = window.getComputedStyle(input)
      const textStart = input.getBoundingClientRect().left + parseFloat(style.paddingInlineStart)
      return textStart - icon.getBoundingClientRect().right
    })
    expect(gap).toBeGreaterThan(0)
  })

  /*
   * The heading counts the library; the line above the grid counts the matches.
   * They were the same number, so searching told a library of three it held one
   * -- and after a search that matched nothing, that it was empty.
   */
  test('the heading keeps counting the library while a search narrows it', async ({ page }) => {
    await page.goto(LIBRARY)
    const heading = page.getByText(/\d+ Icons? in der Library|\d+ icons? in the library/i)
    const before = await heading.textContent()

    await page.locator('#esw-icon-search').fill('Rakete')
    await expect(page.getByText(/1 Icon gefunden|1 icon found/i)).toBeVisible()
    await expect(heading).toHaveText(before)
  })
})
