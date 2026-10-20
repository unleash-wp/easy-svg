import { test, expect } from './fixtures.js'
import { PANEL } from './helpers.js'

/*
 * One frame, not three, on one ground to the bottom of the page.
 *
 * The panel was a bordered window inside wp-admin's margins, with a grey well
 * inside it and cards inside that, and below the window the page turned white:
 * wp-admin's body is only as tall as the viewport, and the html element under
 * it is white. The header and footer now run the full width, the screen sits
 * directly on the grey, and the grey goes to the end of the page.
 */
const GREY = 'rgb(238, 241, 246)'

test('the panel runs edge to edge on grey that reaches the end of the page', async ({ page }) => {
  // A short screen, so the page ends below the panel rather than inside it.
  await page.goto('/wp-admin/admin.php?page=easy-svg-icons&tab=settings')
  await expect(page.locator(`${PANEL} [role="tab"][aria-selected="true"]`).first()).toBeVisible()

  const m = await page.evaluate(() => {
    const content = document.querySelector('#wpcontent').getBoundingClientRect()
    const frame = document.querySelector('#easy-svg-panel > div')
    const box = frame.getBoundingClientRect()
    const style = getComputedStyle(frame)
    return {
      html: getComputedStyle(document.documentElement).backgroundColor,
      left: Math.round(box.left - content.left),
      right: Math.round(content.right - box.right),
      border: style.borderTopWidth,
      fillsViewport: box.bottom + window.scrollY >= window.innerHeight - 1,
    }
  })

  expect(m.html, 'the page below the panel').toBe(GREY)
  expect(m.left, 'gap left of the panel').toBe(0)
  expect(m.right, 'gap right of the panel').toBe(0)
  expect(m.border, 'a border around the whole panel').toBe('0px')
  expect(m.fillsViewport, 'the panel ends above the bottom of the screen').toBe(true)
})
