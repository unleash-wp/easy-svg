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

const nameOf = (ic) => ic.label || ic.slug

/*
 * The chooser asks for .svg; a DROP brings whatever was dropped. Nothing is
 * pre-judged in the browser on purpose: the server owns the verdict and owns the
 * sentences for it (easy_svg_panel_add_message), so a file that is not an SVG is
 * refused by the thing that knows why rather than guessed at here. The size cap
 * it enforces is the same one the Settings tab shows.
 */
const FILE_ACCEPT = '.svg,image/svg+xml'

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
  const [err, setErr] = useState('')
  const [status, setStatus] = useState('')
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

  // One place unwraps a library response, so a route that still answers the old
  // bare array keeps working and the total only has to be read once.
  const take = (res) => {
    const list = listOf(res)
    setIcons(list)
    setTotal(totalOf(res, list.length))
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
          take(res)
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
  const shown = all
  // Every shown id, not a size comparison: a count can read "all" while the
  // picked ids belong to a list that is no longer on screen.
  const allPicked = shown.length > 0 && shown.every((ic) => picked.has(ic.id))

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
    setPicked((prev) => (shown.every((ic) => prev.has(ic.id)) ? new Set() : new Set(shown.map((ic) => ic.id))))
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
    setBusy(true)
    clear()
    setReport(null)
    const rows = []
    let fresh = null
    for (const file of files) {
      try {
        const markupOfFile = await readText(file)
        fresh = await api.post('/library/add', { label: file.name.replace(/\.svg$/i, ''), markup: markupOfFile })
        rows.push({ file: file.name, ok: true, message: __('Icon added.', 'easy-svg') })
      } catch (e) {
        rows.push({ file: file.name, ok: false, message: e.message })
      }
    }
    if (fresh) {
      take(fresh)
    }
    setReport(rows)
    setBusy(false)
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
            rows.push({ file: row.label || `#${row.id}`, ok: 'added' === row.state, message: row.message })
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
    setBusy(true)
    clear()
    try {
      take(await api.post('/library/add', { label, markup }))
      setLabel('')
      setMarkup('')
      setPasting(false)
      setStatus(__('Icon added.', 'easy-svg'))
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

  async function copyCall(ic) {
    const text = iconCall(ic.slug)
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
    <input ref={fileRef} type="file" accept={FILE_ACCEPT} multiple hidden tabIndex={-1} onChange={onPick} />
  )
  const openChooser = () => fileRef.current && fileRef.current.click()

  // What the dialog is about to delete: a tile's one icon, or the selection.
  const confirmIds = confirming ? (confirming.bulk ? Array.from(picked) : [confirming.id]) : []
  const confirmName = confirming && !confirming.bulk
    ? nameOf(all.find((ic) => ic.id === confirming.id) || { slug: '' })
    : ''
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
      accept={FILE_ACCEPT}
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
  // none and remove the box they would clear.
  if (all.length === 0 && !query.trim()) {
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
          _n('%d icon in the library.', '%d icons in the library.', all.length, 'easy-svg'),
          all.length
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
              <Button size="sm" variant="ghost" onClick={toggleAll} disabled={busy || !shown.length}>
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
            <ActionBar.Positioner>
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
                <Button
                  size="sm"
                  variant="primary"
                  bg="ui.bad"
                  _hover={{ bg: 'ui.bad', opacity: 0.9 }}
                  onClick={() => setConfirming({ bulk: true })}
                  disabled={busy}
                >
                  <TrashGlyph />
                  {__('Remove', 'easy-svg')}
                </Button>
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
          <Box
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
              Two columns on a phone, not three: a tile has to be wide enough
              for its three actions at a real touch size, and at three columns
              it is about 100px, which forces them down to 28px side by side.
            */}
            <SimpleGrid columns={{ base: 2, md: 5, lg: 7 }} gap="3">
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
                  onCopy={() => copyCall(ic)}
                  onRemove={() => setConfirming({ id: ic.id })}
                />
              ))}

              {/*
                The chooser is the LAST TILE, not a zone above the grid: the place
                you are already looking at is the place you add to. A real
                <button>, so the keyboard reaches it and it is announced as one.
              */}
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
            </SimpleGrid>

            {shown.length === 0 ? (
              <Box pt="4" pb="2">
                <Text textStyle="description" textAlign="center">
                  {__('No icon matches that search.', 'easy-svg')}
                </Text>
              </Box>
            ) : null}
          </Box>
          {fileInput}
        </Stack>
      </Section>
      {deleteDialog}
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
}) {
  const name = nameOf(ic)
  const isEditing = null !== editing
  return (
    <Box
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
            <HStack data-tile-actions gap="0.5" justify="center" pt="0.5">
              <IconAction
                onClick={onCopy}
                disabled={busy}
                {...TILE_ACTION_SIZE}
                label={
                  // translators: %s: icon name.
                  sprintf(__('Copy the call for %s', 'easy-svg'), name)
                }
              >
                <CopyGlyph />
              </IconAction>
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
            </HStack>
          </>
        )}
      </Stack>
    </Box>
  )
}

// Three of these sit side by side in one tile, so they are narrower than the
// 40px raster -- except on a phone, where 44px is the touch target and a 28px
// one between two others is a mis-tap.
const TILE_ACTION_SIZE = {
  px: { base: '2', md: '1.5' },
  minW: { base: '44px', md: '28px' },
  minH: { base: '44px', md: '28px' },
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
