import { useEffect, useState } from 'react'
import { Stack, HStack, SimpleGrid, Box, Text, Textarea } from '@chakra-ui/react'
import DOMPurify from 'dompurify'
import { __, _n, sprintf } from '@wordpress/i18n'
import { Section, FieldRow, EmptyState, SkeletonRows, Button, TextInput } from '../../ui.jsx'
import { Notice } from '../parts.jsx'
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

export default function Library({ ctx }) {
  const api = makeApi(ctx)
  const [icons, setIcons] = useState(null)
  const [err, setErr] = useState('')
  const [status, setStatus] = useState('')
  const [label, setLabel] = useState('')
  const [markup, setMarkup] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!ctx.iconsEnabled) return
    api.get('/library').then(setIcons).catch((e) => setErr(e.message))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ctx.iconsEnabled])

  // The add form is a control that depends on the Icon-library toggle, so it is
  // only revealed when the feature is on; otherwise point back to Settings.
  if (!ctx.iconsEnabled) {
    return <Notice>{__('The icon library is off. Switch it on under the Settings tab.', 'easy-svg')}</Notice>
  }

  async function add() {
    if (!markup.trim()) return
    setBusy(true)
    setErr('')
    setStatus('')
    try {
      setIcons(await api.post('/library/add', { label, markup }))
      setLabel('')
      setMarkup('')
      setStatus(__('Icon added.', 'easy-svg'))
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }
  async function remove(id) {
    setBusy(true)
    setErr('')
    setStatus('')
    try {
      setIcons(await api.post('/library/delete', { id }))
      setStatus(__('Icon removed.', 'easy-svg'))
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }
  function onFile(e) {
    const f = e.target.files && e.target.files[0]
    if (!f) return
    const reader = new FileReader()
    reader.onload = () => {
      setMarkup(String(reader.result || ''))
      if (!label) setLabel(f.name.replace(/\.svg$/i, ''))
    }
    reader.readAsText(f)
  }

  const fileLabel = __('SVG file', 'easy-svg')

  return (
    <Stack gap="5" maxW="900px">
      <Section
        title={__('Add an icon', 'easy-svg')}
        description={__('Upload an .svg file or paste its markup. It is sanitised and hardened before it is stored — the same bar as a media upload.', 'easy-svg')}
      >
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
          label={fileLabel}
          htmlFor="esw-icon-file"
          control={
            <input
              id="esw-icon-file"
              type="file"
              accept=".svg,image/svg+xml"
              aria-label={fileLabel}
              onChange={onFile}
            />
          }
        />
        <FieldRow
          layout="stack"
          label={__('SVG markup', 'easy-svg')}
          description={__('Paste the <svg>…</svg>, or pick a file above.', 'easy-svg')}
          htmlFor="esw-icon-markup"
          control={
            <Textarea
              id="esw-icon-markup"
              rows={4}
              placeholder="<svg>…</svg>"
              value={markup}
              onChange={(e) => setMarkup(e.target.value)}
              bg="ui.sunk"
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
              p="3"
              boxSize="72px"
              overflow="hidden"
              css={{ '& svg': { width: '100%', height: '100%' } }}
              dangerouslySetInnerHTML={{ __html: sanitizeSvg(markup) }}
            />
          </Box>
        ) : null}
        <HStack pt="4">
          <Button variant="primary" onClick={add} disabled={busy || !markup.trim()}>
            {busy ? __('Adding…', 'easy-svg') : __('Add', 'easy-svg')}
          </Button>
        </HStack>
      </Section>

      {err ? <Notice bad role="alert">{err}</Notice> : null}
      {status ? (
        <Text role="status" aria-live="polite" color="ui.good" fontSize="sm">{status}</Text>
      ) : null}

      {err && icons === null ? null : icons === null ? (
        <Section title={__('Your icons', 'easy-svg')}>
          <SkeletonRows rows={3} />
        </Section>
      ) : icons.length === 0 ? (
        <Section title={__('Your icons', 'easy-svg')}>
          <EmptyState
            title={__('No icons yet', 'easy-svg')}
            description={__('Add your first icon with the form above.', 'easy-svg')}
          />
        </Section>
      ) : (
        <Section
          title={__('Your icons', 'easy-svg')}
          description={sprintf(
            // translators: %d: number of icons in the library.
            _n('%d icon in the library.', '%d icons in the library.', icons.length, 'easy-svg'),
            icons.length
          )}
        >
          <SimpleGrid columns={{ base: 3, md: 5, lg: 7 }} gap="3" pt="1">
            {icons.map((ic) => (
              <Box
                key={ic.id}
                bg="ui.surface"
                borderWidth="1px"
                borderColor="ui.border"
                borderRadius="forge"
                px="3"
                py="3"
              >
                <Stack gap="2" align="center">
                  <Box
                    boxSize="40px"
                    aria-hidden="true"
                    css={{ '& svg': { width: '100%', height: '100%' } }}
                    dangerouslySetInnerHTML={{ __html: sanitizeSvg(ic.content) }}
                  />
                  <Text fontSize="xs" color="ui.muted" textAlign="center" lineClamp={1}>
                    {ic.label || ic.slug}
                  </Text>
                  <Button
                    size="sm"
                    variant="ghost"
                    danger
                    onClick={() => remove(ic.id)}
                    disabled={busy}
                    aria-label={
                      // translators: %s: icon name.
                      sprintf(__('Delete %s', 'easy-svg'), ic.label || ic.slug)
                    }
                  >
                    {__('Delete', 'easy-svg')}
                  </Button>
                </Stack>
              </Box>
            ))}
          </SimpleGrid>
        </Section>
      )}
    </Stack>
  )
}
