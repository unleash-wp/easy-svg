import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY, E2E_PREFIX, sweepE2E, adminPage, listIcons } from './helpers.js'

/*
 * #108 K5: a second icon with a taken name is stored as `name-2`. The screen
 * said only "Icon added." and showed both tiles under the same name, so nobody
 * could tell which slug the copied call or a block had to use.
 */
const SQUARE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="20" height="20" x="2" y="2"/></svg>'
const NAME = `${E2E_PREFIX}doppelt`

const file = () => ({ name: `${NAME}.svg`, mimeType: 'image/svg+xml', buffer: Buffer.from(SQUARE) })

test.describe('a name that is already taken', () => {
  const sweep = async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(LIBRARY)
    await sweepE2E(page)
    await context.close()
  }
  test.beforeAll(sweep)
  test.afterAll(sweep)

  test('the second icon says which slug it got, and its tile shows it', async ({ page }) => {
    await page.goto(LIBRARY)
    const input = page.locator(`${PANEL} input[type="file"]`).first()

    await input.setInputFiles(file())
    await expect(page.locator(PANEL).getByText(/^(Icon added|Icon hinzugefügt)\.$/).first()).toBeVisible()

    await page.locator(`${PANEL} input[type="file"]`).first().setInputFiles(file())
    await expect(
      page.locator(PANEL).getByText(new RegExp(`Added as “${NAME}-2”: “${NAME}” was taken\\.|Als „${NAME}-2“ hinzugefügt: „${NAME}“ war schon vergeben\\.`))
    ).toBeVisible()

    const slugs = (await listIcons(page)).filter((i) => i.label === NAME).map((i) => i.slug).sort()
    expect(slugs).toEqual([NAME, `${NAME}-2`])

    // Same name on both tiles; the second carries the slug that tells them
    // apart. The first's slug IS its name, so it is not said twice.
    await expect(page.locator(`${PANEL} [data-slug]`, { hasText: `${NAME}-2` })).toHaveCount(1)
    await expect(page.locator(`${PANEL} [data-slug]`, { hasText: new RegExp(`^${NAME}$`) })).toHaveCount(0)
  })
})
