import { test as base, expect } from '@playwright/test'
import { createHash } from 'node:crypto'
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'

/*
 * The bundles wp-env serves, read from the checkouts it mounts.
 *
 * Over colima's virtiofs a bundle that changes while pages load it is served
 * for a while with the old length and the new bytes: a 200 that ends mid-script
 * ("Unexpected end of input"). No test can pass on that, so it is said as such.
 */
function servedBundles() {
  const config = existsSync('.wp-env.override.json') ? '.wp-env.override.json' : '.wp-env.json'
  const plugins = JSON.parse(readFileSync(config, 'utf8')).plugins || ['.']
  const stamp = {}
  for (const dir of plugins.filter((p) => 'string' === typeof p && !p.includes('://'))) {
    const build = join(dir, 'build')
    for (const name of existsSync(build) ? readdirSync(build).filter((n) => n.endsWith('.js')) : []) {
      const file = join(build, name)
      stamp[file] = existsSync(file) ? createHash('sha1').update(readFileSync(file)).digest('hex') : 'missing'
    }
  }
  return stamp
}

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
    const served = servedBundles()
    await use(page)
    const now = servedBundles()
    const changed = Object.keys({ ...served, ...now }).filter((f) => served[f] !== now[f])
    expect(changed, 'rebuilt while this test ran, so it read two builds; rerun once nobody is building').toEqual([])
    expect(errors, `console errors:\n${errors.join('\n')}`).toEqual([])
  },
})

export { expect }
