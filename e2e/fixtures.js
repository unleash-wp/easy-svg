import { test as base, expect } from '@playwright/test'

/*
 * Every spec fails on a console error, not only on its own assertions.
 *
 * React reports a broken hook order, a missing key or a ref handed to a plain
 * function component as a console error and carries on drawing -- so a screen
 * can look right in a screenshot and be wrong. One of those (a hook below an
 * early return) shipped before anything watched for it.
 */
/*
 * What another plugin shouts on the post editor, and we cannot fix.
 *
 * The specs that open a post editor load every other plugin's editor bundle
 * too, and one of them warns about React's `defaultProps` on every render. It
 * is their deprecation, in their code, and failing our suite on it would mean
 * the suite reports whichever plugins happen to be installed.
 */
const FOREIGN = [ /Support for defaultProps will be removed/ ]

export const test = base.extend({
  /*
   * What THIS spec expects the browser to shout about.
   *
   * A test that drives a broken state produces the console noise of that broken
   * state: an Icon block pointing at an icon that is gone makes core ask for it
   * and take a 404, which is the condition under test rather than a defect.
   * Per spec, and declared in it, so the suite stays strict everywhere else.
   */
  consoleAllow: [ [], { option: true } ],

  page: async ({ page, consoleAllow }, use) => {
    const errors = []
    const expected = FOREIGN.concat( consoleAllow )
    const ours = (text) => ! expected.some((pattern) => pattern.test(text))
    page.on('console', (m) => {
      if ('error' === m.type() && ours(m.text())) {
        errors.push(m.text())
      }
    })
    page.on('pageerror', (e) => {
      if (ours(String(e))) {
        errors.push(String(e))
      }
    })
    await use(page)
    expect(errors, `console errors:\n${errors.join('\n')}`).toEqual([])
  },
})

export { expect }
