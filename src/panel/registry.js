// The extension API the free panel exposes and the Pro plugin consumes.
//
//   window.EasySvgPanel.registerTab({ id, render })   // Pro fills a pro tab
//   window.EasySvgPanel.subscribe(fn)                 // the panel re-renders on change
//   window.EasySvgPanel.get(id)                       // the registered tab, or undefined
//
// What a tab may pass, beyond id and render (EASY_SVG_PANEL_API 2):
//
//   label       string
//   icon        a keyword this panel's icons.jsx resolves, OR the drawing
//               itself as React children of an <svg> -- because icons.jsx is
//               in THIS plugin, so a keyword it has never heard of used to be
//               a blank square an add-on could not fill
//   order       number, within its area
//
// window.EasySvgPanel.api carries that level, so an add-on that must also run
// on an older free plugin can ask rather than guess.
//
// Unknown fields are passed through untouched: this registry stores the object
// it is handed, and the panel reads what it understands.
//
// Pro references window.EasySvgPanel directly (it does NOT bundle this file), and
// its bundle is enqueued with the panel bundle as a dependency, so this registry
// always exists first. If Pro happens to register before the panel renders, the
// panel reads the registry on first render; if after, the subscription re-renders.
export function installRegistry() {
  if (typeof window === 'undefined') return
  if (window.EasySvgPanel && window.EasySvgPanel.__installed) return

  const registered = new Map()
  const listeners = new Set()

  window.EasySvgPanel = {
    __installed: true,
    // The contract level, read from the panel's own localised data. An add-on
    // that wants a level-2 field and must also run on an older free plugin
    // reads this instead of guessing; on a free plugin that predates it the
    // property is simply absent.
    api: Number((window.EasySvgPanelData && window.EasySvgPanelData.panelApi) || 1),
    registerTab(tab) {
      if (!tab || !tab.id || typeof tab.render !== 'function') return
      registered.set(tab.id, tab)
      listeners.forEach((fn) => {
        try {
          fn()
        } catch {
          /* a bad listener must not break registration */
        }
      })
    },
    get(id) {
      return registered.get(id)
    },
    list() {
      return [...registered.values()]
    },
    subscribe(fn) {
      listeners.add(fn)
      return () => listeners.delete(fn)
    },
  }
}

export function getRegistry() {
  return (typeof window !== 'undefined' && window.EasySvgPanel) || null
}
