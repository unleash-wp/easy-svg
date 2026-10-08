import { __, sprintf } from '@wordpress/i18n'

// Thin REST client for the panel. Nonce rides X-WP-Nonce; credentials stay
// same-origin so the logged-in admin cookie authenticates. Free routes live
// under easy-svg/v1; a ctx from the panel carries { restRoot, nonce }.
export function makeApi({ restRoot, nonce }, namespace = 'easy-svg/v1') {
  const base = `${restRoot}${namespace}`
  const headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }

  async function readError(response, fallback) {
    try {
      const data = await response.json()
      return data?.message || fallback
    } catch {
      return fallback
    }
  }
  async function get(path) {
    const r = await fetch(`${base}${path}`, { headers, credentials: 'same-origin' })
    if (!r.ok) {
      // translators: %d: HTTP status code.
      throw new Error(await readError(r, sprintf(__('Load failed (%d)', 'easy-svg'), r.status)))
    }
    return r.json()
  }
  async function post(path, body) {
    const r = await fetch(`${base}${path}`, {
      method: 'POST',
      headers,
      credentials: 'same-origin',
      body: JSON.stringify(body || {}),
    })
    if (!r.ok) {
      // translators: %d: HTTP status code.
      throw new Error(await readError(r, sprintf(__('Save failed (%d)', 'easy-svg'), r.status)))
    }
    return r.json()
  }
  return { get, post }
}
