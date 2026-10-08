import {
  Button as CButton,
  Input,
  Box,
  Flex,
  HStack,
  VStack,
  Text,
  Heading,
  Badge,
  Skeleton,
  SkeletonText,
  EmptyState as CEmptyState,
} from '@chakra-ui/react'

// The UnleashWP buttons, same rules as the account app:
// primary = navy, hover turns it yellow; accent = the one yellow call to action;
// ghost = outline. The "forge" radius and the 500/600 weights are theirs.
export function Button({ variant = 'ghost', size = 'md', danger, children, ...rest }) {
  const common = {
    size: size === 'sm' ? 'sm' : 'md',
    borderRadius: 'forge',
    fontWeight: '500',
    h: 'auto',
    py: size === 'sm' ? '2.5' : '3',
    _focusVisible: { outline: '2px solid', outlineColor: 'ui.primary', outlineOffset: '2px' },
    'data-variant': variant,
  }

  if (variant === 'accent') {
    return (
      <CButton {...common} bg="yellow" color="navy" fontWeight="600" boxShadow="sm"
        _hover={{ bg: '#e6ac00' }} _active={{ transform: 'translateY(1px)' }}
        _disabled={{ opacity: 0.55, bg: 'yellow', color: 'navy', cursor: 'default' }} {...rest}>
        {children}
      </CButton>
    )
  }

  if (variant === 'primary') {
    return (
      <CButton {...common} bg="navy" color="white" boxShadow="sm"
        _hover={{ bg: 'yellow', color: 'navy' }} _active={{ transform: 'translateY(1px)' }}
        _disabled={{ opacity: 0.55, bg: 'navy', color: 'white', cursor: 'default' }} {...rest}>
        {children}
      </CButton>
    )
  }

  return (
    <CButton {...common} variant="outline" color="ui.primary" borderColor="ui.border"
      _hover={danger ? { color: 'ui.bad', borderColor: 'ui.bad' } : { borderColor: 'ui.primary', bg: 'ui.ghostHover' }}
      _active={{ transform: 'translateY(1px)' }} {...rest}>
      {children}
    </CButton>
  )
}

export function Card({ children, ...rest }) {
  return (
    <Box bg="ui.surface" borderWidth="1px" borderColor="ui.border" borderRadius="forge"
      boxShadow="sm" px={{ base: '5', md: '6' }} py={{ base: '5', md: '6' }} {...rest}>
      {children}
    </Box>
  )
}

export function TextInput({ type = 'text', ...rest }) {
  return (
    <Input type={type} bg="ui.sunk" borderWidth="1px" borderColor="ui.border" borderRadius="forge"
      color="ui.text"
      _focus={{ bg: 'ui.surface', borderColor: 'ui.primary', boxShadow: '0 0 0 3px var(--chakra-colors-ui-ring)' }}
      {...rest} />
  )
}

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
export function FieldRow({ label, description, control, layout = 'inline', htmlFor }) {
  const head = (
    <VStack align="start" gap="1">
      <Text
        as="label"
        htmlFor={htmlFor}
        fontSize="14px"
        fontWeight="700"
        color="ui.heading"
        cursor={htmlFor ? 'pointer' : undefined}
      >
        {label}
      </Text>
      {description ? (
        <Text fontSize="13px" fontWeight="400" lineHeight="1.5" color="ui.muted" maxW="52ch">
          {description}
        </Text>
      ) : null}
    </VStack>
  )

  if (layout === 'stack') {
    return (
      <Box py="4" borderBottomWidth="1px" borderColor="ui.border">
        <Box mb="3">{head}</Box>
        <Box>{control}</Box>
      </Box>
    )
  }

  return (
    <Flex align="center" justify="space-between" gap="6" py="4" borderBottomWidth="1px" borderColor="ui.border">
      {head}
      <Box flexShrink="0">{control}</Box>
    </Flex>
  )
}

// A titled group of settings in ONE Card — the replacement for a stack of
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
// pro tab in its own ChakraProvider can render it. `dirty` drives everything —
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

// Centred empty state: optional icon, a title, a muted line, an optional action
// (pass a <Button>). All copy comes in as props.
export function EmptyState({ icon, title, description, action }) {
  return (
    <CEmptyState.Root>
      <CEmptyState.Content>
        {icon ? <CEmptyState.Indicator>{icon}</CEmptyState.Indicator> : null}
        <VStack textAlign="center" gap="1">
          <CEmptyState.Title fontSize="16px" fontWeight="700" color="ui.heading">
            {title}
          </CEmptyState.Title>
          {description ? (
            <CEmptyState.Description color="ui.muted">{description}</CEmptyState.Description>
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

// Small brand badge — the designed replacement for the literal ' 🔒' on pro
// tabs. The text (e.g. "Pro") is passed in so the tabs localise it.
export function ProBadge({ children, ...rest }) {
  return (
    <Badge colorPalette="brand" variant="subtle" size="sm" {...rest}>
      {children}
    </Badge>
  )
}
