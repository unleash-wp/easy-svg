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
// A tab's CAPABILITY is not passed here: it is declared in PHP, through the
// `easy_svg_panel_menu_tabs` filter, which takes
// `array( 'label' => .., 'capability' => .. )` beside the plain label it took
// before. The menu and the rail then agree, because both read the one answer
// PHP gave -- and a capability a browser could pass would be worth nothing.
//
// Level 3 adds collections inside the library tab, so an add-on's icons sit in
// the one grid instead of a screen of their own:
//
//   window.EasySvgPanel.registerCollections({ id, load })
//
//   load({ signal }) => Promise<Array<{
//     id: string, label: string, readonly?: boolean,
//     icons: Array<{ key, slug, ref, label, content }>,
//   }>>
//
// `ref` is the address a template calls (`set/slug`); `content` is markup the
// add-on already sanitised and hardened. The library lists them on the left
// and filters the grid; it offers no rename or delete on them.
//
// A provider may also take moves. A tile dropped on one of its collections
// that is not `readonly` -- or sent there through the tile's Move-to menu --
// calls
//
//   move({ refs: ['easy-svg/slug' | 'set/slug', ...], to: collectionId })
//     => Promise<{ message: string, undo?: () => Promise<{ message }> }>
//
// and the library says the message, with Undo when there is a way back.
//
// And it may let collections be managed from the sidebar. Each is optional;
// the library offers only what the provider takes, and never on a `readonly`
// collection:
//
//   create({ label })              => Promise<{ id, message? }>  // id: the new collection, shown at once
//   rename({ id, label, collection }) => Promise<{ message? }>
//   trash({ id, collection })      => Promise<{ message, undo?: () => Promise<{ message }> }>
//
// And the icons inside a collection, where the grid shows them:
//
//   relabel({ ref, label, collection }) => Promise<{ message? }>
//   remove({ refs, collection })        => Promise<{ message, undo?: () => Promise<{ message }> }>
//   add({ icons: [{ name, markup }], to, collection })
//     => Promise<{ message, rows?: [{ file, ok, message }], undo?: () => Promise<{ message }> }>
//
//   recolour({ refs, collection })               => Promise<{ message, undo? }>
//   replaceDrawing({ ref, markup, collection })  => Promise<{ message, undo? }>
//
// With `add`, a file dropped, picked or pasted while that collection is on
// screen goes to it instead of the library. A removal is not asked twice: it
// is said with Undo instead.
//
// And an icon's earlier drawings -- a free library icon's too, with
// `collection` null:
//
//   history({ ref, collection })                 => Promise<{ versions: [{ id, date, since, markup, current }], note? }>
//   restoreVersion({ ref, version, collection }) => Promise<{ message }>
//
// `markup` is drawn in the panel, so the provider hands it over hardened.
//
// And the icons picked in the grid, from any collections, exported together;
// the provider delivers the file itself:
//
//   exportIcons({ refs }) => Promise<{ message }>
//   exportMax: number     // more picked than this, and Export is not offered
//
// A selection is moved with the provider's move(), in batches of 25.
//
// And a hint for the sidebar, when something it needs is switched off:
//
//   hint: { text, action?: { label, run: () => Promise } }
//
// `collection` is the object the provider's own load() gave, so whatever it
// carried there -- a post id, say -- comes back without a lookup.
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
  const providers = new Map()
  const listeners = new Set()
  const notify = () =>
    listeners.forEach((fn) => {
      try {
        fn()
      } catch {
        /* a bad listener must not break registration */
      }
    })

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
      notify()
    },
    registerCollections(provider) {
      if (!provider || !provider.id || typeof provider.load !== 'function') return
      providers.set(provider.id, provider)
      notify()
    },
    collectionProviders() {
      return [...providers.values()]
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
