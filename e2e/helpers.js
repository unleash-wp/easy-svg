import { expect } from '@playwright/test'

export const PANEL = '#easy-svg-panel'
export const LIBRARY = '/wp-admin/admin.php?page=easy-svg-icons&tab=library'

/** Every icon this suite creates is named like this, and swept by it. */
export const E2E_PREFIX = 'e2e-'

/**
 * Call the panel's own REST routes as the signed-in admin.
 *
 * Seeding through the API the panel itself uses, rather than a second channel
 * into the container: it needs no wp-cli, and it fails loudly if a route a spec
 * depends on changes shape.
 */
export async function rest(page, path, { method = 'GET', body } = {}) {
  return page.evaluate(
    async ({ path, method, body }) => {
      // The panel localises its data under this name; wpApiSettings is
      // WordPress's own and only there on some screens.
      const nonce = window.EasySvgPanelData?.nonce || window.wpApiSettings?.nonce
      const res = await fetch(`/wp-json/easy-svg/v1${path}`, {
        method,
        headers: { 'X-WP-Nonce': nonce, ...(body ? { 'Content-Type': 'application/json' } : {}) },
        body: body ? JSON.stringify(body) : undefined,
      })
      const text = await res.text()
      try {
        return { status: res.status, body: JSON.parse(text) }
      } catch {
        return { status: res.status, body: text }
      }
    },
    { path, method, body }
  )
}

const SQUARE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24"/></svg>'

/**
 * Put one icon in the library and hand back the row the server stored.
 *
 * /library/add answers with the whole library rather than the new icon, so the
 * row is picked out of that answer by label -- the newest one, because a repeat
 * run gets `-2` appended to its slug and both rows carry the same label.
 */
export async function addIcon(page, label, markup = SQUARE) {
  const r = await rest(page, '/library/add', { method: 'POST', body: { label, markup } })
  expect(r.status, `adding ${label}: ${JSON.stringify(r.body).slice(0, 200)}`).toBe(200)
  const items = r.body?.items ?? []
  const mine = items.filter((i) => i.label === label)
  return mine[mine.length - 1]
}

export async function listIcons(page) {
  const r = await rest(page, '/library')
  return r.body?.items ?? []
}

/**
 * Remove everything this suite made, by slug prefix rather than by a list of
 * ids: a run that failed half way through leaves icons behind, and the next run
 * has to be able to clean up after it.
 */
export async function sweepE2E(page) {
  for (const icon of await listIcons(page)) {
    if (String(icon.slug || '').startsWith(E2E_PREFIX)) {
      await rest(page, '/library/delete', { method: 'POST', body: { id: icon.id } })
    }
  }
}

/**
 * A page that carries the stored admin session.
 *
 * `browser.newPage()` does NOT inherit the project's storageState, so a
 * beforeAll that used it was talking to WordPress as a stranger: the reads it
 * happened to make still worked, and the first write came back 403.
 */
export async function adminPage(browser) {
  const context = await browser.newContext({ storageState: 'e2e/.auth/admin.json' })
  const page = await context.newPage()
  return { page, context }
}
