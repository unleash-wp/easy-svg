import { test, expect } from './fixtures.js'
import { PANEL, adminPage, rest } from './helpers.js'

const SETTINGS = '/wp-admin/admin.php?page=easy-svg-icons&tab=settings'

/*
 * One cap, two controls. The slider is for the gesture and the field for the
 * exact number, and they share a value -- which went wrong once already:
 * NumberInput keeps typed text apart from its value and did not rewrite it, so
 * a clamped save left the old number standing in the field.
 *
 * The site's own setting is read first and put back afterwards: this suite runs
 * against somebody's development site, not a scratch one.
 */
test.describe('the upload size cap', () => {
  let original

  test.beforeAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(SETTINGS)
    original = (await rest(page, '/settings')).body
    await context.close()
  })

  test.afterAll(async ({ browser }) => {
    const { page, context } = await adminPage(browser)
    await page.goto(SETTINGS)
    await rest(page, '/settings', { method: 'POST', body: original })
    await context.close()
  })

  test('the number field and the slider carry the same value', async ({ page }) => {
    await page.goto(SETTINGS)
    const number = page.locator(`${PANEL} input[inputmode="decimal"], ${PANEL} input[type="number"]`).first()
    await expect(number).toBeVisible()

    await number.fill('7')
    await number.blur()
    const slider = page.locator(`${PANEL} [role="slider"]`).first()
    await expect(slider).toHaveAttribute('aria-valuenow', '7')
  })

  test('a saved cap comes back after a reload', async ({ page }) => {
    await page.goto(SETTINGS)
    const number = page.locator(`${PANEL} input[inputmode="decimal"], ${PANEL} input[type="number"]`).first()
    await number.fill('9')
    await number.blur()
    await page.getByRole('button', { name: /^Speichern|^Save/i }).click()
    await expect(page.getByText(/Alle Änderungen gespeichert|All changes saved/i)).toBeVisible()

    await page.reload()
    await expect(page.locator(`${PANEL} input[inputmode="decimal"], ${PANEL} input[type="number"]`).first()).toHaveValue('9')
  })
})
