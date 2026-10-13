import { forwardRef, useEffect, useRef, useState } from 'react'
import {
  Alert,
  Button as CButton,
  Input,
  InputGroup,
  VisuallyHidden,
  Box,
  Flex,
  HStack,
  VStack,
  Text,
  Heading,
  Badge,
  IconButton as CIconButton,
  Skeleton,
  SkeletonText,
  Tooltip as CTooltip,
  EmptyState as CEmptyState,
  Field,
} from '@chakra-ui/react'

// The UnleashWP buttons, same rules as the account app:
// primary = navy, hover turns it yellow; accent = the one yellow call to action;
// ghost = outline. The "forge" radius is theirs. Ubuntu ships only 400 and
// 700, so buttons use 700; 500/600 would render as a faux weight the font
// never shipped.
// forwardRef is load-bearing: Chakra's `asChild` triggers clone their child and
// hand it a ref, and a plain function component drops it -- the trigger then has
// nothing to anchor or open from and silently does nothing.
export const Button = forwardRef(function Button(
  { variant = 'ghost', size = 'md', danger, children, ...rest },
  ref
) {
  const common = {
    size: size === 'sm' ? 'sm' : 'md',
    borderRadius: 'forge',
    fontWeight: '700',
    h: 'auto',
    // The 40px control raster, 14px label like every other label. minH, not
    // h: a wrapping label may still grow, and the tile actions pass their own
    // minH to stay compact inside a tile.
    fontSize: '14px',
    lineHeight: '20px',
    fontFamily: 'heading',
    minH: '40px',
    py: '2',
    _focusVisible: { outline: '2px solid', outlineColor: 'ui.primary', outlineOffset: '2px' },
    'data-variant': variant,
  }

  if (variant === 'accent') {
    return (
      <CButton ref={ref} {...common} bg="yellow" color="navy" boxShadow="sm"
        _hover={{ bg: '#e6ac00' }} _active={{ transform: 'translateY(1px)' }}
        _disabled={{ opacity: 0.55, bg: 'yellow', color: 'navy', cursor: 'default' }} {...rest}>
        {children}
      </CButton>
    )
  }

  if (variant === 'primary') {
    // Hover lightens the navy instead of flipping to yellow: a full colour
    // swap reads as a state change, and yellow is the brand accent, not a
    // confirmation colour.
    return (
      <CButton ref={ref} {...common} bg="navy" color="white" boxShadow="sm"
        _hover={{ bg: '#2c4070' }} _active={{ transform: 'translateY(1px)' }}
        _disabled={{ opacity: 0.55, bg: 'navy', color: 'white', cursor: 'default' }} {...rest}>
        {children}
      </CButton>
    )
  }

  return (
    <CButton ref={ref} {...common} variant="outline" color="ui.primary" borderColor="ui.border"
      _hover={danger ? { color: 'ui.bad', borderColor: 'ui.bad' } : { borderColor: 'ui.primary', bg: 'ui.ghostHover' }}
      _active={{ transform: 'translateY(1px)' }} {...rest}>
      {children}
    </CButton>
  )
})

export function Card({ children, ...rest }) {
  return (
    <Box bg="ui.surface" borderWidth="1px" borderColor="ui.border" borderRadius="forge"
      boxShadow="sm" px={{ base: '5', md: '6' }} py={{ base: '5', md: '6' }} {...rest}>
      {children}
    </Box>
  )
}

export const TextInput = forwardRef(function TextInput({ type = 'text', ...rest }, ref) {
  return (
    <Input ref={ref} type={type} bg="ui.sunk" borderWidth="1px" borderColor="ui.border" borderRadius="forge"
      color="ui.text"
      _focus={{ bg: 'ui.surface', borderColor: 'ui.primary', boxShadow: '0 0 0 3px var(--chakra-colors-ui\\.ring)' }}
      {...rest} />
  )
})

// ---------------------------------------------------------------------------
// Layout primitives for a Blocksy-grade settings panel. All of these are
// props-only and self-contained (no panel/registry imports), so this whole file
// is copied verbatim into easy-svg-pro's src/ui.jsx and stays byte-identical.
// They render NO literal user-facing strings: every label/title/description is
// passed in, so the free and pro tabs localise their own copy.
// ---------------------------------------------------------------------------

