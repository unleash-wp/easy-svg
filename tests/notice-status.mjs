/*
 * Every notice says what it is.
 *
 * <Notice> falls back to `info` when it is given no `status`, and 18 call
 * sites relied on that: "This site is activated" and "the scan stopped
 * early" looked the same, neither green nor amber. A notice now names its
 * status (or `bad` for an error), and this fails on one that does not.
 *
 * Usage: node tests/notice-status.mjs   -> exits non-zero naming the line.
 */
import { readFileSync, readdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const files = readdirSync(join(root, 'src'), { recursive: true })
  .filter((n) => n.endsWith('.jsx'))
  .map((n) => join(root, 'src', n))

let fail = 0
for (const file of files) {
  const src = readFileSync(file, 'utf8')
  for (const m of src.matchAll(/<Notice\b/g)) {
    // The opening tag ends at the first `>` outside a {} expression.
    let i = m.index + m[0].length
    for (let depth = 0; '>' !== src[i] || depth; i++) {
      depth += '{' === src[i] ? 1 : '}' === src[i] ? -1 : 0
    }
    const tag = src.slice(m.index, i)
    if (!/\bstatus=/.test(tag) && !/\bbad\b/.test(tag)) {
      fail = 1
      console.error(`RED  ${file.slice(root.length + 1)}:${src.slice(0, m.index).split('\n').length} has no status`)
    }
  }
}
if (!fail) {
  console.log('every notice names its status')
}
process.exit(fail)
