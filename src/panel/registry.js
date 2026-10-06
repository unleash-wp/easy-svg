// The extension API the free panel exposes and the Pro plugin consumes.
//
//   window.EasySvgPanel.registerTab({ id, render })   // Pro fills a pro tab
//   window.EasySvgPanel.subscribe(fn)                 // the panel re-renders on change
//   window.EasySvgPanel.get(id)                       // the registered tab, or undefined
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
