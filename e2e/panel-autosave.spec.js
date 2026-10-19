import { test, expect } from './fixtures.js'
import { PANEL } from './helpers.js'

const SETTINGS = '/wp-admin/admin.php?page=easy-svg-icons&tab=settings'

/*
 * K1 was "a tab change loses what you typed". The panel unmounts the old tab
 * (App.jsx, `unmountOnExit`, which is why tabs load cleanly), so its useState
 * form died with it and nothing anywhere asked.
 *
 * The answer is not to ask. A question is one more click on a screen whose
 * whole complaint was too many of them -- so the panel saves as you go, and
 * the proof is that the change is still there afterwards, not that a dialog
 * appeared.
 */
const SIZE = `${PANEL} #esw-setting-max-mb`
// What the panel says while and after it writes. There is a [role="status"] on
// this screen today -- the save bar's own "All changes saved" -- so waiting for
// the role alone would wait for something that is already there.
const SAVED = /^(saved|gespeichert)$/i

async function sizeField(page) {
  await page.goto(SETTINGS)
  const field = page.locator(SIZE)
  await expect(field).toBeVisible()
  return field
}

test('a change survives a tab change, and nothing asks about it', async ({ page }) => {
  const field = await sizeField(page)
  const before = await field.inputValue()
  const next = String(Number(before) === 7 ? 6 : 7)

  await field.fill(next)
  await field.blur()

  // Saved by now, with nothing clicked.
  await expect(page.locator(`${PANEL} [role="status"]`).filter({ hasText: SAVED })).toBeVisible()
  await expect(page.getByRole('alertdialog'), 'the panel asked instead of saving').toHaveCount(0)

  await page.getByRole('tab', { name: /icon library|icon-bibliothek/i }).click()
  await expect(page.getByRole('alertdialog'), 'the tab change asked').toHaveCount(0)

  await page.getByRole('tab', { name: /settings|einstellungen/i }).click()
  await expect(page.locator(SIZE)).toHaveValue(next)

  // And it reached the server, not just the other tab's memory.
  await page.reload()
  await expect(page.locator(SIZE)).toHaveValue(next)
})

test('the save bar is gone', async ({ page }) => {
  await page.goto(SETTINGS)
  await expect(page.locator(SIZE)).toBeVisible()

  for (const label of [/^save$/i, /^speichern$/i, /^reset$/i, /zurücksetzen/i]) {
    await expect(
      page.getByRole('button', { name: label }),
      `${label} is still on the screen`
    ).toHaveCount(0)
  }
})

/*
 * M12, and the proof that the response is the truth.
 *
 * The cap is the server's (includes/settings.php), so a number above it goes
 * out as typed and comes back as the allowed one. The field has to move to
 * what was stored -- a form still showing 500 while 100 is saved is the bug
 * this replaces -- and the line explaining it has to appear with it.
 */
test('a size above the cap comes back as the allowed one, and says so', async ({ page }) => {
  await page.goto(SETTINGS)
  const field = page.locator(SIZE)
  await expect(field).toBeVisible()

  await field.fill('500')
  await field.blur()

  await expect(page.locator(`${PANEL} [role="status"]`).filter({ hasText: SAVED })).toBeVisible()
  const stored = Number(await field.inputValue())
  expect(stored, 'the field still shows what the server refused').toBeLessThan(500)
  await expect(page.getByText(/nearest allowed value|erlaubten Wert/i)).toBeVisible()

  // And it was CLAMPED, not refused. With a `maximum` in the route's schema
  // WordPress answers 400 before the sanitiser clamps, and the screen then
  // carries an error instead of the line explaining the jump.
  await expect(page.locator(`${PANEL} [role="alert"]`), 'the write was refused').toHaveCount(0)

  await page.reload()
  await expect(field).toHaveValue(String(stored))
})

