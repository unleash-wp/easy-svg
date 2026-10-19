import { useEffect, useRef, useState } from 'react'
import {
  ActionBar,
  Box,
  Checkbox,
  Clipboard,
  Dialog,
  FileUpload,
  HStack,
  InputGroup,
  Menu,
  Portal,
  SimpleGrid,
  Stack,
  Text,
  Textarea,
} from '@chakra-ui/react'
import DOMPurify from 'dompurify'
import { __, _n, sprintf } from '@wordpress/i18n'
import { Section, FieldRow, SkeletonRows, Button, TextInput, IconAction, useDropZone } from '../../ui.jsx'
import { Notice, SearchField, ReportList } from '../parts.jsx'
import { makeApi } from '../api.js'
import { getRegistry } from '../registry.js'

// Defence in depth for the preview: the stored markup is already server-hardened,
// and a pasted draft is sanitised here before it ever touches innerHTML. The
// server re-sanitises + hardens on save regardless.
const sanitizeSvg = (markup) => {
  try {
    return DOMPurify.sanitize(String(markup || ''), { USE_PROFILES: { svg: true, svgFilters: true } })
  } catch {
    return ''
  }
}

// What a theme or a template writes to render the icon. This is what the copy
// action puts on the clipboard, and the only reason a slug is worth seeing.
const iconCall = (slug) => `easy_svg_icon( '${slug}' )`

// Core's Icon block passes its icons through an allow-list of svg, path and
// polygon (class-wp-icons-registry.php, 7.0): a rect, a circle or a line is
// dropped, and an icon drawn with them renders as nothing at all.
const BLOCK_DRAWS = new Set(['path', 'polygon'])
const BLOCK_WRAPS = new Set(['svg', 'g', 'title', 'desc'])
const blockCanDraw = (markup) => {
  try {
    const doc = new window.DOMParser().parseFromString(String(markup || ''), 'image/svg+xml')
    const names = Array.from(doc.getElementsByTagName('*')).map((el) => el.localName)
    return names.some((n) => BLOCK_DRAWS.has(n)) && names.every((n) => BLOCK_DRAWS.has(n) || BLOCK_WRAPS.has(n))
  } catch {
    return false
  }
}

// The three ways to place an icon, each copied as it is written. The block is
// core's Icon block, which exists only where WordPress has the icon registry.
const callForms = (ic, iconBlock) => [
  { id: 'php', label: __('PHP', 'easy-svg'), text: iconCall(ic.ref || ic.slug) },
  { id: 'shortcode', label: __('Shortcode', 'easy-svg'), text: `[easy_svg_icon name="${refOf(ic)}"]` },
  ...(iconBlock
    ? [
        blockCanDraw(ic.content)
          ? { id: 'block', label: __('Block', 'easy-svg'), text: `<!-- wp:icon {"icon":"${refOf(ic)}"} /-->` }
          : {
              id: 'block',
              label: __('Block', 'easy-svg'),
              text: __('Not for this icon: the Icon block draws only paths and polygons.', 'easy-svg'),
              disabled: true,
            },
      ]
    : []),
]

const nameOf = (ic) => ic.label || ic.slug

// A revision's date arrives as WordPress's GMT column; shown in the viewer's time.
const when = (gmt) => {
  const t = Date.parse(`${String(gmt || '').replace(' ', 'T')}Z`)
  return Number.isNaN(t) ? String(gmt || '') : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(t)
}

// What a tile carries when it is dragged, and the address it moves by.
const MOVE_TYPE = 'application/x-easy-svg-icon'
// How many addresses one move hands a provider; Pro's route takes 25.
const MOVE_BATCH = 25
const refOf = (ic) => ic.ref || `easy-svg/${ic.slug}`

// A taken name is stored as `star-2`, and that slug is what a call must use, so
// the message names it instead of saying only that something was added.
const addedMessage = (added) =>
  added?.slug && added?.wanted && added.slug !== added.wanted
    ? sprintf(
        // translators: %1$s: the slug the icon was stored under. %2$s: the slug that was already taken.
        __('Added as “%1$s”: “%2$s” was taken.', 'easy-svg'),
        added.slug,
        added.wanted
      )
    : __('Icon added.', 'easy-svg')

/*
 * The chooser asks for .svg; a DROP brings whatever was dropped. Nothing is
 * pre-judged in the browser on purpose: the server owns the verdict and owns the
 * sentences for it (easy_svg_panel_add_message), so a file that is not an SVG is
 * refused by the thing that knows why rather than guessed at here. The size cap
 * it enforces is the same one the Settings tab shows.
 */
const FILE_ACCEPT = '.svg,image/svg+xml'
// How many files one pick may carry. Ark's FileUpload takes ONE without it,
// whatever `multiple` says -- the chooser said "files" and took a single file.
const MAX_FILES = 100

// What /library/media accepts in one request, mirrored here so the picker can
// send a larger selection as several. The server refuses more than this; it does
// not silently take the first few.
const MEDIA_CHUNK = 25

const readText = (file) =>
  new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(String(reader.result || ''))
    reader.onerror = () => reject(new Error(__('That file could not be read.', 'easy-svg')))
    reader.readAsText(file)
  })

// 14px glyphs on currentColor, so they inherit the button's hover colours (the
// ghost danger variant turns its text red and the trash goes red with it).
// Decorative throughout: each one sits inside a button that carries an
// aria-label, and focusable="false" keeps legacy Edge out of the tab order.
function Glyph({ children }) {
  return (
    <Box
      as="svg"
      aria-hidden="true"
      focusable="false"
      viewBox="0 0 24 24"
      width="14px"
      height="14px"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {children}
    </Box>
  )
}

const CopyGlyph = () => (
  <Glyph>
    <rect x="9" y="9" width="12" height="12" rx="2" />
    <path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1" />
  </Glyph>
)
const MoveGlyph = () => (
  <Glyph>
    <path d="M3 7h13l-3-3M21 17H8l3 3" />
  </Glyph>
)
const RenameGlyph = () => (
  <Glyph>
    <path d="M12 20h9" />
    <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
  </Glyph>
)
const TrashGlyph = () => (
  <Glyph>
    <path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" />
  </Glyph>
)
const AddGlyph = () => (
  <Glyph>
    <path d="M12 5v14M5 12h14" />
  </Glyph>
)
const HistoryGlyph = () => (
  <Glyph>
    <path d="M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5M12 7v5l3 2" />
  </Glyph>
)
const MoreGlyph = () => (
  <Glyph>
    <path d="M5 12h.01M12 12h.01M19 12h.01" />
  </Glyph>
)
const DownloadGlyph = () => (
  <Glyph>
    <path d="M12 4v12M7 11l5 5 5-5M4 17v2a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2" />
  </Glyph>
)
const UploadGlyph = () => (
  <Glyph>
    <path d="M12 16V4M7 9l5-5 5 5M4 17v2a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2" />
  </Glyph>
)

// How many files of how many were kept. The verdict per file is the server's own
// sentence; only the tally is assembled here.
function reportSummary(rows) {
  const stored = rows.filter((r) => r.ok).length
  return sprintf(
    // translators: 1: number of files stored, 2: number of files picked.
    _n('%1$d of %2$d file stored.', '%1$d of %2$d files stored.', rows.length, 'easy-svg'),
    stored,
    rows.length
  )
}

// The library routes answer { items, total, truncated }. They used to answer a
// bare array, and a site with more icons than one response carries was shown
// the first 500 with nothing said about the rest. Both shapes are accepted so
// the client never depends on which one arrives.
function listOf(res) {
  return Array.isArray(res) ? res : (res && Array.isArray(res.items) ? res.items : [])
}
function totalOf(res, fallback) {
  return res && 'number' === typeof res.total ? res.total : fallback
}

