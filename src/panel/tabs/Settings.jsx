import { useEffect, useRef, useState } from 'react'
import { Stack, Box, Switch } from '@chakra-ui/react'
import { __ } from '@wordpress/i18n'
import { Section, FieldRow, SaveBar, TextInput, SkeletonRows, useDirty } from '../../ui.jsx'
import { Notice } from '../parts.jsx'
import { makeApi } from '../api.js'

// The ceiling the server clamps to (includes/settings.php). Named once here so
// the field's own `max` and the value sent on save cannot drift apart: typing 999
// used to pass the field's max silently and come back as 20 from the server.
const MAX_MB = 20

// Coerce the server payload to a stable shape. max_mb MUST be a Number: useDirty
// compares with Object.is, and a string '2' never equals the number 2, so a raw
// input value would read dirty forever.
const normalize = (s) => {
  const mb = Number(s?.max_mb)
  return {
    svg_upload: !!s?.svg_upload,
    icons: !!s?.icons,
    max_mb: Number.isFinite(mb) ? mb : 2,
  }
}

// A switch presented as a FieldRow: the row's <label htmlFor> drives the switch's
// hidden input, so the accessible name is just the label (the description stays
// out of it) and clicking the label toggles the switch.
function ToggleRow({ id, checked, onChange, label, description }) {
  return (
    <FieldRow
      label={label}
      description={description}
      htmlFor={id}
      control={
        <Switch.Root
          ids={{ hiddenInput: id }}
          checked={checked}
          onCheckedChange={(e) => onChange(e.checked)}
          colorPalette="brand"
        >
          <Switch.HiddenInput />
          <Switch.Control><Switch.Thumb /></Switch.Control>
        </Switch.Root>
      }
    />
  )
}

export default function Settings({ ctx }) {
  const api = makeApi(ctx)
  const [form, setForm] = useState(null)
  const snap = useRef(null)
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)

  /*
   * The load is cancelled when this tab goes away.
   *
   * A closed tab is unmounted (the panel's Tabs root uses unmountOnExit), so
   * without this a load started on mount resolves into a component that is
   * gone -- and two loads of the same resource can resolve out of order, so an
   * older payload lands last and the form shows pre-save values. AbortError is
   * what fetch rejects with when we did the cancelling, so it is not an error
   * to show anybody.
   */
  useEffect(() => {
    const ac = new AbortController()
    api
      .get('/settings', { signal: ac.signal })
      .then((data) => {
        const n = normalize(data)
        snap.current = n
        setForm(n)
      })
      .catch((e) => {
        if ('AbortError' !== e.name) {
          setErr(e.message)
        }
      })
    return () => ac.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const { dirty, markSaved } = useDirty(form, snap)

  if (err && !form) {
    return <Notice bad role="alert">{err}</Notice>
  }
  if (!form) {
    return (
      <Stack gap="5" maxW="760px">
        <SkeletonRows rows={3} />
      </Stack>
    )
  }

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  async function save() {
    setSaving(true)
    setErr('')
    try {
      const resp = await api.post('/settings', {
        svg_upload: !!form.svg_upload,
        icons: !!form.icons,
        max_mb: Math.min(MAX_MB, Math.max(1, Number(form.max_mb) || 2)),
      })
      const n = normalize(resp)
      setForm(n)
      markSaved(n)
    } catch (e) {
      setErr(e.message)
    } finally {
      setSaving(false)
    }
  }

  function reset() {
    setForm(snap.current)
    setErr('')
  }

  return (
    <Stack gap="5" maxW="760px">
      <Section
        title={__('Features', 'easy-svg')}
        description={__('Both are off by default. The security layer (every SVG is sanitised, plus the size cap) is always on — there is no switch for it.', 'easy-svg')}
      >
        <ToggleRow
          id="esw-setting-svg-upload"
          checked={!!form.svg_upload}
          onChange={(v) => set('svg_upload', v)}
          label={__('Allow SVG uploads in the media library', 'easy-svg')}
          description={__('Editors can upload .svg files; each one is sanitised on the way in.', 'easy-svg')}
        />
        <ToggleRow
          id="esw-setting-icons"
          checked={!!form.icons}
          onChange={(v) => set('icons', v)}
          label={__('Enable the icon library', 'easy-svg')}
          description={__('Adds the Icon library tab and the Icon block.', 'easy-svg')}
        />
      </Section>

      <Section
        title={__('Security', 'easy-svg')}
        description={__('The cap limits what the sanitiser will parse — always on, regardless of the features above.', 'easy-svg')}
      >
        <FieldRow
          label={__('Maximum SVG size (MB)', 'easy-svg')}
          description={__('Files larger than this are rejected before the sanitiser runs.', 'easy-svg')}
          htmlFor="esw-setting-max-mb"
          control={
            <Box maxW="120px">
              <TextInput
                id="esw-setting-max-mb"
                type="number"
                min="1"
                max={String(MAX_MB)}
                value={form.max_mb}
                onChange={(e) => set('max_mb', e.target.value === '' ? '' : Number(e.target.value))}
              />
            </Box>
          }
        />
      </Section>

      {err ? <Notice bad role="alert">{err}</Notice> : null}

      <SaveBar
        dirty={dirty}
        saving={saving}
        onSave={save}
        onReset={reset}
        dirtyLabel={__('Unsaved changes', 'easy-svg')}
        savedLabel={__('All changes saved', 'easy-svg')}
        saveLabel={__('Save', 'easy-svg')}
        savingLabel={__('Saving…', 'easy-svg')}
        resetLabel={__('Reset', 'easy-svg')}
      />
    </Stack>
  )
}
