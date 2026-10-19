/*
 * A build never leaves build/ without a whole bundle in it.
 *
 * wp-env serves build/ live, and panel.php enqueues nothing when panel.js is
 * not readable: a page loaded mid-build got an empty panel and an e2e spec
 * timed out on a button that was never drawn (Pro measured it, 2026-10-19).
 *
 * Usage: node tests/build-swap.mjs   -> exits non-zero on a gap.
 */
import { spawn } from 'node:child_process'
import { readFileSync, existsSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
// The bundle, and one file vite copies from public/: both must stay readable.
const files = ['build/panel.js', 'build/fonts/ubuntu-v21-latin-400-normal.woff2']
const read = (f) => (existsSync(join(root, f)) ? readFileSync(join(root, f), 'latin1') : null)

for (const f of files) {
  if (null === read(f)) {
    console.error(`RED  ${f} is missing before the build; build once first`)
    process.exit(1)
  }
}
const before = Object.fromEntries(files.map((f) => [f, read(f)]))
const seen = Object.fromEntries(files.map((f) => [f, new Set()]))

const started = Date.now()
let done = false
const watching = (async () => {
  while (!done) {
    for (const f of files) {
      seen[f].add(read(f))
    }
    await new Promise((r) => setTimeout(r, 2))
  }
})()

await new Promise((resolve, reject) => {
  const p = spawn('npx', ['vite', 'build'], { cwd: root, stdio: 'ignore' })
  p.on('exit', (code) => (0 === code ? resolve() : reject(new Error(`vite build exited ${code}`))))
})
done = true
await watching

let fail = 0
for (const f of files) {
  const after = read(f)
  if (null === after) {
    console.error(`RED  ${f} is gone after the build`)
    fail = 1
    continue
  }
  if ('build/panel.js' === f && statSync(join(root, f)).mtimeMs < started) {
    console.error(`RED  ${f} was not rewritten by the build`)
    fail = 1
  }
  for (const s of seen[f]) {
    if (null === s) {
      console.error(`RED  ${f} was missing during the build`)
      fail = 1
      break
    }
    if (s !== before[f] && s !== after) {
      console.error(`RED  ${f} was read half-written (${s.length} of ${after.length} bytes)`)
      fail = 1
      break
    }
  }
}

if (!fail) {
  console.log('build swap: green')
}
process.exit(fail)
