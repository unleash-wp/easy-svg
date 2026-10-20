import { useEffect, useRef, useState } from 'react'
import { Stack, HStack, NumberInput, Slider, Switch, Text } from '@chakra-ui/react'
import { __, sprintf } from '@wordpress/i18n'
import { Section, FieldRow, SaveState, SkeletonRows, UndoToast, useAutosave } from '../../ui.jsx'
import { Notice } from '../parts.jsx'
import { makeApi } from '../api.js'

// The ceiling the server clamps to (includes/settings.php). Named once here so
// the field's own `max` and the value sent on save cannot drift apart: typing 999
// used to pass the field's max silently and come back as 20 from the server.
// The server's ceiling, sent with the panel: Pro raises it, and a field that
// offered more than the save accepts would clamp silently.
const DEFAULT_MAX_MB = 20

// Coerce the server payload to a stable shape. max_mb MUST be a Number: the
// autosave compares with Object.is to skip a write that changes nothing, and a
// string '2' never equals the number 2, so a raw input value would write on
// every keystroke.
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
  const MAX_MB = Number(ctx.maxMbCeiling) > 0 ? Number(ctx.maxMbCeiling) : DEFAULT_MAX_MB
  const api = makeApi(ctx)
  const [info, setInfo] = useState('')
  /*
   * The size the server last confirmed, so an accepted write can be told from
   * one that only repeats what is already stored. The server is the authority
   * on the cap (includes/settings.php); with its answer taken as the truth the
   * field visibly jumps to the allowed number, and the line below says why.
   */
  const confirmedSize = useRef(null)

  const auto = useAutosave(async (patch) => {
    const sent = patch.max_mb
    const was = confirmedSize.current
    const resp = await api.post('/settings', patch)
    const n = normalize(resp)
    confirmedSize.current = n.max_mb
    setFieldSeq((seq) => seq + 1)

    if (undefined === sent) {
      return n
    }
    if (sent !== n.max_mb) {
      setInfo(
        sprintf(
          // translators: 1: the saved cap in MB, 2: the highest allowed cap in MB.
          __('The size cap was saved as %1$d MB, the nearest allowed value (1 to %2$d).', 'easy-svg'),
          n.max_mb,
          MAX_MB
        )
      )
    } else if (null !== was && sent !== was) {
      /*
       * Cleared only when the number actually moved, and never before the
       * server has confirmed one. The field clamps itself on blur and that
       * write arrives carrying the number the server already stored, so
       * clearing on every accepted write wiped the line explaining the jump a
       * few milliseconds after it appeared -- measured, with `was` still null.
       */
      setInfo('')
    }
    return n
  })
  const form = auto.value

  /*
   * Redraw the number after a refused write. useAutosave puts the last
   * confirmed value back, but NumberInput keeps the text that was typed apart
   * from its value and never rewrites it -- so the refused number stayed on
   * screen over a state that had already moved back. Same remount the clamp
   * uses, for the same reason.
   */
  useEffect(() => {
    if ('error' === auto.state) {
      setFieldSeq((seq) => seq + 1)
    }
  }, [auto.state])
  const [err, setErr] = useState('')
  /*
   * The number field's key, bumped only when the value changes from outside
   * it: NumberInput keeps typed text apart from its value and never rewrites
   * it, so a slider drag or a clamped save left the old number standing.
   * Bumping it on typing would remount the field under the caret.
   */
  const [fieldSeq, setFieldSeq] = useState(0)

  /*
   * Cancelled when the tab goes away: it is unmounted (unmountOnExit), and two
   * loads of the same resource can resolve out of order, leaving the form on
   * pre-save values. AbortError is our own cancelling, so it is not shown.
   */
  useEffect(() => {
    const ac = new AbortController()
    api
      .get('/settings', { signal: ac.signal })
      .then((data) => {
        auto.prime(normalize(data))
      })
      .catch((e) => {
        if ('AbortError' !== e.name) {
          setErr(e.message)
        }
      })
    return () => ac.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

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

  /*
   * A switch is written at once. Only the field that changed is sent: the
   * route merges the body onto what is stored, so a patch is enough.
   *
   * Switching one OFF is marked undoable -- that is the stray click that takes
   * something away, and the one case autosave is worse at than the button it
   * replaced. Switching on takes nothing, so it offers nothing.
   */
  const toggle = (k, v) => auto.set({ [k]: !!v }, { undoable: !v })

  /*
   * The number, after a pause or on blur. NOT clamped here: the server owns
   * the cap (includes/settings.php) and its answer is what the screen ends up
   * showing, so 500 goes out as 500 and comes back as the allowed number --
   * which is the whole point of taking the response as the truth, and what
   * makes the clamp visible instead of silent. An empty field is the default.
   */
  const setSize = (raw) => {
    const typed = '' === raw ? NaN : Number(raw)
    auto.set({ max_mb: Number.isFinite(typed) ? Math.round(typed) : 2 }, { typed: true })
  }

  return (
    <Stack gap="5" maxW="760px">
      <Section
        title={__('What runs', 'easy-svg')}
        description={__('Both are off by default. Every SVG is always cleaned and held to the size cap; that has no switch.', 'easy-svg')}
      >
        <ToggleRow
          id="esw-setting-svg-upload"
          checked={!!form.svg_upload}
          onChange={(v) => toggle('svg_upload', v)}
          label={__('Allow SVG uploads in the media library', 'easy-svg')}
          description={__('Editors can upload .svg files.', 'easy-svg')}
        />
        <ToggleRow
          id="esw-setting-icons"
          checked={!!form.icons}
          onChange={(v) => toggle('icons', v)}
          label={__('Enable the icon library', 'easy-svg')}
          description={__('Adds the Icon library tab and the Icon block.', 'easy-svg')}
        />
      </Section>

      <Section
        title={__('Security', 'easy-svg')}
        description={__('The largest SVG this site accepts, whatever is switched on above.', 'easy-svg')}
      >
        <FieldRow
          label={__('Maximum SVG size (MB)', 'easy-svg')}
          description={sprintf(
            // translators: %d: the highest allowed cap in MB.
            __('Larger files are refused. Allowed: 1 to %d MB.', 'easy-svg'),
            MAX_MB
          )}
          htmlFor="esw-setting-max-mb"
          control={
            /*
             * A slider for the gesture, a field for the exact value, one value
             * between them: somebody who knows they want 5 should not have to
             * aim for it. The slider's min/max also make an out-of-range
             * number unreachable by dragging.
             */
            <Stack gap="2" w={{ base: 'full', md: '260px' }}>
              <HStack gap="3" align="center">
                <Slider.Root
                  flex="1"
                  min={1}
                  max={MAX_MB}
                  step={1}
                  colorPalette="brand"
                  value={[Number(form.max_mb) || 1]}
                  onValueChange={(e) => {
                    setSize(e.value[0])
                    setFieldSeq((seq) => seq + 1)
                  }}
                  onValueChangeEnd={() => auto.flush()}
                  aria-label={[__('Maximum SVG size (MB)', 'easy-svg')]}
                >
                  <Slider.Control>
                    <Slider.Track bg="ui.border" h="4px" borderRadius="full">
                      <Slider.Range bg="ui.primary" />
                    </Slider.Track>
                    <Slider.Thumbs borderColor="ui.primary" bg="ui.surface" />
                  </Slider.Control>
                </Slider.Root>
                <NumberInput.Root
                  key={fieldSeq}
                  min={1}
                  max={MAX_MB}
                  value={String(form.max_mb)}
                  onValueChange={(e) => {
                    setSize(e.value)
                    /*
                     * The field clamps itself on blur but leaves the typed text
                     * standing: 120 stayed on screen while 100 was the number
                     * that would be saved. Remounting rewrites it, and only
                     * when the two disagree -- on every keystroke the remount
                     * would move the caret.
                     */
                    if (document.getElementById('esw-setting-max-mb')?.value !== e.value) {
                      setFieldSeq((seq) => seq + 1)
                    }
                  }}
                  w="92px"
                  flexShrink="0"
                >
                  {/* Chakra labels the steppers in English; they are buttons a
                      screen reader reads out, so they get our strings. */}
                  <NumberInput.Control>
                    <NumberInput.IncrementTrigger aria-label={__('Increase the size cap', 'easy-svg')} />
                    <NumberInput.DecrementTrigger aria-label={__('Decrease the size cap', 'easy-svg')} />
                  </NumberInput.Control>
                  <NumberInput.Input
                    id="esw-setting-max-mb"
                    onBlur={() => auto.flush()}
                    bg="ui.sunk"
                    borderColor="ui.border"
                    borderRadius="forge"
                    _focusVisible={{ bg: 'ui.surface', borderColor: 'ui.primary' }}
                  />
                </NumberInput.Root>
              </HStack>
              {/* The ends of the range, where a slider is read: the numbers
                  tell you what dragging can reach without a legend. */}
              <HStack justify="space-between" aria-hidden="true">
                <Text textStyle="caption">{sprintf(__('%d MB', 'easy-svg'), 1)}</Text>
                <Text textStyle="caption">{sprintf(__('%d MB', 'easy-svg'), MAX_MB)}</Text>
              </HStack>
            </Stack>
          }
        />
      </Section>

      {err ? <Notice bad role="alert">{err}</Notice> : null}
      {info ? <Notice role="status" status="info">{info}</Notice> : null}

      <SaveState
        state={auto.state}
        error={auto.error}
        savingLabel={__('Saving', 'easy-svg')}
        savedLabel={__('Saved', 'easy-svg')}
      />

      <UndoToast
        onUndo={auto.undo}
        label={__('Switched off.', 'easy-svg')}
        undoLabel={__('Undo', 'easy-svg')}
      />
    </Stack>
  )
}
