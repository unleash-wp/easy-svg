import { useEffect, useMemo, useRef, useState } from 'react'
import { Stack, HStack, SimpleGrid, Box, Text, Textarea, Checkbox } from '@chakra-ui/react'
import DOMPurify from 'dompurify'
import { __, _n, sprintf } from '@wordpress/i18n'
import { Section, FieldRow, EmptyState, SkeletonRows, Button, TextInput } from '../../ui.jsx'
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

export default function Library({ ctx }) {
  const api = makeApi(ctx)
  const [icons, setIcons] = useState(null)
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
  const [dragOver, setDragOver] = useState(false)
  const [editing, setEditing] = useState(null)
  const fileRef = useRef(null)

  useEffect(() => {
    if (!ctx.iconsEnabled) return
    api.get('/library').then(setIcons).catch((e) => setErr(e.message))
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
  }

  function stopSelecting() {
    setSelecting(false)
    setPicked(new Set())
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
      setIcons(fresh)
    }
    setReport(rows)
    setBusy(false)
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
      setIcons(await api.post('/library/add', { label, markup }))
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
        setIcons(fresh)
      }
      setPicked(new Set())
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
      setIcons(await api.post('/library/rename', { id, label: next }))
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
      setErr(
        // translators: %s: the PHP call that renders the icon, e.g. easy_svg_icon( 'heart' ).
        sprintf(__('The clipboard is not available here. The call is %s.', 'easy-svg'), text)
      )
    }
  }

  /*
   * A drop lands on the GRID, which is also where the chooser tile is: adding
   * happens where looking already happens. dragleave fires on every child
   * boundary as well, so the related target decides whether the pointer really
   * left the region.
   */
  const dropProps = {
    onDragEnter: (e) => {
      e.preventDefault()
      setDragOver(true)
    },
    onDragOver: (e) => {
      e.preventDefault()
      if (e.dataTransfer) {
        e.dataTransfer.dropEffect = 'copy'
      }
      setDragOver(true)
    },
    onDragLeave: (e) => {
      if (!e.currentTarget.contains(e.relatedTarget)) {
        setDragOver(false)
      }
    },
    onDrop: (e) => {
      e.preventDefault()
      setDragOver(false)
      addFiles(e.dataTransfer && e.dataTransfer.files)
    },
  }

  const dashed = {
    borderWidth: '1px',
    borderStyle: 'dashed',
    borderColor: dragOver ? 'ui.primary' : 'ui.border',
    borderRadius: 'forge',
    bg: dragOver ? 'ui.ghostHover' : 'transparent',
  }

  const pasteLabel = __('Paste markup', 'easy-svg')
  const fileInput = (
    <input ref={fileRef} type="file" accept={FILE_ACCEPT} multiple hidden tabIndex={-1} onChange={onPick} />
  )
  const openChooser = () => fileRef.current && fileRef.current.click()
  const pasteForm = (
    <PasteForm {...{ label, setLabel, markup, setMarkup, addPasted, busy, setPasting }} />
  )

  const messages = (
    <>
      {err ? <Notice bad role="alert">{err}</Notice> : null}
      {status ? (
        <Text role="status" aria-live="polite" color="ui.goodInk" fontSize="sm">{status}</Text>
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
          <Box {...dashed} px="4" py="8" {...dropProps}>
            <EmptyState
              title={__('Drop your first SVG here', 'easy-svg')}
              description={__('Every file is sanitised and hardened on the way in — the same bar as a media upload.', 'easy-svg')}
              action={
                <Stack gap="3" align="center">
                  <HStack gap="3" flexWrap="wrap" justify="center">
                    <Button variant="primary" onClick={openChooser} disabled={busy}>
                      {__('Choose an SVG', 'easy-svg')}
                    </Button>
                    <Button variant="ghost" onClick={() => setPasting((v) => !v)} aria-expanded={pasting}>
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
            <Button size="sm" variant="ghost" onClick={() => setPasting((v) => !v)} aria-expanded={pasting}>
              {pasteLabel}
            </Button>
          </HStack>

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
              label={sprintf(
                // translators: %d: number of icons selected.
                _n('%d icon selected', '%d icons selected', picked.size, 'easy-svg'),
                picked.size
              )}
              actionLabel={__('Remove', 'easy-svg')}
              onAction={() => remove(Array.from(picked))}
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
            outline={dragOver ? '2px dashed' : undefined}
            outlineColor="ui.primary"
            outlineOffset="-2px"
            {...dropProps}
          >
            <SimpleGrid columns={{ base: 3, md: 5, lg: 7 }} gap="3">
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
                  onRemove={() => remove(ic.id)}
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
                {...dashed}
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
        '& [data-tile-actions]': { opacity: 0, transition: 'opacity .12s ease' },
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
            <HStack gap="2" justify="center">
              <Button
                size="sm"
                variant="primary"
                onClick={() => onEditSave(editing)}
                disabled={busy || !editing.trim()}
              >
                {__('Save', 'easy-svg')}
              </Button>
              <Button size="sm" variant="ghost" onClick={onEditCancel} disabled={busy}>
                {__('Cancel', 'easy-svg')}
              </Button>
            </HStack>
          </Stack>
        ) : (
          <>
            <Text fontSize="xs" color="ui.muted" textAlign="center" lineClamp={1} w="full" title={name}>
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

// An icon-only button, so its whole name comes from aria-label — there is no text
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
      px="1.5"
      py="1.5"
      minW="0"
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
        description={__('Optional — a slug is derived from it.', 'easy-svg')}
        htmlFor="esw-icon-name"
        control={
          <Box maxW="220px">
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
            rows={4}
            placeholder="<svg>…</svg>"
            value={markup}
            onChange={(e) => setMarkup(e.target.value)}
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
