import React, { useEffect, useState } from 'react'
import { Stack, HStack, SimpleGrid, Box, Text, Textarea } from '@chakra-ui/react'
import DOMPurify from 'dompurify'
import { Button, Card, TextInput } from '../../ui.jsx'
import { Notice, Loading } from '../parts.jsx'
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
  const [label, setLabel] = useState('')
  const [markup, setMarkup] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!ctx.iconsEnabled) return
    api.get('/library').then(setIcons).catch((e) => setErr(e.message))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ctx.iconsEnabled])

  if (!ctx.iconsEnabled) {
    return <Notice>Die Icon-Library ist aus. Schalte sie im Tab „Einstellungen" ein.</Notice>
  }
  if (err && !icons) return <Notice bad>{err}</Notice>
  if (!icons) return <Loading />

  async function add() {
    if (!markup.trim()) return
    setBusy(true)
    setErr('')
    try {
      setIcons(await api.post('/library/add', { label, markup }))
      setLabel('')
      setMarkup('')
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }
  async function remove(id) {
    setBusy(true)
    setErr('')
    try {
      setIcons(await api.post('/library/delete', { id }))
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

  return (
    <Stack gap="5" maxW="900px">
      <Card>
        <Stack gap="3">
          <Text fontWeight="600" color="ui.text">Icon hinzufügen</Text>
          <HStack gap="3" flexWrap="wrap" align="center">
            <Box flex="1" minW="200px">
              <TextInput placeholder="Name (optional)" value={label} onChange={(e) => setLabel(e.target.value)} />
            </Box>
            <input type="file" accept=".svg,image/svg+xml" onChange={onFile} />
          </HStack>
          <Textarea
            rows={4}
            placeholder="<svg>…</svg> einfügen"
            value={markup}
            onChange={(e) => setMarkup(e.target.value)}
            bg="ui.sunk"
            borderColor="ui.border"
            borderRadius="forge"
            fontFamily="mono"
            fontSize="sm"
          />
          {markup.trim() ? (
            <Box
              borderWidth="1px"
              borderColor="ui.border"
              borderRadius="forge"
              p="3"
              boxSize="72px"
              overflow="hidden"
              css={{ '& svg': { width: '100%', height: '100%' } }}
              dangerouslySetInnerHTML={{ __html: sanitizeSvg(markup) }}
            />
          ) : null}
          <HStack>
            <Button variant="primary" onClick={add} disabled={busy || !markup.trim()}>{busy ? 'Speichert…' : 'Hinzufügen'}</Button>
          </HStack>
        </Stack>
      </Card>

      {err ? <Notice bad>{err}</Notice> : null}

      {icons.length === 0 ? (
        <Notice>Noch keine Icons. Füge oben eins hinzu.</Notice>
      ) : (
        <SimpleGrid columns={{ base: 3, md: 5, lg: 7 }} gap="3">
          {icons.map((ic) => (
            <Card key={ic.id} px="3" py="3">
              <Stack gap="2" align="center">
                <Box boxSize="40px" css={{ '& svg': { width: '100%', height: '100%' } }} dangerouslySetInnerHTML={{ __html: sanitizeSvg(ic.content) }} />
                <Text fontSize="xs" color="ui.muted" textAlign="center" lineClamp={1}>{ic.label || ic.slug}</Text>
                <Button size="sm" variant="ghost" danger onClick={() => remove(ic.id)} disabled={busy}>Löschen</Button>
              </Stack>
            </Card>
          ))}
        </SimpleGrid>
      )}
    </Stack>
  )
}