export default function Library({ ctx }) {
  const api = makeApi(ctx)
  const [icons, setIcons] = useState(null)
  const [total, setTotal] = useState(0)
  const [libraryTotal, setLibraryTotal] = useState(0)
  const [err, setErr] = useState('')
  const [status, setStatus] = useState('')
  // The file picker keeps what it accepted and refuses the same file again
  // without a word; a new key after each batch gives it an empty hand.
  const [pickRound, setPickRound] = useState(0)
  const [busy, setBusy] = useState(false)
  const [query, setQuery] = useState('')
  const [searching, setSearching] = useState(false)
  const [report, setReport] = useState(null)
  const [pasting, setPasting] = useState(false)
  const [label, setLabel] = useState('')
  const [markup, setMarkup] = useState('')
  const [selecting, setSelecting] = useState(false)
  const [picked, setPicked] = useState(() => new Set())
  const [editing, setEditing] = useState(null)
  // Deleting is the one unrecoverable act on this screen, so it takes a second
  // click: { id } for one tile, { bulk: true } for the selection bar.
  const [confirming, setConfirming] = useState(null)
  // The call to copy by hand when there is no clipboard API, dismissable.
  const [copyHelp, setCopyHelp] = useState('')
  const fileRef = useRef(null)
  // A new drawing for one set icon: the tile it is for, while the picker is open.
  const replaceRef = useRef(null)
  const [replacing, setReplacing] = useState(null)
  // An add-on's collections (contract 3), and which of them the grid shows:
  // 'all', 'library' or a collection id.
  const [collections, setCollections] = useState([])
  // A link can open the grid on one collection (`collection=`) or with a new
  // set being named (`new-set=1`): where the Sets tab and the set list lead.
  const linked = useRef(
    typeof window === 'undefined' ? new URLSearchParams() : new URLSearchParams(window.location.search)
  )
  const [view, setView] = useState('all')
  // The last move's answer, and its way back when the add-on gave one.
  const [moved, setMoved] = useState(null)
  const reloadCollections = useRef(() => {})
  // The providers themselves, for what they offer beyond their collections.
  const [providers, setProviders] = useState([])
  // A name being typed in the sidebar: { id: 'new' | collectionId, value }.
  const [naming, setNaming] = useState(null)

  // The link waits for what it names: an add-on registers its collections
  // after the panel's first load. A collection that never comes leaves the
  // grid on All icons, and a click of the person's own ends the wait.
  useEffect(() => {
    const wanted = linked.current.get('collection')
    if (wanted && collections.some((c) => c.id === wanted)) {
      linked.current.delete('collection')
      setView(wanted)
    }
    if ('1' === linked.current.get('new-set') && providers.some((p) => 'function' === typeof p.create)) {
      linked.current.delete('new-set')
      setNaming({ id: 'new', value: '' })
    }
  }, [collections, providers])

  function choose(id) {
    linked.current.delete('collection')
    setView(id)
  }
  // One icon's earlier drawings: { ic, provider, versions: null while loading }.
  const [past, setPast] = useState(null)

  useEffect(() => {
    const reg = getRegistry()
    if (!reg || typeof reg.collectionProviders !== 'function') {
      return undefined
    }
    let ac = null
    const load = () => {
      ac?.abort()
      ac = new AbortController()
      const signal = ac.signal
      setProviders(reg.collectionProviders())
      Promise.all(
        reg
          .collectionProviders()
          .map((p) =>
            Promise.resolve(p.load({ signal }))
              .then((list) => (Array.isArray(list) ? list.map((c) => ({ ...c, provider: p })) : []))
              .catch(() => [])
          )
      ).then((lists) => {
        if (!signal.aborted) {
          setCollections(lists.flat().filter((c) => c && c.id && Array.isArray(c.icons)))
        }
      })
    }
    reloadCollections.current = load
    load()
    const off = reg.subscribe(load)
    return () => {
      off()
      ac?.abort()
    }
  }, [])

  // One place unwraps a library response, so a route that still answers the old
  // bare array keeps working and the total only has to be read once.
  const take = (res, filtered = false) => {
    const list = listOf(res)
    setIcons(list)
    setTotal(totalOf(res, list.length))
    /*
     * How big the library IS, which a search's answer cannot say: its list is
     * the matches. The heading read them as the library and told a site with
     * three icons it had one. Every other route answers the whole library.
     */
    if (!filtered) {
      setLibraryTotal(totalOf(res, list.length))
    }
    // A selection may not outlive the list it was made in. Select all with 400
    // loaded, then search: without this the 397 off-screen ids stay picked, the
    // bar still counts them, and Remove deletes what nobody can see.
    setPicked((prev) => {
      if (0 === prev.size) {
        return prev
      }
      const live = new Set(list.map((ic) => ic.id))
      const next = new Set()
      prev.forEach((id) => {
        if (live.has(id)) {
          next.add(id)
        }
      })
      return next.size === prev.size ? prev : next
    })
  }

  // Opening the paste form used to look like a dead click: it rendered below
  // the fold with nothing focused. Focus pulls it into view.
  useEffect(() => {
    if (!pasting) {
      return
    }
    const el = document.getElementById('esw-icon-name')
    if (el) {
      el.focus()
      el.scrollIntoView({ block: 'center' })
    }
  }, [pasting])

  /*
   * The server searches, debounced: filtering one response would miss the rest
   * of the library and say "no icon matches" about it. Each keystroke replaces
   * the request in flight, so an older answer cannot land last.
   */
  useEffect(() => {
    if (!ctx.iconsEnabled) return undefined
    const ac = new AbortController()
    const term = query.trim()
    const run = () => {
      setSearching(!!term)
      api
        .get(term ? `/library?search=${encodeURIComponent(term)}` : '/library', { signal: ac.signal })
        .then((res) => {
          take(res, !!term)
          setSearching(false)
        })
        .catch((e) => {
          if ('AbortError' !== e.name) {
            setErr(e.message)
            setSearching(false)
          }
        })
    }
    // No wait for the first load, and a short one for typing.
    const timer = window.setTimeout(run, query === '' ? 0 : 250)
    return () => {
      window.clearTimeout(timer)
      ac.abort()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ctx.iconsEnabled, query])

  const all = icons || []

  // The grid takes a drop too, because adding where you are already looking is
  // the obvious gesture. The surface above is Chakra's and owns its own.
  //
  // Above the early return below for the same reason as `shown`: a hook may not
  // be skipped, and this tab re-renders with iconsEnabled flipped the moment
  // the Settings tab is saved.
  const grid = useDropZone({ onFiles: addFiles, disabled: busy })

  // Above the early return below, because a hook may not be skipped: this tab
  // re-renders with iconsEnabled flipped the moment the Settings tab is saved.
  // What the server answered IS the list: it searched name and slug across the
  // whole library, which is the part a browser-side filter could not reach.
  // A collection's icons join the grid as tiles of their own: keyed by their
  // address, never selectable, renamed or deleted from here.
  const term = query.trim().toLowerCase()
  const inCollection = (c) =>
    c.icons
      .filter((ic) => !term || `${ic.label || ''} ${ic.slug || ''} ${ic.ref || ''}`.toLowerCase().includes(term))
      .map((ic) => ({
        ...ic,
        id: ic.key || ic.ref,
        readonly: true,
        collection: c.id,
        source: c,
        canRename: !c.readonly && 'function' === typeof c.provider?.relabel,
        canRemove: !c.readonly && 'function' === typeof c.provider?.remove,
        canRecolour: !c.readonly && 'function' === typeof c.provider?.recolour,
        canReplace: !c.readonly && 'function' === typeof c.provider?.replaceDrawing,
      }))
  const shown =
    'library' === view
      ? all
      : 'all' === view
        ? [...all, ...collections.flatMap(inCollection)]
        : collections.filter((c) => c.id === view).flatMap(inCollection)
  // Every shown tile can be picked: moving and exporting reach any of them.
  // Removing in bulk reaches only the library's own, which this screen stores.
  const own = shown
  // Where a tile may go: a collection that is not read only, of a provider
  // that takes moves.
  const targets = collections.filter((c) => !c.readonly && c.provider && 'function' === typeof c.provider.move)

  async function moveTo(collection, refs) {
    const going = refs.filter((ref) => !ref.startsWith(`${collection?.id}/`))
    if (!collection || !going.length) {
      return
    }
    setBusy(true)
    setErr('')
    // A provider takes a bounded batch, so a big selection goes in turns; the
    // way back undoes them newest first.
    const said = []
    const undos = []
    try {
      for (let i = 0; i < going.length; i += MOVE_BATCH) {
        const done = await collection.provider.move({ refs: going.slice(i, i + MOVE_BATCH), to: collection.id })
        said.push(done?.message || __('Moved.', 'easy-svg'))
        if ('function' === typeof done?.undo) {
          undos.push(done.undo)
        }
      }
    } catch (e) {
      setErr(e.message)
    } finally {
      setMoved(
        said.length
          ? {
              message: said.join(' · '),
              undo: undos.length
                ? async () => {
                    const back = []
                    for (const undo of undos.slice().reverse()) {
                      back.push((await undo())?.message || __('Undone.', 'easy-svg'))
                    }
                    return { message: back.join(' · ') }
                  }
                : null,
            }
          : null
      )
      if (said.length) {
        setPicked(new Set())
      }
      reloadCollections.current()
      setBusy(false)
    }
  }

  const exporter = providers.find((p) => 'function' === typeof p.exportIcons)
  const pickedTiles = shown.filter((ic) => picked.has(ic.id))
  const pickedLibrary = pickedTiles.filter((ic) => !ic.readonly).map((ic) => ic.id)
  const exportTooMany = exporter && Number.isFinite(exporter.exportMax) && pickedTiles.length > exporter.exportMax

  // Picked icons of collections that take a recolour, one call per collection.
  const recolourable = pickedTiles.filter((ic) => ic.canRecolour)

  async function recolourPicked() {
    const groups = new Map()
    recolourable.forEach((ic) => groups.set(ic.source, [...(groups.get(ic.source) || []), refOf(ic)]))
    const said = []
    const undos = []
    await act(async () => {
      for (const [collection, refs] of groups) {
        const done = await collection.provider.recolour({ refs, collection })
        said.push(done?.message || __('Done.', 'easy-svg'))
        if ('function' === typeof done?.undo) {
          undos.push(done.undo)
        }
      }
      return {
        message: said.join(' · '),
        undo: undos.length
          ? async () => {
              for (const undo of undos.slice().reverse()) {
                await undo()
              }
              return { message: __('Undone.', 'easy-svg') }
            }
          : null,
      }
    })
  }

  async function exportPicked() {
    if (!exporter || !pickedTiles.length || exportTooMany) {
      return
    }
    await act(() => exporter.exportIcons({ refs: pickedTiles.map(refOf) }))
  }

  // A sidebar action: its answer is said where a move's is, Undo included.
  async function act(run) {
    setBusy(true)
    setErr('')
    try {
      const done = await run()
      setMoved({ message: done?.message || __('Done.', 'easy-svg'), undo: 'function' === typeof done?.undo ? done.undo : null })
      reloadCollections.current()
      return done
    } catch (e) {
      setErr(e.message)
      return null
    } finally {
      setBusy(false)
    }
  }

  const creator = providers.find((p) => 'function' === typeof p.create)
  // What a provider wants said where its collections would be -- that a
  // feature they need is off, and the way to switch it on.
  const hints = providers.filter((p) => p.hint && p.hint.text)

  async function runHint(hint) {
    setBusy(true)
    setErr('')
    try {
      await hint.action.run()
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }
  // The set on screen, when it takes files: then an upload goes there.
  const intoSet = collections.find((c) => c.id === view && !c.readonly && 'function' === typeof c.provider?.add) || null
  // A set that imports bundles takes a ZIP too, in every chooser and drop.
  const acceptFiles = intoSet && 'function' === typeof intoSet.provider.importFile ? `${FILE_ACCEPT},.zip,application/zip` : FILE_ACCEPT

  async function saveName() {
    const label = (naming?.value || '').trim()
    const target = naming
    if (!label || !target) {
      setNaming(null)
      return
    }
    setNaming(null)
    if ('new' === target.id) {
      const made = await act(() => creator.create({ label }))
      if (made?.id) {
        setView(made.id)
      }
      return
    }
    const collection = collections.find((c) => c.id === target.id)
    if (collection && label !== (collection.label || collection.id)) {
      await act(() => collection.provider.rename({ id: collection.id, label, collection }))
    }
  }

  async function trashCollection(collection) {
    const done = await act(() => collection.provider.trash({ id: collection.id, collection }))
    if (done && view === collection.id) {
      setView('all')
    }
  }

  // Who keeps an icon's history: a set's own provider, or -- for a library
  // icon -- whichever provider offers it. A read-only collection has none.
  const historyOf = (ic) => {
    const p = ic.source ? (ic.source.readonly ? null : ic.source.provider) : providers.find((x) => 'function' === typeof x.history)
    return p && 'function' === typeof p.history && 'function' === typeof p.restoreVersion ? p : null
  }

  function openHistory(ic) {
    const provider = historyOf(ic)
    if (!provider) {
      return
    }
    setPast({ ic, provider, versions: null, note: '', error: '' })
    Promise.resolve(provider.history({ ref: refOf(ic), collection: ic.source || null }))
      .then((r) => setPast((p) => (p && p.ic === ic ? { ...p, versions: r?.versions ?? [], note: r?.note || '' } : p)))
      .catch((e) => setPast((p) => (p && p.ic === ic ? { ...p, versions: [], error: e.message } : p)))
  }

  async function restoreVersion(version) {
    const { ic, provider } = past || {}
    if (!provider) {
      return
    }
    setPast(null)
    const done = await act(() => provider.restoreVersion({ ref: refOf(ic), version: version.id, collection: ic.source || null }))
    // A library icon's drawing changed in the free store: read the page again.
    if (done && !ic.source) {
      const term = query.trim()
      api
        .get(term ? `/library?search=${encodeURIComponent(term)}` : '/library')
        .then((res) => take(res, !!term))
        .catch((e) => setErr(e.message))
    }
  }

  // Enter keeps the name, Escape drops it; leaving the field drops it too, so a
  // half-typed name never becomes a set by accident.
  const nameField = (label) => (
    <TextInput
      size="sm"
      autoFocus
      aria-label={label}
      value={naming?.value || ''}
      maxLength={100}
      onChange={(e) => setNaming({ ...naming, value: e.target.value })}
      onKeyDown={(e) => {
        if ('Enter' === e.key) {
          e.preventDefault()
          saveName()
        } else if ('Escape' === e.key) {
          e.preventDefault()
          setNaming(null)
        }
      }}
      onBlur={() => setNaming(null)}
    />
  )

  async function undoMove() {
    const undo = moved?.undo
    if (!undo) {
      return
    }
    setBusy(true)
    try {
      const back = await undo()
      setMoved({ message: back?.message || __('Undone.', 'easy-svg'), undo: null })
      reloadCollections.current()
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }

  const dropOn = (collection) =>
    targets.includes(collection)
      ? {
          onDragOver: (e) => {
            if (Array.from(e.dataTransfer?.types || []).includes(MOVE_TYPE)) {
              e.preventDefault()
              e.dataTransfer.dropEffect = 'move'
            }
          },
          onDrop: (e) => {
            const raw = e.dataTransfer?.getData(MOVE_TYPE)
            if (!raw) {
              return
            }
            e.preventDefault()
            try {
              moveTo(collection, JSON.parse(raw).refs || [])
            } catch {
              /* not a tile of this screen */
            }
          },
        }
      : {}
  // Every shown id, not a size comparison: a count can read "all" while the
  // picked ids belong to a list that is no longer on screen.
  const allPicked = own.length > 0 && own.every((ic) => picked.has(ic.id))

  // Every control on this screen depends on the Icon-library toggle, so nothing
  // is revealed while the feature is off; point back to Settings instead.
  if (!ctx.iconsEnabled) {
    return <Notice>{__('The icon library is off. Switch it on under the Settings tab.', 'easy-svg')}</Notice>
  }

  const clear = () => {
    setErr('')
    setStatus('')
    setCopyHelp('')
  }

  function stopSelecting() {
    setSelecting(false)
    setPicked(new Set())
    setConfirming(null)
  }

  function toggleAll() {
    setPicked((prev) => (own.every((ic) => prev.has(ic.id)) ? new Set() : new Set(own.map((ic) => ic.id))))
  }

  function togglePicked(id, on) {
    setPicked((prev) => {
      const next = new Set(prev)
      if (on) {
        next.add(id)
      } else {
        next.delete(id)
      }
      return next
    })
  }

  /*
   * One icon per request, because that is what the route takes -- so a batch is a
   * loop, and a loop that collects one verdict per FILE rather than one vague
   * sentence per batch. The refusal text is whatever the server answered; the
   * browser only decides which file it belongs to.
   *
   * Sequential, not parallel: every add answers with the whole fresh page, so the
   * last answer is the current truth, and parallel inserts would race for the
   * unique slug each of them is about to be given.
   */
  async function addFiles(fileList) {
    const files = Array.from(fileList || [])
    if (!files.length) {
      return
    }
    if (intoSet) {
      clear()
      setReport(null)
      const importer = 'function' === typeof intoSet.provider.importFile
      const icons = []
      const bundles = []
      const rows = []
      for (const file of files) {
        // A ZIP, or an SVG that is a font of glyphs, is a bundle of icons: the
        // set's own import reads it. Everything else is one icon.
        if (/\.zip$/i.test(file.name)) {
          bundles.push(file)
          continue
        }
        const text = await readText(file).catch(() => '')
        if (/<glyph\b/i.test(text)) {
          bundles.push(file)
          continue
        }
        icons.push({ name: file.name.replace(/\.svg$/i, ''), markup: text })
      }
      await act(async () => {
        const said = []
        const undos = []
        const take = (done) => {
          said.push(done?.message || __('Done.', 'easy-svg'))
          rows.push(...(done?.rows || []))
          if ('function' === typeof done?.undo) {
            undos.push(done.undo)
          }
        }
        if (icons.length) {
          take(await intoSet.provider.add({ icons, to: intoSet.id, collection: intoSet }))
        }
        for (const file of bundles) {
          if (!importer) {
            rows.push({ file: file.name, ok: false, message: __('This set cannot import a bundle.', 'easy-svg') })
            continue
          }
          try {
            take(await intoSet.provider.importFile({ file, collection: intoSet }))
          } catch (e) {
            rows.push({ file: file.name, ok: false, message: e.message })
          }
        }
        return {
          message: said.join(' · ') || __('Nothing was added.', 'easy-svg'),
          undo: undos.length
            ? async () => {
                for (const undo of undos.slice().reverse()) {
                  await undo()
                }
                return { message: __('Undone.', 'easy-svg') }
              }
            : null,
        }
      })
      setReport(rows.length ? rows : null)
      setPickRound((n) => n + 1)
      return
    }
    setBusy(true)
    clear()
    setReport(null)
    const rows = []
    let fresh = null
    for (const file of files) {
      try {
        const markupOfFile = await readText(file)
        fresh = await api.post('/library/add', { label: file.name.replace(/\.svg$/i, ''), markup: markupOfFile })
        rows.push({ file: file.name, ok: true, message: addedMessage(fresh?.added) })
      } catch (e) {
        rows.push({ file: file.name, ok: false, message: e.message })
      }
    }
    if (fresh) {
      take(fresh)
    }
    setReport(rows)
    setBusy(false)
    setPickRound((n) => n + 1)
  }

  /*
   * The media library, by id.
   *
   * Only ids travel: the server reads the file with get_attached_file(), so an
   * offloaded library -- S3, a CDN -- is a clear refusal per file instead of a
   * CORS error that would fail for exactly the sites that asked for this. The
   * frame is left to WordPress; wp_enqueue_media() puts it on this screen.
   */
  async function addFromMedia() {
    const media = window.wp && window.wp.media
    if (!media) {
      setErr(__('The media library did not load on this screen.', 'easy-svg'))
      return
    }
    const frame = media({
      title: __('Choose SVGs to add as icons', 'easy-svg'),
      button: { text: __('Add as icons', 'easy-svg') },
      library: { type: 'image/svg+xml' },
      multiple: true,
    })
    // On the media library tab, not the upload one. A select frame opens on
    // "Upload files", and its library collection does not fetch until the other
    // tab is shown -- so the person asking for files they already have is first
    // shown an empty uploader.
    frame.on('open', () => frame.content.mode('browse'))

    frame.on('select', async () => {
      const ids = frame
        .state()
        .get('selection')
        .map((item) => item.id)
        .filter((id) => !!id)
      if (!ids.length) {
        return
      }
      // With a set on screen that takes media, the files go into that set.
      if (intoSet && 'function' === typeof intoSet.provider.addMedia) {
        clear()
        setReport(null)
        const done = await act(() => intoSet.provider.addMedia({ ids, collection: intoSet }))
        setReport(Array.isArray(done?.rows) && done.rows.length ? done.rows : null)
        return
      }
      setBusy(true)
      clear()
      setReport(null)
      const rows = []
      let fresh = null
      // Sent in chunks the server accepts whole, for the same reason adds are
      // sequential: each answer carries the fresh page, and the last is true.
      for (let i = 0; i < ids.length; i += MEDIA_CHUNK) {
        try {
          fresh = await api.post('/library/media', { ids: ids.slice(i, i + MEDIA_CHUNK) })
          for (const row of fresh.report || []) {
            const ok = 'added' === row.state
            rows.push({ file: row.label || `#${row.id}`, ok, message: ok ? addedMessage(row) : row.message })
          }
        } catch (e) {
          setErr(e.message)
          break
        }
      }
      if (fresh) {
        take(fresh)
      }
      setReport(rows)
      setBusy(false)
    })
    frame.open()
  }

  function onPick(e) {
    addFiles(e.target.files)
    // So picking the same file twice in a row still fires a change event.
    e.target.value = ''
  }

  async function addPasted() {
    if (!markup.trim()) {
      return
    }
    if (intoSet) {
      clear()
      const done = await act(() =>
        intoSet.provider.add({ icons: [{ name: label.trim() || 'icon', markup }], to: intoSet.id, collection: intoSet })
      )
      if (done && (done.rows || []).every((r) => r.ok)) {
        setLabel('')
        setMarkup('')
        setPasting(false)
      } else if (Array.isArray(done?.rows)) {
        setReport(done.rows)
      }
      return
    }
    setBusy(true)
    clear()
    try {
      const fresh = await api.post('/library/add', { label, markup })
      take(fresh)
      setLabel('')
      setMarkup('')
      setPasting(false)
      setStatus(addedMessage(fresh?.added))
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }

  // One id or a selection; the route takes one at a time either way.
  async function remove(ids) {
    const list = Array.isArray(ids) ? ids : [ids]
    if (!list.length) {
      return
    }
    setBusy(true)
    clear()
    let fresh = null
    let gone = 0
    try {
      for (const id of list) {
        fresh = await api.post('/library/delete', { id })
        gone++
      }
      setStatus(
        sprintf(
          // translators: %d: number of icons removed.
          _n('%d icon removed.', '%d icons removed.', gone, 'easy-svg'),
          gone
        )
      )
    } catch (e) {
      setErr(e.message)
    } finally {
      if (fresh) {
        take(fresh)
      }
      setPicked(new Set())
      setConfirming(null)
      setBusy(false)
    }
  }

  async function rename(id, value) {
    const next = value.trim()
    if (!next) {
      return
    }
    const tile = shown.find((ic) => ic.id === id)
    if (tile?.canRename) {
      setEditing(null)
      if (next !== nameOf(tile)) {
        await act(() => tile.source.provider.relabel({ ref: refOf(tile), label: next, collection: tile.source }))
      }
      return
    }
    setBusy(true)
    clear()
    try {
      take(await api.post('/library/rename', { id, label: next }))
      setEditing(null)
      setStatus(__('Icon renamed.', 'easy-svg'))
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }

  async function copyCall(ic, form = 'php') {
    // A collection's icon is called by its full address; a library icon by
    // its slug, which is what the free collection answers to.
    const chosen = callForms(ic, ctx.iconBlock).find((f) => f.id === form && !f.disabled) || callForms(ic, false)[0]
    const text = chosen.text
    clear()
    try {
      await navigator.clipboard.writeText(text)
      setStatus(
        // translators: %s: the PHP call that renders the icon, e.g. easy_svg_icon( 'heart' ).
        sprintf(__('Copied %s to the clipboard.', 'easy-svg'), text)
      )
    } catch {
      // An admin served over plain HTTP has no clipboard API at all, so hand the
      // text over to be copied by hand rather than leave a dead button.
      setCopyHelp(text)
    }
  }



  const dashed = (over) => ({
    borderWidth: '1px',
    borderStyle: 'dashed',
    borderColor: over ? 'ui.primary' : 'ui.border',
    borderRadius: 'forge',
    bg: over ? 'ui.ghostHover' : 'transparent',
  })

  const pasteLabel = __('Paste markup', 'easy-svg')

  const fileInput = (
    <>
      <input
        ref={fileRef}
        type="file"
        accept={acceptFiles}
        multiple
        hidden
        tabIndex={-1}
        onChange={onPick}
      />
      <input
        ref={replaceRef}
        data-replace-input
        type="file"
        accept=".svg,image/svg+xml"
        hidden
        tabIndex={-1}
        onChange={async (e) => {
          const file = e.target.files?.[0]
          const ic = replacing
          e.target.value = ''
          setReplacing(null)
          if (file && ic) {
            const markup = await readText(file).catch(() => '')
            await act(() => ic.source.provider.replaceDrawing({ ref: refOf(ic), markup, collection: ic.source }))
          }
        }}
      />
    </>
  )
  const openChooser = () => fileRef.current && fileRef.current.click()

  // What the dialog is about to delete: a tile's one icon, or the selection.
  const confirmIds = confirming ? (confirming.bulk ? pickedLibrary : [confirming.id]) : []
  const confirmName = confirming && !confirming.bulk
    ? nameOf(all.find((ic) => ic.id === confirming.id) || { slug: '' })
    : ''
  const historyDialog = (
    <Dialog.Root
      open={!!past}
      onOpenChange={(e) => (e.open ? null : setPast(null))}
      placement="center"
      // Not portalled, for the same reason as the delete dialog below.
      modal
    >
      <Dialog.Backdrop />
      <Dialog.Positioner>
        <Dialog.Content data-icon-history borderRadius="forge" bg="ui.surface" maxW="560px">
          <Dialog.Header>
            <Dialog.Title fontSize="16px" fontWeight="700" color="ui.heading">
              {past
                ? sprintf(
                    // translators: %s: icon name.
                    __('Earlier versions of %s', 'easy-svg'),
                    nameOf(past.ic)
                  )
                : ''}
            </Dialog.Title>
          </Dialog.Header>
          <Dialog.Body>
            {!past || null === past.versions ? (
              <SkeletonRows rows={2} />
            ) : past.error ? (
              <Notice bad role="alert">{past.error}</Notice>
            ) : past.versions.length < 2 ? (
              <Text fontSize="sm" color="ui.muted">
                {past.note || __('Only the drawing in use has been kept so far.', 'easy-svg')}
              </Text>
            ) : (
              <Stack gap="2">
                {past.versions.map((v) => (
                  <HStack key={v.id} data-version={v.id} gap="3" borderWidth="1px" borderColor="ui.border" borderRadius="forge" p="2">
                    <Box
                      w="40px"
                      h="40px"
                      flexShrink="0"
                      css={{ '& svg': { width: '100%', height: '100%' } }}
                      dangerouslySetInnerHTML={{ __html: sanitizeSvg(v.markup) }}
                    />
                    <Text fontSize="sm" flex="1">
                      {v.since && v.since !== v.date
                        ? sprintf(
                            // translators: 1: date the drawing was first saved, 2: date it was last saved.
                            __('%1$s – %2$s', 'easy-svg'),
                            when(v.since),
                            when(v.date)
                          )
                        : when(v.date)}
                    </Text>
                    {v.current ? (
                      <Text fontSize="xs" color="ui.muted">{__('In use', 'easy-svg')}</Text>
                    ) : (
                      <Button size="sm" variant="ghost" disabled={busy} onClick={() => restoreVersion(v)}>
                        {__('Restore', 'easy-svg')}
                      </Button>
                    )}
                  </HStack>
                ))}
              </Stack>
            )}
          </Dialog.Body>
          <Dialog.Footer>
            <Dialog.ActionTrigger asChild>
              <Button variant="ghost">{__('Close', 'easy-svg')}</Button>
            </Dialog.ActionTrigger>
          </Dialog.Footer>
        </Dialog.Content>
      </Dialog.Positioner>
    </Dialog.Root>
  )
  const deleteDialog = (
    <Dialog.Root
      open={!!confirming}
      onOpenChange={(e) => (e.open ? null : setConfirming(null))}
      role="alertdialog"
      placement="center"
      // Not portalled: the panel's type and tokens are scoped to its subtree,
      // and a dialog in document.body would render in wp-admin's own styles.
      modal
    >
      <Dialog.Backdrop />
      <Dialog.Positioner>
        <Dialog.Content borderRadius="forge" bg="ui.surface">
          <Dialog.Header>
            <Dialog.Title fontSize="16px" fontWeight="700" color="ui.heading">
              {confirming && confirming.bulk
                ? sprintf(
                    // translators: %d: number of icons selected.
                    _n('Remove %d icon?', 'Remove %d icons?', confirmIds.length, 'easy-svg'),
                    confirmIds.length
                  )
                : sprintf(
                    // translators: %s: icon name.
                    __('Delete %s?', 'easy-svg'),
                    confirmName
                  )}
            </Dialog.Title>
          </Dialog.Header>
          <Dialog.Body>
            <Dialog.Description fontSize="14px" color="ui.muted">
              {__('This cannot be undone. Templates and blocks that render it will show nothing.', 'easy-svg')}
            </Dialog.Description>
          </Dialog.Body>
          <Dialog.Footer gap="3">
            <Dialog.ActionTrigger asChild>
              <Button variant="ghost" disabled={busy}>{__('Cancel', 'easy-svg')}</Button>
            </Dialog.ActionTrigger>
            <Button
              variant="primary"
              bg="ui.bad"
              _hover={{ bg: 'ui.bad', opacity: 0.9 }}
              _disabled={{ opacity: 0.55, bg: 'ui.bad', color: 'white', cursor: 'default' }}
              onClick={() => remove(confirmIds)}
              disabled={busy}
            >
              {busy ? __('Removing…', 'easy-svg') : __('Delete', 'easy-svg')}
            </Button>
          </Dialog.Footer>
        </Dialog.Content>
      </Dialog.Positioner>
    </Dialog.Root>
  )

  /*
   * Full width above the grid, because the primary action of the screen should
   * not be the narrowest thing on it. Chakra's FileUpload owns the hidden
   * input, the drag state and the keyboard path; the verdict on a file stays
   * the server's, nothing is pre-judged beyond the accept hint.
   */
  const uploadSurface = (first) => (
    <FileUpload.Root
      key={pickRound}
      accept={acceptFiles}
      maxFiles={MAX_FILES}
      multiple
      disabled={busy}
      alignItems="stretch"
      onFileAccept={(details) => addFiles(details.files)}
    >
      <FileUpload.HiddenInput />
      <FileUpload.Dropzone
        borderWidth="1px"
        borderStyle="dashed"
        borderColor="ui.border"
        borderRadius="forge"
        bg="transparent"
        px="4"
        py="5"
        minH="0"
        gap="2"
        _hover={{ borderColor: 'ui.primary', bg: 'ui.ghostHover' }}
        _dragging={{ borderColor: 'ui.primary', bg: 'ui.ghostHover' }}
      >
        <Box color="ui.muted"><UploadGlyph /></Box>
        <FileUpload.DropzoneContent gap="2">
          <Text fontSize="14px" fontWeight="700" color="ui.heading">
            {first ? __('Drop your first SVG here', 'easy-svg') : __('Drop SVG files here', 'easy-svg')}
          </Text>
          {first ? (
            <Text textStyle="caption" maxW="46ch">
              {__('Every file is sanitised and hardened on the way in. Same bar as a media upload.', 'easy-svg')}
            </Text>
          ) : null}
          <HStack gap="2" flexWrap="wrap" justify="center">
            <FileUpload.Trigger asChild>
              <Button variant="primary" size="sm" disabled={busy}>
                {__('Choose an SVG', 'easy-svg')}
              </Button>
            </FileUpload.Trigger>
            {/* Not inside the dropzone's click target: the media frame is a
                different door, and a click on it must not open the chooser. */}
            <Button
              variant="ghost"
              size="sm"
              onClick={(e) => {
                e.stopPropagation()
                addFromMedia()
              }}
              disabled={busy}
            >
              {__('Choose from the media library', 'easy-svg')}
            </Button>
            <Button
              variant="ghost"
              size="sm"
              onClick={(e) => {
                e.stopPropagation()
                setPasting((v) => !v)
              }}
              aria-expanded={pasting}
            >
              {pasteLabel}
            </Button>
          </HStack>
          {first ? (
            <Text textStyle="caption">{__('There is no limit on how many icons you keep.', 'easy-svg')}</Text>
          ) : null}
        </FileUpload.DropzoneContent>
      </FileUpload.Dropzone>
    </FileUpload.Root>
  )
  const addSurface = uploadSurface(false)
  const pasteForm = (
    <PasteForm {...{ label, setLabel, markup, setMarkup, addPasted, busy, setPasting }} />
  )

  // Said plainly when the response could not carry everything. The grid used to
  // show the first 500 and let the reader believe that was all of them.
  const truncated = total > all.length
  const messages = (
    <>
      {err ? <Notice bad role="alert">{err}</Notice> : null}
      {copyHelp ? (
        <Notice role="status" status="warning" title={__('The clipboard is not available here', 'easy-svg')}>
          {/*
            Chakra's Clipboard: it owns the copy attempt and the copied/not
            state, and its input keeps the call selectable where even that
            fails -- which is the whole point on a site served over plain HTTP.
          */}
          <Clipboard.Root value={copyHelp} timeout={1500} mt="2" maxW="420px">
            <InputGroup
              endElement={
                <Clipboard.Trigger asChild>
                  <Button variant="ghost" size="sm" me="-2" minH="0" px="2">
                    <Clipboard.Indicator copied={__('Copied', 'easy-svg')}>
                      {__('Copy', 'easy-svg')}
                    </Clipboard.Indicator>
                  </Button>
                </Clipboard.Trigger>
              }
            >
              <Clipboard.Input asChild>
                <TextInput
                  readOnly
                  fontSize="13px"
                  aria-label={__('The call to copy into your template', 'easy-svg')}
                />
              </Clipboard.Input>
            </InputGroup>
          </Clipboard.Root>
          <Button size="sm" variant="ghost" mt="2" onClick={() => setCopyHelp('')}>
            {__('Dismiss', 'easy-svg')}
          </Button>
        </Notice>
      ) : null}
      {status ? (
        <Text role="status" aria-live="polite" color="ui.goodInk" fontSize="sm">{status}</Text>
      ) : null}
      {truncated ? (
        <Notice>
          {sprintf(
            // translators: 1: how many icons are stored, 2: how many this screen lists.
            __('This site has %1$d icons and this screen lists %2$d of them. The rest are stored and still work in templates and the Icon block.', 'easy-svg'),
            total,
            all.length
          )}
        </Notice>
      ) : null}
    </>
  )
  const reportBox = report ? (
    <ReportList
      summary={reportSummary(report)}
      rows={report}
      onDismiss={() => setReport(null)}
      dismissLabel={__('Dismiss this report', 'easy-svg')}
    />
  ) : null

  if (err && icons === null) {
    return <Notice bad role="alert">{err}</Notice>
  }

  if (icons === null) {
    return (
      <Stack gap="5" maxW="900px">
        <Section title={__('Your icons', 'easy-svg')}>
          <SkeletonRows rows={3} />
        </Section>
      </Stack>
    )
  }

  // Nothing stored yet: the empty state IS the upload surface. Only without a
  // search term, though -- a search answers empty the same way, and this screen
  // has no search field, so it would tell somebody with 400 icons they have
  // none and remove the box they would clear. Nor while a provider has a set to
  // begin or a hint to give: this screen has no sidebar to put them in.
  if (all.length === 0 && !query.trim() && !collections.length && !creator && !hints.length) {
    return (
      <Stack gap="5" maxW="900px">
        {messages}
        {reportBox}
        {/* The same dropzone the populated screen uses, so there is one drag
            target, one keyboard path and one set of buttons to maintain. */}
        <Section title={__('Your icons', 'easy-svg')}>
          {uploadSurface(true)}
          {pasting ? <Box pt="4">{pasteForm}</Box> : null}
        </Section>
        {deleteDialog}
      </Stack>
    )
  }

  return (
    <Stack gap="5" maxW="900px">
      <Section
        title={__('Your icons', 'easy-svg')}
        description={sprintf(
          // translators: %d: number of icons in the library.
          _n('%d icon in the library.', '%d icons in the library.', libraryTotal, 'easy-svg'),
          libraryTotal
        )}
      >
        <Stack gap="4">
          <HStack gap="3" flexWrap="wrap" align="center">
            <SearchField
              id="esw-icon-search"
              label={__('Search icons by name or slug', 'easy-svg')}
              placeholder={__('Search icons…', 'easy-svg')}
              value={query}
              onChange={(e) => setQuery(e.target.value)}
            />
            <Button
              size="sm"
              variant="ghost"
              onClick={() => (selecting ? stopSelecting() : setSelecting(true))}
              aria-pressed={selecting}
            >
              {selecting ? __('Done selecting', 'easy-svg') : __('Select several', 'easy-svg')}
            </Button>
            {/*
              One click for the whole list, which is what somebody clearing a
              library after a bad import needs. It takes what is SHOWN: under a
              search that is the matches, so "select all" never reaches past
              what the person can see.
            */}
            {selecting ? (
              <Button size="sm" variant="ghost" onClick={toggleAll} disabled={busy || !own.length}>
                {allPicked ? __('Clear selection', 'easy-svg') : __('Select all', 'easy-svg')}
              </Button>
            ) : null}
          </HStack>

          {addSurface}

          {/*
            The match count, announced: typing tells a sighted person nothing
            either until they look at the grid. role=status implies a polite live
            region, so it is spoken once the keystrokes stop.
          */}
          {query.trim() ? (
            <Text role="status" aria-live="polite" textStyle="caption">
              {searching
                ? __('Searching…', 'easy-svg')
                : sprintf(
                    // translators: %d: number of icons the search found.
                    _n('%d icon found.', '%d icons found.', shown.length, 'easy-svg'),
                    shown.length
                  )}
            </Text>
          ) : null}

          {pasting ? pasteForm : null}
          {reportBox}
          {messages}

          {/*
            Chakra's ActionBar: a selection bar belongs over the content it
            acts on, not in the flow pushing it down. Not portalled, so it
            keeps the panel's type and tokens.
          */}
          <ActionBar.Root open={selecting && picked.size > 0} closeOnInteractOutside={false}>
            {/* Above the grid and the collections beside it: without a layer of
                its own the sidebar took the clicks meant for the bar. */}
            <ActionBar.Positioner zIndex="docked">
              <ActionBar.Content
                bg="ui.heading"
                color="ui.surface"
                borderRadius="forge"
                boxShadow="lg"
                px="3"
                py="2.5"
                gap="3"
              >
                {/* A span: Chakra renders this as a button, and a focusable
                    button with nothing to do is a stop on the way to Remove. */}
                <ActionBar.SelectionTrigger asChild>
                  <Text as="span" fontSize="14px" fontWeight="700" color="ui.surface">
                    {sprintf(
                      // translators: %d: number of icons selected.
                      _n('%d icon selected', '%d icons selected', picked.size, 'easy-svg'),
                      picked.size
                    )}
                  </Text>
                </ActionBar.SelectionTrigger>
                <ActionBar.Separator bg="whiteAlpha.400" />
                {targets.length ? (
                  <Menu.Root>
                    {/* The Trigger itself, not asChild: Ark anchors the menu to
                        its ref, which this panel's Button does not pass on. */}
                    <Menu.Trigger
                      disabled={busy}
                      display="inline-flex"
                      alignItems="center"
                      gap="1.5"
                      h="8"
                      px="3"
                      fontSize="sm"
                      fontWeight="600"
                      borderRadius="forge"
                      color="ui.surface"
                      cursor="pointer"
                      _hover={{ bg: 'whiteAlpha.200' }}
                      _focusVisible={{ outline: '2px solid', outlineColor: 'ui.surface', outlineOffset: '2px' }}
                    >
                      <MoveGlyph />
                      {__('Move to…', 'easy-svg')}
                    </Menu.Trigger>
                    <Menu.Positioner>
                      <Menu.Content>
                        {targets.map((c) => (
                          <Menu.Item key={c.id} value={c.id} onSelect={() => moveTo(c, pickedTiles.map(refOf))}>
                            {c.label || c.id}
                          </Menu.Item>
                        ))}
                      </Menu.Content>
                    </Menu.Positioner>
                  </Menu.Root>
                ) : null}
                {recolourable.length ? (
                  <Button
                    size="sm"
                    variant="ghost"
                    color="ui.surface"
                    _hover={{ color: 'ui.surface', bg: 'whiteAlpha.200' }}
                    onClick={recolourPicked}
                    disabled={busy}
                  >
                    {recolourable.length === pickedTiles.length
                      ? __('Recolour', 'easy-svg')
                      : sprintf(
                          // translators: %d: number of picked icons that can be recoloured.
                          _n('Recolour %d', 'Recolour %d', recolourable.length, 'easy-svg'),
                          recolourable.length
                        )}
                  </Button>
                ) : null}
                {exporter ? (
                  <Button
                    size="sm"
                    variant="ghost"
                    color="ui.surface"
                    _hover={{ color: 'ui.surface', bg: 'whiteAlpha.200' }}
                    onClick={exportPicked}
                    disabled={busy || exportTooMany}
                    title={
                      exportTooMany
                        ? sprintf(
                            // translators: %d: most icons one export takes.
                            __('One export takes at most %d icons.', 'easy-svg'),
                            exporter.exportMax
                          )
                        : undefined
                    }
                  >
                    <DownloadGlyph />
                    {__('Export', 'easy-svg')}
                  </Button>
                ) : null}
                {pickedLibrary.length ? (
                  <Button
                    size="sm"
                    variant="primary"
                    bg="ui.bad"
                    _hover={{ bg: 'ui.bad', opacity: 0.9 }}
                    onClick={() => setConfirming({ bulk: true })}
                    disabled={busy}
                  >
                    <TrashGlyph />
                    {pickedLibrary.length === pickedTiles.length
                      ? __('Remove', 'easy-svg')
                      : sprintf(
                          // translators: %d: number of picked icons that are the library's own.
                          _n('Remove %d from the library', 'Remove %d from the library', pickedLibrary.length, 'easy-svg'),
                          pickedLibrary.length
                        )}
                  </Button>
                ) : null}
                <Button
                  size="sm"
                  variant="ghost"
                  color="ui.surface"
                  borderColor="whiteAlpha.500"
                  _hover={{ color: 'ui.surface', borderColor: 'ui.surface', bg: 'whiteAlpha.200' }}
                  onClick={stopSelecting}
                  disabled={busy}
                >
                  {__('Done selecting', 'easy-svg')}
                </Button>
              </ActionBar.Content>
            </ActionBar.Positioner>
          </ActionBar.Root>

          {/*
            The grid scrolls inside the card rather than growing it, so the
            toolbar above stays put with four hundred icons in the library. maxH
            is a little over four rows: enough that there is visibly more below.
          */}
          {moved ? (
            <HStack data-move-result role="status" gap="3" wrap="wrap">
              <Text fontSize="sm">{moved.message}</Text>
              {moved.undo ? (
                <Button size="sm" variant="ghost" onClick={undoMove} disabled={busy}>
                  {__('Undo', 'easy-svg')}
                </Button>
              ) : null}
            </HStack>
          ) : null}
          <Stack direction={{ base: 'column', md: 'row' }} gap="4" align="stretch">
          {collections.length || creator || hints.length ? (
            <Stack data-collections as="nav" aria-label={__('Collections', 'easy-svg')} gap="0.5" minW={{ md: '180px' }} pt="4">
              {[
                { id: 'all', label: __('All icons', 'easy-svg'), count: libraryTotal + collections.reduce((n, c) => n + c.icons.length, 0) },
                { id: 'library', label: __('Icon library', 'easy-svg'), count: libraryTotal },
                ...collections.map((c) => ({ id: c.id, label: c.label || c.id, count: c.icons.length, readonly: !!c.readonly, collection: c })),
              ].map((entry) => {
                const c = entry.collection
                if (c && naming?.id === c.id) {
                  return (
                    <Box key={entry.id}>
                      {nameField(
                        // translators: %s: current name of the set.
                        sprintf(__('New name for %s', 'easy-svg'), entry.label)
                      )}
                    </Box>
                  )
                }
                const canRename = c && !c.readonly && 'function' === typeof c.provider?.rename
                const canTrash = c && !c.readonly && 'function' === typeof c.provider?.trash
                return (
                  <HStack key={entry.id} gap="0.5">
                    <Button
                      {...(c ? dropOn(c) : {})}
                      flex="1"
                      minW="0"
                      size="sm"
                      variant={view === entry.id ? 'subtle' : 'ghost'}
                      justifyContent="space-between"
                      aria-pressed={view === entry.id}
                      onClick={() => choose(entry.id)}
                      title={entry.readonly ? __('Read only: these icons come from files', 'easy-svg') : undefined}
                    >
                      <Text as="span" truncate>{entry.label}</Text>
                      <Text as="span" fontSize="xs" color="ui.muted">{entry.count}</Text>
                    </Button>
                    {canRename || canTrash ? (
                      <Menu.Root>
                        <Menu.Trigger
                          disabled={busy}
                          aria-label={
                            // translators: %s: name of the set.
                            sprintf(__('Actions for %s', 'easy-svg'), entry.label)
                          }
                          display="inline-flex"
                          alignItems="center"
                          justifyContent="center"
                          minW="8"
                          h="8"
                          borderRadius="forge"
                          color="ui.muted"
                          cursor="pointer"
                          _hover={{ color: 'ui.primary', bg: 'ui.ghostHover' }}
                          _focusVisible={{ outline: '2px solid', outlineColor: 'ui.primary', outlineOffset: '2px' }}
                        >
                          <MoreGlyph />
                        </Menu.Trigger>
                        <Portal>
                          <Menu.Positioner>
                            <Menu.Content>
                              {canRename ? (
                                <Menu.Item value="rename" onSelect={() => setNaming({ id: c.id, value: entry.label })}>
                                  <RenameGlyph />
                                  {__('Rename', 'easy-svg')}
                                </Menu.Item>
                              ) : null}
                              {canTrash ? (
                                <Menu.Item value="trash" onSelect={() => trashCollection(c)}>
                                  <TrashGlyph />
                                  {__('Move to trash', 'easy-svg')}
                                </Menu.Item>
                              ) : null}
                            </Menu.Content>
                          </Menu.Positioner>
                        </Portal>
                      </Menu.Root>
                    ) : null}
                  </HStack>
                )
              })}
              {hints.map((p) => (
                <Stack key={p.id} data-collections-hint gap="2" mt="2" p="3" borderWidth="1px" borderStyle="dashed" borderColor="ui.border" borderRadius="forge">
                  <Text fontSize="sm" color="ui.muted">{p.hint.text}</Text>
                  {p.hint.action && 'function' === typeof p.hint.action.run ? (
                    <Button size="sm" variant="outline" disabled={busy} onClick={() => runHint(p.hint)}>
                      {p.hint.action.label}
                    </Button>
                  ) : null}
                </Stack>
              ))}
              {creator ? (
                'new' === naming?.id ? (
                  <Box pt="1">{nameField(__('Name of the new set', 'easy-svg'))}</Box>
                ) : (
                  <Button size="sm" variant="ghost" justifyContent="flex-start" disabled={busy} onClick={() => setNaming({ id: 'new', value: '' })}>
                    <AddGlyph />
                    {__('New set', 'easy-svg')}
                  </Button>
                )
              ) : null}
            </Stack>
          ) : null}
          <Box
            flex="1"
            minW="0"
            maxH="420px"
            overflowY="auto"
            px="1"
            pt="4"
            pb="1"
            borderTopWidth="1px"
            borderColor="ui.border"
            borderRadius="forge"
            outline={grid.isOver ? '2px dashed' : undefined}
            outlineColor="ui.primary"
            outlineOffset="-2px"
            {...grid.props}
          >
            {/*
              The tile's own width decides how many fit, not the viewport's:
              this grid sits inside two sidebars, so five columns at a 800px
              window left each tile 75px wide and broke "Rocket" across two
              lines. 110px is the floor a tile needs for its three actions at a
              real touch size. auto-FILL, not auto-fit: with three icons in the
              library auto-fit would stretch them across the row.
            */}
            <SimpleGrid templateColumns="repeat(auto-fill, minmax(110px, 1fr))" gap="3">
              {shown.map((ic) => (
                <IconTile
                  key={ic.id}
                  ic={ic}
                  busy={busy}
                  selecting={selecting}
                  picked={picked.has(ic.id)}
                  onPick={(on) => togglePicked(ic.id, on)}
                  editing={editing && editing.id === ic.id ? editing.value : null}
                  onEdit={() => setEditing({ id: ic.id, value: nameOf(ic) })}
                  onEditChange={(value) => setEditing({ id: ic.id, value })}
                  onEditCancel={() => setEditing(null)}
                  onEditSave={(value) => rename(ic.id, value)}
                  onCopy={(form) => copyCall(ic, form)}
                  forms={callForms(ic, ctx.iconBlock)}
                  onHistory={historyOf(ic) ? () => openHistory(ic) : null}
                  onRecolour={ic.canRecolour ? () => act(() => ic.source.provider.recolour({ refs: [refOf(ic)], collection: ic.source })) : null}
                  onReplace={
                    ic.canReplace
                      ? () => {
                          setReplacing(ic)
                          replaceRef.current?.click()
                        }
                      : null
                  }
                  onRemove={() =>
                    ic.canRemove
                      ? act(() => ic.source.provider.remove({ refs: [refOf(ic)], collection: ic.source }))
                      : setConfirming({ id: ic.id })
                  }
                  targets={targets.filter((c) => c.id !== ic.collection)}
                  onMoveTo={(c) => moveTo(c, [refOf(ic)])}
                />
              ))}

              {/*
                The chooser is the LAST TILE, not a zone above the grid: the place
                you are already looking at is the place you add to. A real
                <button>, so the keyboard reaches it and it is announced as one.
              */}
              {'all' === view || 'library' === view || intoSet ? (
              <Box
                as="button"
                type="button"
                onClick={openChooser}
                disabled={busy}
                {...dashed(false)}
                minH="96px"
                px="2"
                py="3"
                color="ui.muted"
                cursor="pointer"
                _hover={{ borderColor: 'ui.primary', color: 'ui.primary', bg: 'ui.ghostHover' }}
                _disabled={{ opacity: 0.55, cursor: 'default' }}
              >
                <Stack gap="1.5" align="center" justify="center" h="full">
                  <AddGlyph />
                  <Text fontSize="11px" lineHeight="1.3" textAlign="center">
                    {__('Drop an SVG or choose one', 'easy-svg')}
                  </Text>
                </Stack>
              </Box>
              ) : null}
            </SimpleGrid>

            {shown.length === 0 && query.trim() ? (
              <Box pt="4" pb="2">
                <Text textStyle="description" textAlign="center">
                  {__('No icon matches that search.', 'easy-svg')}
                </Text>
              </Box>
            ) : null}
          </Box>
          </Stack>
          {fileInput}
        </Stack>
      </Section>
      {deleteDialog}
      {historyDialog}
    </Stack>
  )
}

/*
 * One icon.
 *
 * The actions are rendered always and hidden with opacity, never with `display`
 * or `visibility`: a button that is invisible but still in the tab order is
 * exactly what is wanted, because :focus-within brings it into view the moment
 * the keyboard reaches it. Where there is no hover at all -- a touch screen --
 * they simply stay visible, because a control you can only reveal by hovering is
 * a control you do not have.
 */
function IconTile({
  ic,
  busy,
  selecting,
  picked,
  onPick,
  editing,
  onEdit,
  onEditChange,
  onEditCancel,
  onEditSave,
  onCopy,
  onRemove,
  onHistory = null,
  onRecolour = null,
  onReplace = null,
  forms = [],
  targets = [],
  onMoveTo,
}) {
  const name = nameOf(ic)
  const isEditing = null !== editing
  return (
    <Box
      data-icon-key={ic.key || ic.ref || `easy-svg/${ic.slug}`}
      draggable={!isEditing && !busy}
      onDragStart={(e) => {
        e.dataTransfer.setData(MOVE_TYPE, JSON.stringify({ refs: [refOf(ic)] }))
        e.dataTransfer.effectAllowed = 'copyMove'
      }}
      bg="ui.surface"
      borderWidth="1px"
      borderColor={picked ? 'ui.primary' : 'ui.border'}
      borderRadius="forge"
      px="2"
      py="2.5"
      minH="96px"
      gridColumn={isEditing ? 'span 2' : undefined}
      css={{
        // Dimmed, never hidden: fully invisible actions were not found at all
        // until a click landed on one by accident.
        '& [data-tile-actions]': { opacity: 0.5, transition: 'opacity .12s ease' },
        '&:hover [data-tile-actions]': { opacity: 1 },
        '&:focus-within [data-tile-actions]': { opacity: 1 },
        '@media (hover: none)': { '& [data-tile-actions]': { opacity: 1 } },
        '@media (prefers-reduced-motion: reduce)': { '& [data-tile-actions]': { transition: 'none' } },
      }}
    >
      <Stack gap="1.5" align="center">
        {selecting ? (
          <HStack w="full" justify="flex-start">
            <Checkbox.Root
              size="sm"
              colorPalette="brand"
              checked={picked}
              onCheckedChange={(e) => onPick(!!e.checked)}
            >
              <Checkbox.HiddenInput
                aria-label={
                  // translators: %s: icon name.
                  sprintf(__('Select %s', 'easy-svg'), name)
                }
              />
              <Checkbox.Control />
            </Checkbox.Root>
          </HStack>
        ) : null}

        <Box
          boxSize="28px"
          aria-hidden="true"
          css={{ '& svg': { width: '100%', height: '100%' } }}
          dangerouslySetInnerHTML={{ __html: sanitizeSvg(ic.content) }}
        />

        {isEditing ? (
          <Stack gap="2" w="full" pt="1">
            <TextInput
              size="sm"
              autoFocus
              value={editing}
              aria-label={
                // translators: %s: the icon's current name.
                sprintf(__('New name for %s', 'easy-svg'), name)
              }
              onChange={(e) => onEditChange(e.target.value)}
              onKeyDown={(e) => {
                if ('Enter' === e.key) {
                  e.preventDefault()
                  onEditSave(editing)
                }
                if ('Escape' === e.key) {
                  e.preventDefault()
                  onEditCancel()
                }
              }}
            />
            {/* Stacked and full width: side by side the two of them are wider
                than the tile and get clipped at its edge. */}
            <Stack gap="2" w="full">
              <Button
                size="sm"
                variant="primary"
                w="full"
                onClick={() => onEditSave(editing)}
                disabled={busy || !editing.trim()}
              >
                {__('Save', 'easy-svg')}
              </Button>
              <Button size="sm" variant="ghost" w="full" onClick={onEditCancel} disabled={busy}>
                {__('Cancel', 'easy-svg')}
              </Button>
            </Stack>
          </Stack>
        ) : (
          <>
            <Text fontSize="xs" color="ui.muted" textAlign="center" lineClamp={2} w="full" title={name}>
              {name}
            </Text>
            {/* Two icons may share a name; never a slug, and the slug is what
                the copied call and a block use. Shown when it says something
                the name does not. */}
            {ic.slug && ic.slug !== name ? (
              <Text
                data-slug
                fontFamily="mono"
                fontSize="2xs"
                color="ui.muted"
                textAlign="center"
                truncate
                w="full"
                title={ic.slug}
              >
                {ic.slug}
              </Text>
            ) : null}
            {/* Wraps rather than clips: a set's icon carries more actions than
                a 110px tile has room for in one row. */}
            <HStack data-tile-actions gap="0.5" justify="center" pt="0.5" flexWrap="wrap">
              <Menu.Root>
                {/* The Trigger is the button itself, for the same reason as Move. */}
                <Menu.Trigger
                  disabled={busy}
                  aria-label={
                    // translators: %s: icon name.
                    sprintf(__('Copy the call for %s', 'easy-svg'), name)
                  }
                  title={sprintf(__('Copy the call for %s', 'easy-svg'), name)}
                  display="inline-flex"
                  alignItems="center"
                  justifyContent="center"
                  borderRadius="forge"
                  color="ui.muted"
                  cursor="pointer"
                  _hover={{ color: 'ui.primary', bg: 'ui.ghostHover' }}
                  _focusVisible={{ outline: '2px solid', outlineColor: 'ui.primary', outlineOffset: '2px' }}
                  {...TILE_ACTION_SIZE}
                >
                  <CopyGlyph />
                </Menu.Trigger>
                <Portal>
                  <Menu.Positioner>
                    <Menu.Content>
                      {forms.map((f) => (
                        <Menu.Item key={f.id} value={f.id} disabled={!!f.disabled} onSelect={() => onCopy(f.id)}>
                          <Stack gap="0">
                            <Text as="span">{f.label}</Text>
                            <Text as="span" fontSize="xs" color="ui.muted" fontFamily="mono">{f.text}</Text>
                          </Stack>
                        </Menu.Item>
                      ))}
                    </Menu.Content>
                  </Menu.Positioner>
                </Portal>
              </Menu.Root>
              {onHistory || onRecolour || onReplace || targets.length ? (
                <Menu.Root>
                  <Menu.Trigger
                    disabled={busy}
                    aria-label={
                      // translators: %s: icon name.
                      sprintf(__('More for %s', 'easy-svg'), name)
                    }
                    title={sprintf(__('More for %s', 'easy-svg'), name)}
                    display="inline-flex"
                    alignItems="center"
                    justifyContent="center"
                    borderRadius="forge"
                    color="ui.muted"
                    cursor="pointer"
                    _hover={{ color: 'ui.primary', bg: 'ui.ghostHover' }}
                    _focusVisible={{ outline: '2px solid', outlineColor: 'ui.primary', outlineOffset: '2px' }}
                    {...TILE_ACTION_SIZE}
                  >
                    <MoreGlyph />
                  </Menu.Trigger>
                  <Portal>
                    <Menu.Positioner>
                      <Menu.Content>
                        {onHistory ? (
                          <Menu.Item value="history" onSelect={onHistory}>
                            <HistoryGlyph />
                            {__('Earlier versions…', 'easy-svg')}
                          </Menu.Item>
                        ) : null}
                        {onRecolour ? (
                          <Menu.Item value="recolour" onSelect={onRecolour}>
                            {__('Recolour: take the text colour', 'easy-svg')}
                          </Menu.Item>
                        ) : null}
                        {onReplace ? (
                          <Menu.Item value="replace" onSelect={onReplace}>
                            {__('Replace the drawing…', 'easy-svg')}
                          </Menu.Item>
                        ) : null}
                        {/* Moving by menu is the way that needs no mouse. */}
                        {targets.length ? (
                          <Menu.ItemGroup>
                            <Menu.ItemGroupLabel fontSize="xs" color="ui.muted">
                              {__('Move to', 'easy-svg')}
                            </Menu.ItemGroupLabel>
                            {targets.map((c) => (
                              <Menu.Item key={c.id} value={`move:${c.id}`} onSelect={() => onMoveTo(c)}>
                                <MoveGlyph />
                                {c.label || c.id}
                              </Menu.Item>
                            ))}
                          </Menu.ItemGroup>
                        ) : null}
                      </Menu.Content>
                    </Menu.Positioner>
                  </Portal>
                </Menu.Root>
              ) : null}


              {/* A read-only collection's icon is renamed or deleted where it lives. */}
              {ic.readonly && !ic.canRename && !ic.canRemove ? null : (
                <>
                  {ic.readonly && !ic.canRename ? null : (
                  <IconAction
                    onClick={onEdit}
                    disabled={busy}
                    {...TILE_ACTION_SIZE}
                    label={
                      // translators: %s: icon name.
                      sprintf(__('Rename %s', 'easy-svg'), name)
                    }
                  >
                    <RenameGlyph />
                  </IconAction>
                  )}
                  {ic.readonly && !ic.canRemove ? null : (
                  <IconAction
                    onClick={onRemove}
                    disabled={busy}
                    danger
                    {...TILE_ACTION_SIZE}
                    label={
                      // translators: %s: icon name.
                      sprintf(__('Delete %s', 'easy-svg'), name)
                    }
                  >
                    <TrashGlyph />
                  </IconAction>
                  )}
                </>
              )}
            </HStack>
          </>
        )}
      </Stack>
    </Box>
  )
}

// Four of these sit side by side in one tile, so they are narrower than the
// 40px raster: 24px, the WCAG 2.5.8 minimum, is what fits four in a 110px
// tile -- except on a phone, where 44px is the touch target.
const TILE_ACTION_SIZE = {
  px: { base: '2', md: '1' },
  minW: { base: '44px', md: '24px' },
  minH: { base: '44px', md: '24px' },
}

// Pasting is still possible, just no longer the default path: it sits behind the
// secondary button, because dropping a file is what people actually do.
function PasteForm({ label, setLabel, markup, setMarkup, addPasted, busy, setPasting }) {
  return (
    <Box borderWidth="1px" borderColor="ui.border" borderRadius="forge" bg="ui.sunk" px="4" pt="1" pb="4">
      <FieldRow
        label={__('Name', 'easy-svg')}
        description={__('Optional. The slug is derived from it.', 'easy-svg')}
        htmlFor="esw-icon-name"
        control={
          <Box w="320px" maxW="full">
            <TextInput
              id="esw-icon-name"
              placeholder={__('e.g. arrow-right', 'easy-svg')}
              value={label}
              onChange={(e) => setLabel(e.target.value)}
            />
          </Box>
        }
      />
      <FieldRow
        layout="stack"
        label={__('SVG markup', 'easy-svg')}
        description={__('Paste the <svg>…</svg>. It is sanitised and hardened before it is stored, exactly like a dropped file.', 'easy-svg')}
        htmlFor="esw-icon-markup"
        control={
          <Textarea
            id="esw-icon-markup"
            placeholder="<svg>…</svg>"
            value={markup}
            onChange={(e) => setMarkup(e.target.value)}
            w="full"
            minH="160px"
            resize="vertical"
            bg="ui.surface"
            borderColor="ui.border"
            borderRadius="forge"
            fontFamily="mono"
            fontSize="sm"
          />
        }
      />
      {markup.trim() ? (
        <Box pt="4">
          <Text textStyle="caption" mb="2">{__('Preview', 'easy-svg')}</Text>
          <Box
            aria-hidden="true"
            borderWidth="1px"
            borderColor="ui.border"
            borderRadius="forge"
            bg="ui.surface"
            p="3"
            boxSize="72px"
            overflow="hidden"
            css={{ '& svg': { width: '100%', height: '100%' } }}
            dangerouslySetInnerHTML={{ __html: sanitizeSvg(markup) }}
          />
        </Box>
      ) : null}
      <HStack pt="4" gap="3">
        <Button variant="primary" onClick={addPasted} disabled={busy || !markup.trim()}>
          {busy ? __('Adding…', 'easy-svg') : __('Add', 'easy-svg')}
        </Button>
        <Button variant="ghost" onClick={() => setPasting(false)} disabled={busy}>
          {__('Cancel', 'easy-svg')}
        </Button>
      </HStack>
    </Box>
  )
}
