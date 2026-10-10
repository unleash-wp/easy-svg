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
  /*
   * `signal` is how a tab cancels a load it no longer needs.
   *
   * A panel tab unmounts when you switch away from it (the Tabs root is set to
   * unmountOnExit), so a load started on mount can resolve into a component
   * that is gone -- and worse, two loads of the same resource can resolve out
   * of order, so an older payload lands last and a tab shows pre-save values.
   * The caller passes an AbortController's signal and aborts it in the effect's
   * cleanup; fetch then rejects with AbortError, which the caller ignores.
   */
  async function get(path, { signal } = {}) {
    const r = await fetch(`${base}${path}`, { headers, credentials: 'same-origin', signal })
    if (!r.ok) {
      // translators: %d: HTTP status code.
      throw new Error(await readError(r, sprintf(__('Load failed (%d)', 'easy-svg'), r.status)))
    }
    return r.json()
  }
  async function post(path, body, { signal } = {}) {
    const r = await fetch(`${base}${path}`, {
      method: 'POST',
      headers,
      credentials: 'same-origin',
      body: JSON.stringify(body || {}),
      signal,
    })
    if (!r.ok) {
      // translators: %d: HTTP status code.
      throw new Error(await readError(r, sprintf(__('Save failed (%d)', 'easy-svg'), r.status)))
    }
    return r.json()
  }
  return { get, post }
}