/*
 * Two writes that really overlap.
 *
 * Clicking twice does not do it: playwright waits for each click to settle, so
 * the first write is finished before the second starts and the queue is never
 * reached -- measured, by taking the queue out and watching this pass anyway.
 * The first response is held open instead, which is also what a slow site
 * does, so the second write is issued while the first is still in flight.
 *
 * Both have to arrive, in order. A queue that drops what came in during a
 * write loses the second switch; one that lets both run at once lets the
 * slower reply land last, and the screen then shows a state the server does
 * not have.
 */
test('a write issued during another one is not lost', async ({ page }) => {
  await page.goto(SETTINGS)
  const roots = page.locator(`${PANEL} [data-scope="switch"][data-part="root"]`)
  await expect(roots.first()).toBeVisible()
  const upload = page.locator(`${PANEL} #esw-setting-svg-upload`)
  const icons = page.locator(`${PANEL} #esw-setting-icons`)
  const wantUpload = !(await upload.isChecked())
  const wantIcons = !(await icons.isChecked())

  let held = 0
  await page.route('**/easy-svg/v1/settings*', async (route) => {
    if ('POST' !== route.request().method()) {
      return route.fallback()
    }
    // Only the first one waits. Long enough that the second click lands first.
    if (0 === held++) {
      await new Promise((r) => setTimeout(r, 1500))
    }
    return route.fallback()
  })

  await roots.nth(0).click()
  await roots.nth(1).click({ noWaitAfter: true })

  await expect(page.locator(`${PANEL} [role="status"]`).filter({ hasText: SAVED })).toBeVisible({ timeout: 15000 })
  await page.unroute('**/easy-svg/v1/settings*')

  await page.reload()
  await expect(upload, 'the held write never arrived').toBeChecked({ checked: wantUpload })
  await expect(icons, 'the write issued during it was dropped').toBeChecked({ checked: wantIcons })
})

/*
 * A write the server refuses puts the field back and says so. An optimistic
 * value nobody accepted is a lie, and leaving it on screen is how somebody
 * closes the tab believing it saved.
 */
// This spec drives a refused write on purpose, so the browser's own complaint
// about the 500 is the condition under test rather than a defect.
test.use({ consoleAllow: [/status of 500/, /Failed to load resource/] })

test('a refused write goes back to the last confirmed value', async ({ page }) => {
  await page.goto(SETTINGS)
  const field = page.locator(SIZE)
  await expect(field).toBeVisible()
  const before = await field.inputValue()

  await page.route('**/easy-svg/v1/settings*', async (route) => {
    if ('POST' !== route.request().method()) {
      return route.fallback()
    }
    return route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ code: 'e2e_refused', message: 'E2E refused this write' }),
    })
  })

  await field.fill(String(Number(before) === 4 ? 5 : 4))
  await field.blur()

  await expect(page.locator(`${PANEL} [role="alert"]`).first()).toBeVisible({ timeout: 15000 })
  await expect(field, 'the refused value is still on screen').toHaveValue(before)

  await page.unroute('**/easy-svg/v1/settings*')
  await page.reload()
  await expect(field, 'the refused value reached the server after all').toHaveValue(before)
})

/*
 * The same field twice, with the first reply held open. Different fields
 * cannot show this: the route merges a patch onto what is stored, so whichever
 * order two different keys land in, both end up set. One key, two values, and
 * the order is the whole answer -- without one write at a time the held reply
 * lands last and the stored number is the one the user typed FIRST.
 */
test('the later of two writes to one field wins', async ({ page }) => {
  await page.goto(SETTINGS)
  const field = page.locator(SIZE)
  await expect(field).toBeVisible()

  let held = 0
  await page.route('**/easy-svg/v1/settings*', async (route) => {
    if ('POST' !== route.request().method()) {
      return route.fallback()
    }
    if (0 === held++) {
      await new Promise((r) => setTimeout(r, 1500))
    }
    return route.fallback()
  })

  await field.fill('4')
  await field.blur()
  await field.fill('9')
  await field.blur()

  await expect(page.locator(`${PANEL} [role="status"]`).filter({ hasText: SAVED })).toBeVisible({ timeout: 15000 })
  await page.unroute('**/easy-svg/v1/settings*')

  await page.reload()
  await expect(field, 'the earlier write landed last').toHaveValue('9')
})

