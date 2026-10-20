import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, E2E_PREFIX, sweepE2E, adminPage, listIcons } from './helpers.js'

/*
 * #108 K5: a second file with a taken name can replace the first.
 *
 * It was stored as `name-2` and nothing else was possible: a site that wanted
 * a new drawing for "star" got two stars and had to delete one and edit every
 * page that should use the new one. The toast now offers to put the drawing
 * on the icon that has the name; its address stays, so every page using it
 * shows the new drawing. Undo puts back both the old drawing and the copy.
 */
const SQUARE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="20" height="20" x="2" y="2"/></svg>'
const CIRCLE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>'
const NAME = `${E2E_PREFIX}tauschen`

const file = (markup) => ({ name: `${NAME}.svg`, mimeType: 'image/svg+xml', buffer: Buffer.from(markup) })
const mine = async (page) => (await listIcons(page)).filter((i) => i.label === NAME).sort((a, b) => a.slug.localeCompare(b.slug))

test.describe('a file whose name is taken', () => {
  const sweep = async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await context.close()
  }
  test.beforeEach(sweep)
  test.afterAll(sweep)

  test('can replace the icon that has the name, and Undo puts both back', async ({ page }) => {
    await page.goto(LIBRARY)
    const input = () => page.locator(`${PANEL} input[type="file"]`).first()
    await input().setInputFiles(file(SQUARE))
    await expect(page.locator(PANEL).getByText(/^(Icon added|Icon hinzugefügt)\.$/).first()).toBeVisible()

    await input().setInputFiles(file(CIRCLE))
    const toast = page.locator(`${PANEL} [data-toast]`)
    await expect(toast).toContainText(`${NAME}-2`)
    await toast.getByRole('button', { name: new RegExp(`Replace “${NAME}” instead|„${NAME}“ stattdessen ersetzen`) }).click()
    await expect(toast).toContainText(/new drawing|neue Zeichnung/)

    let icons = await mine(page)
    expect(icons.map((i) => i.slug), 'one icon, at the address that was taken').toEqual([NAME])
    expect(icons[0].content, 'with the new drawing').toContain('circle')

    await toast.getByRole('button', { name: /Undo|Rückgängig/ }).click()
    await expect(toast).toContainText(`${NAME}-2`)
    icons = await mine(page)
    expect(icons.map((i) => i.slug), 'both are back').toEqual([NAME, `${NAME}-2`])
    expect(icons[0].content, 'the first has its old drawing').toContain('rect')
    expect(icons[1].content, 'and the copy the new one').toContain('circle')
  })

  test('a name that is free is not offered anything', async ({ page }) => {
    await page.goto(LIBRARY)
    await page.locator(`${PANEL} input[type="file"]`).first().setInputFiles(file(SQUARE))
    await expect(page.locator(PANEL).getByText(/^(Icon added|Icon hinzugefügt)\.$/).first()).toBeVisible()
    await expect(page.locator(PANEL).getByRole('button', { name: /instead|stattdessen/ })).toHaveCount(0)
  })
})
