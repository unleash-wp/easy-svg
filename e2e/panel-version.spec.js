import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY } from './helpers.js'

/*
 * The panel names each installed version once.
 *
 * It said the free version in the top corner AND in the footer, the Pro
 * version in the footer AND in the Pro badge's tooltip AND on the license
 * tab. One place answers the support question; five make a reader wonder
 * whether they are different things. The footer keeps both halves.
 */
test('each version is shown once, in the footer', async ({ page }) => {
  await page.goto(LIBRARY)
  await expect(page.locator(PANEL)).toBeVisible()
  const { version, proVersion } = await page.evaluate(() => window.EasySvgPanelData)
  expect(version).toBeTruthy()

  const panel = page.locator(PANEL)
  const count = async (needle) =>
    panel.evaluate((el, n) => {
      const text = el.innerText.split(n).length - 1
      const titles = [...el.querySelectorAll('[title]')].filter((t) => t.title.includes(n)).length
      return text + titles
    }, needle)

  expect(await count(version), `v${version} on screen`).toBe(1)
  if (proVersion) {
    expect(await count(proVersion), `Pro ${proVersion} on screen`).toBe(1)
  }
  await expect(panel.getByText(new RegExp(`v${version.replace(/\./g, '\\.')}`)).last()).toBeVisible()

  // The license tab said it again in a sentence of its own.
  if (proVersion) {
    await page.goto(LIBRARY.replace('tab=library', 'tab=licence'))
    await expect(panel.locator('[role="tab"][aria-selected="true"]')).toBeVisible()
    await expect(panel.getByText(/License status|Lizenzstatus/).first()).toBeVisible()
    expect(await count(proVersion), `Pro ${proVersion} on the license tab`).toBe(1)
  }
})
