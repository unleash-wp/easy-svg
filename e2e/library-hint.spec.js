import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY } from './helpers.js'

/*
 * A provider whose collections need a feature that is switched off says so in
 * the sidebar, where its collections would be, with the way to switch it on --
 * even when it has no collection to list yet.
 */

async function provide(page, { fails = false } = {}) {
  await page.evaluate((fails) => {
    window.__runs = 0
    const reg = window.EasySvgPanel
    const all = reg.collectionProviders.bind(reg)
    reg.collectionProviders = () => all().filter((p) => 'e2e' === p.id)
    reg.registerCollections({
      id: 'e2e',
      load: async () => [],
      hint: {
        text: 'E2E: sets are off.',
        action: {
          label: 'E2E turn on',
          run: async () => {
            window.__runs++
            if (fails) {
              throw new Error('E2E could not switch it on')
            }
          },
        },
      },
    })
  }, fails)
}

const nav = (page) => page.locator(`${PANEL} [data-collections]`)

test('the hint is shown with its action, with no collection to list', async ({ page }) => {
  await page.goto(LIBRARY)
  await provide(page)
  const hint = nav(page).locator('[data-collections-hint]')
  await expect(hint).toContainText('E2E: sets are off.')
  await hint.getByRole('button', { name: 'E2E turn on' }).click()
  await expect.poll(() => page.evaluate(() => window.__runs)).toBe(1)
})

test('an action that fails says why', async ({ page }) => {
  await page.goto(LIBRARY)
  await provide(page, { fails: true })
  await nav(page).locator('[data-collections-hint]').getByRole('button', { name: 'E2E turn on' }).click()
  await expect(page.locator(PANEL).getByText('E2E could not switch it on')).toBeVisible()
})