// One setting on its own row. `label` is a real <label htmlFor={control's id}>
// for a11y; pass the same id to the control. `description` is the helper line
// under it. `control` is the input on the right (inline) or full-width (stack).
// Every row draws its own bottom divider, so a column of FieldRows reads as a
// list inside a single Section/Card.
// Field.Root, not a bare label and a Text: the description was not announced.
// The label was wired by hand and read correctly, but nothing pointed the
// control at its explanation, so a screen reader said "Allow SVG uploads,
// switch" and stopped. Field.HelperText owns an id and Field.Root hands it to
// the control as aria-describedby, which is the whole reason to use it here.
export function FieldRow({ label, description, control, layout = 'inline', htmlFor }) {
  const head = (
    <VStack align="start" gap="1">
      <Field.Label
        htmlFor={htmlFor}
        fontSize="14px"
        fontWeight="700"
        color="ui.heading"
        cursor={htmlFor ? 'pointer' : undefined}
      >
        {label}
      </Field.Label>
      {description ? (
        <Field.HelperText fontSize="13px" fontWeight="400" lineHeight="1.5" color="ui.muted" maxW="52ch" mt="0">
          {description}
        </Field.HelperText>
      ) : null}
    </VStack>
  )

  if (layout === 'stack') {
    return (
      <Field.Root py="4" borderBottomWidth="1px" borderColor="ui.border" gap="0">
        <Box mb="3">{head}</Box>
        {/* Full width: Field.Root does not stretch its children, so a bare Box
            shrinks to the control's content and a full-width control lies. */}
        <Box w="full">{control}</Box>
      </Field.Root>
    )
  }

  // The flex props, not orientation="horizontal": that variant lays the helper
  // text out under the whole row, which moved the description out from under its
  // own label. This keeps the row as it was and changes only what the control is
  // told about itself.
  return (
    <Field.Root
      display="flex"
      flexDirection="row"
      alignItems="center"
      justifyContent="space-between"
      gap="6"
      py="4"
      borderBottomWidth="1px"
      borderColor="ui.border"
    >
      {head}
      <Box flexShrink="0">{control}</Box>
    </Field.Root>
  )
}

// A titled group of settings in ONE Card, replacing a stack of
// identical cards. Put FieldRows (or anything) as children; their dividers do
// the visual separation.
export function Section({ title, description, children }) {
  return (
    <Card>
      <Box mb="4">
        <Heading as="h2" fontSize="16px" fontWeight="700" color="ui.heading">
          {title}
        </Heading>
        {description ? (
          <Text fontSize="13px" fontWeight="400" lineHeight="1.5" color="ui.muted" mt="1">
            {description}
          </Text>
        ) : null}
      </Box>
      {children}
    </Card>
  )
}

// A sticky action bar for a settings pane: status on the left (announced to
// screen readers), Reset + Save on the right. Props-only and context-free, so a
// pro tab in its own ChakraProvider can render it. `dirty` drives everything:
// Save is disabled when clean or while `saving`, Reset only shows when dirty.
export function SaveBar({
  dirty,
  saving,
  onSave,
  onReset,
  dirtyLabel,
  savedLabel,
  saveLabel,
  savingLabel,
  resetLabel,
}) {
  return (
    <Box
      position="sticky"
      bottom="0"
      zIndex="10"
      borderTopWidth="1px"
      borderColor="ui.border"
      bg="ui.surface"
      borderBottomRadius="forge"
      py="3"
      px={{ base: '4', md: '6' }}
      boxShadow="0 -1px 2px rgba(32,49,89,.06)"
    >
      <HStack justify="space-between" gap="4">
        <Text as="span" role="status" aria-live="polite" fontSize="sm" color={dirty ? 'ui.text' : 'ui.muted'}>
          {dirty ? dirtyLabel : savedLabel}
        </Text>
        <HStack gap="3">
          {dirty && onReset ? (
            <Button variant="ghost" size="sm" onClick={onReset}>
              {resetLabel}
            </Button>
          ) : null}
          <Button variant="primary" size="sm" onClick={onSave} disabled={!dirty || saving}>
            {saving ? savingLabel : saveLabel}
          </Button>
        </HStack>
      </HStack>
    </Box>
  )
}

// Reference/Object.is equality one level deep. Note: 2 !== '2', so normalise
// value types (number vs string from inputs) before trusting the result.
export function shallowEqual(a, b) {
  if (Object.is(a, b)) {
    return true
  }
  if (typeof a !== 'object' || a === null || typeof b !== 'object' || b === null) {
    return false
  }
  const ak = Object.keys(a)
  const bk = Object.keys(b)
  if (ak.length !== bk.length) {
    return false
  }
  for (const k of ak) {
    if (!Object.prototype.hasOwnProperty.call(b, k) || !Object.is(a[k], b[k])) {
      return false
    }
  }
  return true
}

