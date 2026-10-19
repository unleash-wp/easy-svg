import { test, expect } from './fixtures.js'
import { PANEL, LIBRARY } from './helpers.js'

/*
 * Panel contract 3, managing collections from the sidebar: an add-on that
 * takes create, rename or trash gets them on the left, beside its own
 * collections. A read-only collection offers none of them.
 */

async function provide(page, { create = true } = {}) {
  await page.evaluate((create) => {
    window.__calls = []
    window.__sets = [
      { id: 'e2e-a', label: 'E2E Set A', readonly: false, set_id: 11, icons: [] },
      { id: 'e2e-t', label: 'E2E Theme', readonly: true, icons: [] },
    ]
    const provider = {
      id: 'e2e',
      load: async () => window.__sets.map((c) => ({ ...c })),
      rename: async ({ id, label, collection }) => {
        window.__calls.push({ rename: { id, label, setId: collection?.set_id } })
        window.__sets.find((c) => c.id === id).label = label
        return { message: `Renamed to ${label}` }
      },
      trash: async ({ id }) => {
        window.__calls.push({ trash: id })
        const gone = window.__sets.find((c) => c.id === id)
        window.__sets = window.__sets.filter((c) => c.id !== id)
        return {
          message: `${gone.label} is in the trash`,
          undo: async () => {
            window.__calls.push({ restore: id })
            window.__sets.push(gone)
            return { message: 'Back again' }
          },
        }
      },
    }
    if (create) {
      provider.create = async ({ label }) => {
        window.__calls.push({ create: label })
        window.__sets.push({ id: 'e2e-new', label, readonly: false, set_id: 12, icons: [] })
        return { id: 'e2e-new', message: `${label} made` }
      }
    }
    // Only this provider: with Pro active, its real one also takes create, and
    // New set would go to the server instead of to the calls recorded here.
    const reg = window.EasySvgPanel
    const all = reg.collectionProviders.bind(reg)
    reg.collectionProviders = () => all().filter((p) => 'e2e' === p.id)
    reg.registerCollections(provider)
  }, create)
}

const nav = (page) => page.locator(`${PANEL} [data-collections]`)
const calls = (page) => page.evaluate(() => window.__calls)

test.describe('managing sets from the sidebar', () => {
  test('a new set is made by name, and the grid shows it', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await nav(page).getByRole('button', { name: /New set|Neues Set/ }).click()
    const name = nav(page).getByRole('textbox')
    await name.fill('E2E Fresh')
    await name.press('Enter')
    expect(await calls(page)).toEqual([{ create: 'E2E Fresh' }])
    const made = nav(page).getByRole('button', { name: /^E2E Fresh/ })
    await expect(made).toHaveAttribute('aria-pressed', 'true')
    await expect(page.locator(`${PANEL} [data-move-result]`)).toContainText('E2E Fresh made')
  })

  test('Escape leaves without making anything', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await nav(page).getByRole('button', { name: /New set|Neues Set/ }).click()
    await nav(page).getByRole('textbox').fill('E2E Nope')
    await nav(page).getByRole('textbox').press('Escape')
    await expect(nav(page).getByRole('textbox')).toHaveCount(0)
    expect(await calls(page)).toEqual([])
  })

  test('a set is renamed in place', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await nav(page).getByRole('button', { name: /Actions for E2E Set A|Aktionen für E2E Set A/ }).click()
    await page.getByRole('menuitem', { name: /Rename|Umbenennen/ }).click()
    const name = nav(page).getByRole('textbox')
    await expect(name).toHaveValue('E2E Set A')
    await name.fill('E2E Set Alpha')
    await name.press('Enter')
    expect(await calls(page)).toEqual([{ rename: { id: 'e2e-a', label: 'E2E Set Alpha', setId: 11 } }])
    await expect(nav(page).getByRole('button', { name: /^E2E Set Alpha/ })).toBeVisible()
  })

  test('a set goes to the trash, and Undo brings it back', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page)
    await nav(page).getByRole('button', { name: /^E2E Set A/ }).click()
    await nav(page).getByRole('button', { name: /Actions for E2E Set A|Aktionen für E2E Set A/ }).click()
    await page.getByRole('menuitem', { name: /Move to trash|Papierkorb/ }).click()
    const said = page.locator(`${PANEL} [data-move-result]`)
    await expect(said).toContainText('E2E Set A is in the trash')
    await expect(nav(page).getByRole('button', { name: /^E2E Set A/ })).toHaveCount(0)
    // The grid no longer shows a collection that is gone.
    await expect(nav(page).getByRole('button', { name: /All icons|Alle Icons/ })).toHaveAttribute('aria-pressed', 'true')
    await said.getByRole('button', { name: /Undo|Rückgängig/ }).click()
    await expect(said).toContainText('Back again')
    await expect(nav(page).getByRole('button', { name: /^E2E Set A/ })).toBeVisible()
    expect(await calls(page)).toEqual([{ trash: 'e2e-a' }, { restore: 'e2e-a' }])
  })

  test('a read-only collection has no actions, and no create means no New set', async ({ page }) => {
    await page.goto(LIBRARY)
    await provide(page, { create: false })
    await expect(nav(page).getByRole('button', { name: /^E2E Theme/ })).toBeVisible()
    await expect(nav(page).getByRole('button', { name: /Actions for E2E Theme|Aktionen für E2E Theme/ })).toHaveCount(0)
    await expect(nav(page).getByRole('button', { name: /New set|Neues Set/ })).toHaveCount(0)
  })
})
