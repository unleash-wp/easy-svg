import { useEffect, useRef, useState } from 'react'
import { Stack, Box, NumberInput, Switch } from '@chakra-ui/react'
import { __, sprintf } from '@wordpress/i18n'
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
  const [info, setInfo] = useState('')
  const [saving, setSaving] = useState(false)
  /*
   * Bumped after every save, and used as the number field's key.
   *
   * NumberInput keeps the text somebody typed separately from its value, and
   * it does not rewrite that text when the value prop changes underneath it:
   * typing 0 and saving left "0" standing over a field whose own machine --
   * and the server -- had already agreed on 1. Remounting re-seeds the text
   * from the value, so the field shows what was stored.
   */
  const [savedSeq, setSavedSeq] = useState(0)

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
    setInfo('')
    // Nearest allowed value, not the default: 0 used to come back as 2 with
    // nothing said. An empty field still means "the default".
    const typed = form.max_mb === '' ? NaN : Number(form.max_mb)
    const sent = Number.isFinite(typed) ? Math.min(MAX_MB, Math.max(1, Math.round(typed))) : 2
    try {
      const resp = await api.post('/settings', {
        svg_upload: !!form.svg_upload,
        icons: !!form.icons,
        max_mb: sent,
      })
      const n = normalize(resp)
      setForm(n)
      markSaved(n)
      setSavedSeq((seq) => seq + 1)
      if (typed !== n.max_mb) {
        setInfo(
          sprintf(
            // translators: 1: the saved cap in MB, 2: the highest allowed cap in MB.
            __('The size cap was saved as %1$d MB, the nearest allowed value (1 to %2$d).', 'easy-svg'),
            n.max_mb,
            MAX_MB
          )
        )
      }
    } catch (e) {
      setErr(e.message)
    } finally {
      setSaving(false)
    }
  }

  function reset() {
    setForm(snap.current)
    setErr('')
    setInfo('')
  }

  return (
    <Stack gap="5" maxW="760px">
      <Section
        title={__('Features', 'easy-svg')}
        description={__('Both are off by default. The security layer is always on: every SVG is sanitised and the size cap applies. There is no switch for it.', 'easy-svg')}
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
        description={__('The cap limits what the sanitiser will parse. It applies whatever the features above are set to.', 'easy-svg')}
      >
        <FieldRow
          label={__('Maximum SVG size (MB)', 'easy-svg')}
          description={sprintf(
            // translators: %d: the highest allowed cap in MB.
            __('Files larger than this are rejected before the sanitiser runs. Allowed: 1 to %d MB.', 'easy-svg'),
            MAX_MB
          )}
          htmlFor="esw-setting-max-mb"
          control={
            // Chakra's NumberInput: it carries the min and max as a contract
            // rather than as two attributes the browser may or may not act on,
            // and it brings the steppers, the scrub handle and the keyboard
            // arrows a number field is expected to have.
            <NumberInput.Root
              key={savedSeq}
              min={1}
              max={MAX_MB}
              value={String(form.max_mb)}
              onValueChange={(e) => set('max_mb', e.value === '' ? '' : Number(e.value))}
              w="120px"
            >
              <NumberInput.Control />
              <NumberInput.Input
                id="esw-setting-max-mb"
                bg="ui.sunk"
                borderColor="ui.border"
                borderRadius="forge"
                _focusVisible={{ bg: 'ui.surface', borderColor: 'ui.primary' }}
              />
            </NumberInput.Root>
          }
        />
      </Section>

      {err ? <Notice bad role="alert">{err}</Notice> : null}
      {info ? <Notice role="status">{info}</Notice> : null}

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