// Dirty tracking without extra React state. Pattern: on load, stash the server
// payload in a ref (`const snap = useRef(null)`), then
// `const { dirty, markSaved } = useDirty(form, snap)`. `dirty` is true when the
// live form differs from the snapshot; call `markSaved(serverResponse)` after a
// successful save to re-baseline (omit the arg to baseline the current value).
export function useDirty(current, snapshotRef) {
  const dirty = !shallowEqual(current, snapshotRef.current)
  const markSaved = (saved) => {
    snapshotRef.current = saved === undefined ? current : saved
  }
  return { dirty, markSaved }
}

// A drop target. The caller owns the look, because free's icon grid and pro's
// set editor do not resemble each other; this only owns the behaviour.
//
// The drop stops propagation so useDropGuard below never sees a drop a zone
// already took. dragleave fires on every child boundary as well, so the related
// target decides whether the pointer really left the region.
export function useDropZone({ onFiles, disabled }) {
  const [isOver, setOver] = useState(false)
  if (disabled) {
    return { isOver: false, props: {} }
  }
  return {
    isOver,
    props: {
      onDragEnter: (e) => {
        e.preventDefault()
        setOver(true)
      },
      onDragOver: (e) => {
        e.preventDefault()
        if (e.dataTransfer) {
          e.dataTransfer.dropEffect = 'copy'
        }
        setOver(true)
      },
      onDragLeave: (e) => {
        if (!e.currentTarget.contains(e.relatedTarget)) {
          setOver(false)
        }
      },
      onDrop: (e) => {
        e.preventDefault()
        e.stopPropagation()
        setOver(false)
        onFiles(e.dataTransfer && e.dataTransfer.files)
      },
    },
  }
}

// A file dropped next to a zone used to take the whole screen with it: nothing
// here called preventDefault, so the browser did what it always does with a
// dropped file -- open it, discarding the admin page and anything unsaved on
// it. This swallows the ones no zone took and tells the reader where they go.
//
// A drop something else already handled is left alone. The media frame's own
// uploader prevents its drops, and complaining over a file that landed exactly
// where the person aimed it would be worse than saying nothing.
export function useDropGuard(onMiss) {
  const miss = useRef(onMiss)
  miss.current = onMiss
  useEffect(() => {
    const over = (e) => {
      if (!e.defaultPrevented) {
        e.preventDefault()
      }
    }
    const drop = (e) => {
      if (e.defaultPrevented) {
        return
      }
      e.preventDefault()
      if (miss.current) {
        miss.current()
      }
    }
    window.addEventListener('dragover', over)
    window.addEventListener('drop', drop)
    return () => {
      window.removeEventListener('dragover', over)
      window.removeEventListener('drop', drop)
    }
  }, [])
}

// Centred empty state: optional icon, a title, a muted line, an optional action
// (pass a <Button>). All copy comes in as props.
export function EmptyState({ icon, title, description, action, contentProps, ...rest }) {
  return (
    <CEmptyState.Root {...rest}>
      <CEmptyState.Content {...contentProps}>
        {icon ? <CEmptyState.Indicator>{icon}</CEmptyState.Indicator> : null}
        <VStack textAlign="center" gap="1">
          <CEmptyState.Title fontSize="16px" fontWeight="700" color="ui.heading">
            {title}
          </CEmptyState.Title>
          {description ? (
            <CEmptyState.Description fontSize="13px" lineHeight="1.5" color="ui.muted">{description}</CEmptyState.Description>
          ) : null}
        </VStack>
        {action ? <Box pt="2">{action}</Box> : null}
      </CEmptyState.Content>
    </CEmptyState.Root>
  )
}

// Loading placeholder shaped like a column of FieldRows, so a pane keeps its
// height and nothing jumps when the data arrives.
export function SkeletonRows({ rows = 4 }) {
  return (
    <Box>
      {Array.from({ length: rows }).map((_, i) => (
        <Flex
          key={i}
          align="center"
          justify="space-between"
          gap="6"
          py="4"
          borderBottomWidth="1px"
          borderColor="ui.border"
        >
          <Box flex="1">
            <Skeleton height="14px" width="38%" mb="2" />
            <SkeletonText noOfLines={1} width="68%" />
          </Box>
          <Skeleton height="32px" width="110px" borderRadius="forge" flexShrink="0" />
        </Flex>
      ))}
    </Box>
  )
}