test('two writes that overlap both arrive', async ({ page }) => {
  await page.goto(SETTINGS)
  // Chakra hides the real input behind Switch.HiddenInput, so the click goes
  // to the control and the state is read off the root's data-state.
  const roots = page.locator(`${PANEL} [data-scope="switch"][data-part="root"]`)
  await expect(roots.first()).toBeVisible()
  const upload = page.locator(`${PANEL} #esw-setting-svg-upload`)
  const icons = page.locator(`${PANEL} #esw-setting-icons`)

  const wantUpload = !(await upload.isChecked())
  const wantIcons = !(await icons.isChecked())

  await roots.nth(0).click()
  await roots.nth(1).click()

  await expect(page.locator(`${PANEL} [role="status"]`).filter({ hasText: SAVED })).toBeVisible()
  await expect(page.locator(`${PANEL} [role="alert"]`)).toHaveCount(0)

  await page.reload()
  await expect(upload, 'the first switch did not reach the server').toBeChecked({ checked: wantUpload })
  await expect(icons, 'the second switch did not reach the server').toBeChecked({ checked: wantIcons })
})

/*
 * Undo, and the narrow cut it has to keep.
 *
 * Autosave is worse than the button it replaced in exactly one case: a stray
 * click that TAKES something away lands at once and cannot be called back.
 * The toast is the price of having removed the question -- so it belongs to
 * that case and to no other. A toast that appears on every change is clicked
 * away before it is read, and then it is missing precisely when it matters.
 */
const SWITCHES = `${PANEL} [data-scope="switch"][data-part="root"]`
const UNDO = /^(undo|rückgängig)$/i

async function setSwitch(page, index, on) {
  const root = page.locator(SWITCHES).nth(index)
  const state = on ? 'checked' : 'unchecked'
  if (state !== (await root.getAttribute('data-state'))) {
    await root.click()
    await expect(page.locator(`${PANEL} [role="status"]`).filter({ hasText: SAVED })).toBeVisible()
  }
}

test('switching a feature off offers to undo it, and the undo sticks', async ({ page }) => {
  await page.goto(SETTINGS)
  await expect(page.locator(SWITCHES).first()).toBeVisible()

  // From a known state: on, with no toast of its own to confuse the next step.
  await setSwitch(page, 0, true)

  await page.locator(SWITCHES).nth(0).click()
  const undo = page.getByRole('button', { name: UNDO })
  await expect(undo, 'switching a feature off offered no way back').toBeVisible()

  await undo.click()
  await expect(page.locator(`${PANEL} [role="status"]`).filter({ hasText: SAVED })).toBeVisible()

  // Undo is a write like any other, so it has to have reached the server.
  await page.reload()
  await expect(page.locator(SWITCHES).first()).toHaveAttribute('data-state', 'checked')
})

/*
 * The one that keeps the cut narrow. Without it the toast drifts onto every
 * change over time, and nobody notices until it is everywhere.
 */
test('switching a feature on offers nothing, because nothing was taken', async ({ page }) => {
  await page.goto(SETTINGS)
  await expect(page.locator(SWITCHES).first()).toBeVisible()

  await setSwitch(page, 0, false)
  // Whatever the step above may have put on screen is gone before the real one.
  await expect(page.getByRole('button', { name: UNDO })).toHaveCount(0, { timeout: 15000 })

  await page.locator(SWITCHES).nth(0).click()
  await expect(page.locator(`${PANEL} [role="status"]`).filter({ hasText: SAVED })).toBeVisible()
  await expect(
    page.getByRole('button', { name: UNDO }),
    'switching something ON offered to undo it'
  ).toHaveCount(0)

  await setSwitch(page, 0, true)
})
