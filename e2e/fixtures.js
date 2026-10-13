import { test as base, expect } from '@playwright/test'

/*
 * Every spec fails on a console error, not only on its own assertions.
 *
 * React reports a broken hook order, a missing key or a ref handed to a plain
 * function component as a console error and carries on drawing -- so a screen
 * can look right in a screenshot and be wrong. One of those (a hook below an
 * early return) shipped before anything watched for it.
 */
export const test = base.extend({
  page: async ({ page }, use) => {
    const errors = []
    page.on('console', (m) => {
      if ('error' === m.type()) {
        errors.push(m.text())
      }
    })
    page.on('pageerror', (e) => errors.push(String(e)))
    await use(page)
    expect(errors, `console errors:\n${errors.join('\n')}`).toEqual([])
  },
})

export { expect }