// Small brand badge, the designed replacement for the literal ' 🔒' on pro
// tabs. The text (e.g. "Pro") is passed in so the tabs localise it.
//
// fontWeight is set explicitly: Chakra's badge recipe bases it on `medium`
// (500), which it emits inside `@layer recipes`, and Ubuntu ships only 400 and
// 700, so the default renders as a faux weight the browser synthesises.
export function ProBadge({ children, ...rest }) {
  return (
    <Badge colorPalette="brand" variant="solid" size="sm" fontWeight="700" {...rest}>
      {children}
    </Badge>
  )
}

// `bad` and `role` are the API both plugins' tabs already call this with.
// aria-live repeats what the role implies, because a notice inserted after the
// first paint is announced only when the region says how.
export function Notice({ bad, role, status, title, children, ...rest }) {
  const live = role === 'alert' ? 'assertive' : role === 'status' ? 'polite' : undefined
  const resolved = status || (bad ? 'error' : 'info')
  return (
    <Alert.Root
      role={role}
      aria-live={live}
      status={resolved}
      variant="subtle"
      // Brand navy for a plain notice: Chakra's info palette is its own blue,
      // and this panel has exactly one blue.
      colorPalette={'info' === resolved ? 'brand' : undefined}
      borderWidth="1px"
      borderStartWidth="3px"
      borderStartColor="colorPalette.solid"
      borderColor="ui.border"
      borderRadius="forge"
      alignItems={title ? 'flex-start' : 'center'}
      fontSize="14px"
      {...rest}
    >
      <Alert.Indicator />
      {title ? (
        <Alert.Content>
          <Alert.Title>{title}</Alert.Title>
          <Alert.Description>{children}</Alert.Description>
        </Alert.Content>
      ) : (
        <Alert.Title flex="1" fontWeight="400">{children}</Alert.Title>
      )}
    </Alert.Root>
  )
}

// One label serves as the accessible name and the tooltip, so the two cannot
// drift apart. Not portalled: the panel's styles are scoped to its subtree, and
// a tooltip in document.body would render in wp-admin's face.
export function IconAction({ label, danger, children, ...rest }) {
  return (
    <CTooltip.Root openDelay={250} closeDelay={80} positioning={{ placement: 'top' }}>
      <CTooltip.Trigger asChild>
        <CIconButton
          aria-label={label}
          variant="ghost"
          size="sm"
          borderRadius="forge"
          color="ui.muted"
          _hover={danger ? { color: 'ui.bad', bg: 'ui.badBg' } : { color: 'ui.primary', bg: 'ui.ghostHover' }}
          _focusVisible={{ outline: '2px solid', outlineColor: 'ui.primary', outlineOffset: '2px' }}
          {...rest}
        >
          {children}
        </CIconButton>
      </CTooltip.Trigger>
      <CTooltip.Positioner>
        <CTooltip.Content
          bg="ui.heading"
          color="ui.surface"
          fontSize="12px"
          fontWeight="400"
          px="2"
          py="1"
          borderRadius="forge"
        >
          <CTooltip.Arrow>
            <CTooltip.ArrowTip />
          </CTooltip.Arrow>
          {label}
        </CTooltip.Content>
      </CTooltip.Positioner>
    </CTooltip.Root>
  )
}

// The label is real but visually hidden: a visible one would repeat the
// placeholder, and a bare input says nothing to a screen reader. type="search"
// for the browser's own clear affordance and the Escape key.
export function SearchField({ id, label, placeholder, value, onChange, maxW = '300px' }) {
  return (
    <Box flex="1" maxW={maxW} minW="160px">
      <VisuallyHidden as="label" htmlFor={id}>{label}</VisuallyHidden>
      {/* The magnifier says what the field is for before the placeholder is
          read, and it is decorative: the label above names it. */}
      <InputGroup
        startElement={
          <Box as="svg" aria-hidden="true" viewBox="0 0 24 24" boxSize="16px" fill="none"
            stroke="currentColor" strokeWidth="2" strokeLinecap="round" color="ui.muted">
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
          </Box>
        }
      >
        {/* The start element sits ON the field, so without room for it the
            magnifier lands on the first letters of the placeholder. ps is not
            enough on its own: the panel's own unlayered rule has to beat
            wp-admin's forms.css and sets one padding for every field, so the
            attribute is what that rule aims at. */}
        <TextInput
          id={id}
          type="search"
          ps="9"
          data-esw-search="true"
          placeholder={placeholder}
          value={value}
          onChange={onChange}
        />
      </InputGroup>
    </Box>
  )
}
