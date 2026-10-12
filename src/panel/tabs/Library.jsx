import { useEffect, useMemo, useRef, useState } from 'react'
import { Stack, HStack, SimpleGrid, Box, Text, Textarea, Checkbox } from '@chakra-ui/react'
import DOMPurify from 'dompurify'
import { __, _n, sprintf } from '@wordpress/i18n'
import { Section, FieldRow, EmptyState, SkeletonRows, Button, TextInput, useDropZone } from '../../ui.jsx'
import { Notice, SearchField, BulkBar, ReportList } from '../parts.jsx'
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
  const copyHelpRef = useRef(null)
  const fileRef = useRef(null)

  // One place unwraps a library response, so a route that still answers the old
  // bare array keeps working and the total only has to be read once.
  const take = (res) => {
    const list = listOf(res)
    setIcons(list)
    setTotal(totalOf(res, list.length))
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

  // Cancelled when the tab goes away, or when the icon library is switched off
  // while a load is in flight. See the note in tabs/Settings.jsx.
  useEffect(() => {
    if (!ctx.iconsEnabled) return undefined
    const ac = new AbortController()
    api
      .get('/library', { signal: ac.signal })
      .then(take)
      .catch((e) => {
        if ('AbortError' !== e.name) {
          setErr(e.message)
        }
      })
    return () => ac.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ctx.iconsEnabled])

  const all = icons || []

  // Above the early return below, because a hook may not be skipped: this tab
  // re-renders with iconsEnabled flipped the moment the Settings tab is saved.
  const shown = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) {
      return all
    }
    // Name AND slug: the slug is what a template writes, so somebody searching
    // for "arrow-right" has to find the icon they named "Pfeil nach rechts".
    return all.filter((ic) => `${ic.label} ${ic.slug}`.toLowerCase().includes(q))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [icons, query])

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

  // The legacy path for exactly the pages the modern API refuses: select the
  // call, try execCommand, and either way leave it selected for a manual copy.
  function copyHelpByHand() {
    const node = copyHelpRef.current
    if (!node) {
      return
    }
    const range = document.createRange()
    range.selectNodeContents(node)
    const selection = window.getSelection()
    selection.removeAllRanges()
    selection.addRange(range)
    let copied = false
    try {
      copied = document.execCommand('copy')
    } catch {
      copied = false
    }
    if (copied) {
      setCopyHelp('')
      setStatus(
        // translators: %s: the PHP call that renders the icon, e.g. easy_svg_icon( 'heart' ).
        sprintf(__('Copied %s to the clipboard.', 'easy-svg'), node.textContent)
      )
    }
  }

  // Two targets, one behaviour: the zone above and the grid below both take a
  // drop, because adding where you are already looking was right -- it was just
  // the only place. ui.jsx owns the handlers so pro's tabs get the same ones.
  const zone = useDropZone({ onFiles: addFiles, disabled: busy })
  const grid = useDropZone({ onFiles: addFiles, disabled: busy })

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

  /*
   * The add surface, the width of the content and above the grid.
   *
   * It used to be the last tile IN the grid: 49px wide, a 13px label wrapped
   * over three lines, the primary action of the screen rendered smaller than
   * anything else on it -- with "Paste markup", which is a developer's errand,
   * as a full button above. The tile stays as a shortcut; it is no longer the
   * only door.
   */
  const addSurface = (
    <Box {...dashed(zone.isOver)} px="4" py="5" {...zone.props}>
      <Stack gap="2" align="center" textAlign="center">
        <Text fontSize="sm" fontWeight="700" color="ui.heading">
          {__('Drop SVG files here', 'easy-svg')}
        </Text>
        <HStack gap="2" flexWrap="wrap" justify="center">
          <Button variant="primary" size="sm" onClick={openChooser} disabled={busy}>
            {__('Choose an SVG', 'easy-svg')}
          </Button>
          <Button variant="ghost" size="sm" onClick={addFromMedia} disabled={busy}>
            {__('Choose from the media library', 'easy-svg')}
          </Button>
        </HStack>
        <Button variant="ghost" size="sm" onClick={() => setPasting((v) => !v)} aria-expanded={pasting}>
          {pasteLabel}
        </Button>
      </Stack>
    </Box>
  )
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
        <Notice role="status">
          <HStack justify="space-between" gap="3" align="center" flexWrap="wrap">
            <Text as="span" fontSize="sm">
              {__('The clipboard is not available here. Copy the call by hand:', 'easy-svg')}{' '}
              <Box as="code" ref={copyHelpRef} fontFamily="mono" userSelect="all">{copyHelp}</Box>
            </Text>
            <HStack gap="2">
              <Button size="sm" variant="ghost" onClick={copyHelpByHand}>
                {__('Copy', 'easy-svg')}
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setCopyHelp('')}>
                {__('Dismiss', 'easy-svg')}
              </Button>
            </HStack>
          </HStack>
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

  // Nothing stored yet: the empty state IS the upload surface. Not a sentence
  // about where to upload, but the place where you do it.
  if (all.length === 0) {
    return (
      <Stack gap="5" maxW="900px">
        {messages}
        {reportBox}
        <Section title={__('Your icons', 'easy-svg')}>
          <Box {...dashed(zone.isOver)} px="4" py="5" {...zone.props}>
            <EmptyState
              py="2"
              contentProps={{ maxW: 'none', gap: '3' }}
              title={__('Drop your first SVG here', 'easy-svg')}
              description={__('Every file is sanitised and hardened on the way in. Same bar as a media upload.', 'easy-svg')}
              action={
                <Stack gap="3" align="center">
                  <HStack gap="2" flexWrap="wrap" justify="center">
                    <Button variant="primary" size="sm" onClick={openChooser} disabled={busy}>
                      {__('Choose an SVG', 'easy-svg')}
                    </Button>
                    <Button variant="ghost" size="sm" onClick={addFromMedia} disabled={busy}>
                      {__('Choose from the media library', 'easy-svg')}
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setPasting((v) => !v)} aria-expanded={pasting}>
                      {pasteLabel}
                    </Button>
                  </HStack>
                  <Text textStyle="caption">{__('There is no limit on how many icons you keep.', 'easy-svg')}</Text>
                </Stack>
              }
            />
          </Box>
          {fileInput}
          {pasting ? <Box pt="4">{pasteForm}</Box> : null}
        </Section>
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
          </HStack>

          {addSurface}

          {/*
            The match count, announced: typing tells a sighted person nothing
            either until they look at the grid. role=status implies a polite live
            region, so it is spoken once the keystrokes stop.
          */}
          {query.trim() ? (
            <Text role="status" aria-live="polite" textStyle="caption">
              {sprintf(
                // translators: 1: number of icons matching the search, 2: number of icons in the library.
                _n('Showing %1$d of %2$d icon.', 'Showing %1$d of %2$d icons.', all.length, 'easy-svg'),
                shown.length,
                all.length
              )}
            </Text>
          ) : null}

          {pasting ? pasteForm : null}
          {reportBox}
          {messages}

          {selecting && picked.size > 0 ? (
            <BulkBar
              count={picked.size}
              busy={busy}
              label={
                confirming && confirming.bulk
                  ? sprintf(
                      // translators: %d: number of icons selected.
                      _n('Remove %d icon? This cannot be undone.', 'Remove %d icons? This cannot be undone.', picked.size, 'easy-svg'),
                      picked.size
                    )
                  : sprintf(
                      // translators: %d: number of icons selected.
                      _n('%d icon selected', '%d icons selected', picked.size, 'easy-svg'),
                      picked.size
                    )
              }
              actionLabel={confirming && confirming.bulk ? __('Remove permanently', 'easy-svg') : __('Remove', 'easy-svg')}
              onAction={() => (confirming && confirming.bulk ? remove(Array.from(picked)) : setConfirming({ bulk: true }))}
              cancelLabel={confirming && confirming.bulk ? __('Cancel', 'easy-svg') : null}
              onCancel={() => setConfirming(null)}
            />
          ) : null}

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
                  confirmingDelete={!!(confirming && confirming.id === ic.id)}
                  onRemove={() => setConfirming({ id: ic.id })}
                  onConfirmRemove={() => remove(ic.id)}
                  onCancelRemove={() => setConfirming(null)}
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
  confirmingDelete,
  onRemove,
  onConfirmRemove,
  onCancelRemove,
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
      gridColumn={isEditing || confirmingDelete ? 'span 2' : undefined}
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
        ) : confirmingDelete ? (
          <Stack gap="2" w="full" pt="1" align="center">
            <Text fontSize="xs" color="ui.heading" fontWeight="700" textAlign="center">
              {
                // translators: %s: icon name.
                sprintf(__('Delete %s? This cannot be undone.', 'easy-svg'), name)
              }
            </Text>
            <Stack gap="2" w="full">
              <Button
                size="sm"
                variant="primary"
                bg="ui.bad"
                w="full"
                _hover={{ bg: 'ui.bad', opacity: 0.9 }}
                _disabled={{ opacity: 0.55, bg: 'ui.bad', color: 'white', cursor: 'default' }}
                onClick={onConfirmRemove}
                disabled={busy}
              >
                {__('Delete', 'easy-svg')}
              </Button>
              <Button size="sm" variant="ghost" w="full" onClick={onCancelRemove} disabled={busy}>
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
              <TileAction
                onClick={onCopy}
                disabled={busy}
                label={
                  // translators: %s: icon name.
                  sprintf(__('Copy the call for %s', 'easy-svg'), name)
                }
              >
                <CopyGlyph />
              </TileAction>
              <TileAction
                onClick={onEdit}
                disabled={busy}
                label={
                  // translators: %s: icon name.
                  sprintf(__('Rename %s', 'easy-svg'), name)
                }
              >
                <RenameGlyph />
              </TileAction>
              <TileAction
                onClick={onRemove}
                disabled={busy}
                danger
                label={
                  // translators: %s: icon name.
                  sprintf(__('Delete %s', 'easy-svg'), name)
                }
              >
                <TrashGlyph />
              </TileAction>
            </HStack>
          </>
        )}
      </Stack>
    </Box>
  )
}

// An icon-only button, so its whole name comes from aria-label: there is no text
// to read. Narrow enough that three of them fit a tile at seven columns, and
// borderless until hovered so a tile is not three boxes in a box.
function TileAction({ label, onClick, disabled, danger, children }) {
  return (
    <Button
      size="sm"
      variant="ghost"
      danger={danger}
      aria-label={label}
      onClick={onClick}
      disabled={disabled}
      px={{ base: '2', md: '1.5' }}
      py={{ base: '2', md: '1.5' }}
      // 44 on a phone, where three of these sit side by side in one tile and a
      // 28px target between two others is a mis-tap. On md and up it is a mouse,
      // and the tile goes back to holding three narrow buttons.
      minW={{ base: '44px', md: '0' }}
      minH={{ base: '44px', md: '0' }}
      borderColor="transparent"
    >
      {children}
    </Button>
  )
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
