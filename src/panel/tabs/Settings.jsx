import React, { useEffect, useState } from 'react'
import { Stack, HStack, Text, Switch, Box } from '@chakra-ui/react'
import { Button, Card, TextInput } from '../../ui.jsx'
import { Notice, Loading } from '../parts.jsx'
import { makeApi } from '../api.js'

export default function Settings({ ctx }) {
  const api = makeApi(ctx)
  const [s, setS] = useState(null)
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    api.get('/settings').then(setS).catch((e) => setErr(e.message))
  }, [])

  if (err && !s) return <Notice bad>{err}</Notice>
  if (!s) return <Loading />

  const set = (k, v) => {
    setS({ ...s, [k]: v })
    setSaved(false)
  }

  async function save() {
    setSaving(true)
    setErr('')
    try {
      setS(await api.post('/settings', {
        svg_upload: !!s.svg_upload,
        icons: !!s.icons,
        max_mb: Math.max(1, Number(s.max_mb) || 2),
      }))
      setSaved(true)
    } catch (e) {
      setErr(e.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Stack gap="5" maxW="640px">
      <Card>
        <Stack gap="4">
          <Text fontWeight="600" color="ui.text">Features</Text>
          <Text fontSize="sm" color="ui.muted">
            Beide standardmäßig aus. Die Sicherheit (Bereinigung jeder SVG + Größen-Cap) ist immer aktiv — kein Schalter.
          </Text>
          <Switch.Root checked={!!s.svg_upload} onCheckedChange={(e) => set('svg_upload', e.checked)} colorPalette="brand">
            <Switch.HiddenInput />
            <Switch.Control><Switch.Thumb /></Switch.Control>
            <Switch.Label fontSize="sm">SVG-Uploads in der Mediathek erlauben</Switch.Label>
          </Switch.Root>
          <Switch.Root checked={!!s.icons} onCheckedChange={(e) => set('icons', e.checked)} colorPalette="brand">
            <Switch.HiddenInput />
            <Switch.Control><Switch.Thumb /></Switch.Control>
            <Switch.Label fontSize="sm">Icon-Library aktivieren</Switch.Label>
          </Switch.Root>
        </Stack>
      </Card>

      <Card>
        <Stack gap="3">
          <Text fontWeight="600" color="ui.text">Sicherheit</Text>
          <HStack gap="3">
            <Text fontSize="sm" color="ui.muted" minW="160px">Maximale SVG-Größe (MB)</Text>
            <Box maxW="120px">
              <TextInput type="number" min="1" max="20" value={s.max_mb} onChange={(e) => set('max_mb', e.target.value)} />
            </Box>
          </HStack>
          <Text fontSize="xs" color="ui.muted">
            Der Cap begrenzt, was der Sanitizer parst — immer aktiv, unabhängig von den Features oben.
          </Text>
        </Stack>
      </Card>

      {err ? <Notice bad>{err}</Notice> : null}
      <HStack gap="3">
        <Button variant="primary" onClick={save} disabled={saving}>{saving ? 'Speichert…' : 'Speichern'}</Button>
        {saved ? <Text color="ui.good" fontSize="sm">Gespeichert</Text> : null}
      </HStack>
    </Stack>
  )
}
