import { Box, HStack, InputGroup, Stack, Text, Heading, VisuallyHidden } from '@chakra-ui/react'
import { __ } from '@wordpress/i18n'
import { Button, Card, ProBadge, TextInput } from '../ui.jsx'

// The notice lives in the shared layer now, so both plugins' tabs get the same
// Alert -- with its status icon and colours -- from one definition.
export { Notice } from '../ui.jsx'

// A filter field that sits directly above the thing it filters. The label is a
// real <label htmlFor>, hidden visually only: a visible one would be a second
// line saying what the placeholder already says, and a bare input says nothing
// at all to a screen reader. type="search" so the browser offers its own clear
// affordance and the Escape key empties the field.
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
        {/* ps: the start element sits ON the field, so without room for it the
            magnifier lands on the first letters of the placeholder. */}
        <TextInput id={id} type="search" ps="9" placeholder={placeholder} value={value} onChange={onChange} />
      </InputGroup>
    </Box>
  )
}

// The bar that appears once a selection exists: how many are picked and the one
// thing that can be done to them. Navy and full width, so it reads as a mode the
// screen is in rather than as another row of the card it sits in. The count is
// announced, because the thing that changed it (a click on a tile far away) does
// not announce itself.
export function BulkBar({ count, label, actionLabel, onAction, busy, cancelLabel, onCancel }) {
  return (
    <HStack
      bg="navy"
      color="white"
      borderRadius="forge"
      px="4"
      py="2.5"
      gap="3"
      justify="space-between"
      boxShadow="sm"
    >
      <Text as="span" role="status" aria-live="polite" fontSize="sm" fontWeight="700">
        {label}
      </Text>
      {/*
        Red, because the one action here removes things for good. Yellow is the
        brand accent and must not double as the destructive colour.
      */}
      <HStack gap="2">
        {cancelLabel ? (
          // White on navy, borderless: the ghost variant's navy-on-navy is
          // unreadable here, and the accent button must stay the only loud one.
          <Button
            size="sm"
            variant="ghost"
            color="white"
            borderColor="whiteAlpha.500"
            _hover={{ color: 'white', borderColor: 'white', bg: 'whiteAlpha.200' }}
            onClick={onCancel}
            disabled={busy}
          >
            {cancelLabel}
          </Button>
        ) : null}
        <Button
          size="sm"
          variant="primary"
          bg="ui.bad"
          _hover={{ bg: 'ui.bad', opacity: 0.9 }}
          _disabled={{ opacity: 0.55, bg: 'ui.bad', color: 'white', cursor: 'default' }}
          onClick={onAction}
          disabled={busy || !count}
        >
          {actionLabel}
        </Button>
      </HStack>
    </HStack>
  )
}

// What came of a batch: one row per file, and the verdict in each row is the
// SERVER's own sentence. There is exactly one place that decides why an icon was
// refused and it is not the browser, so nothing here phrases a refusal; it only
// puts the sentence next to the file that provoked it.
//
// role="status" on the box: the batch finished somewhere else on the screen (a
// drop onto the grid), so without a live region the outcome is silent.
export function ReportList({ summary, rows, onDismiss, dismissLabel }) {
  return (
    <Box
      role="status"
      aria-live="polite"
      borderWidth="1px"
      borderColor="ui.border"
      borderRadius="forge"
      bg="ui.sunk"
      px="4"
      py="3"
    >
      <HStack justify="space-between" gap="3" align="start">
        <Text fontSize="sm" fontWeight="700" color="ui.heading">{summary}</Text>
        {onDismiss ? (
          <Button size="sm" variant="ghost" onClick={onDismiss} aria-label={dismissLabel} px="2" minW="0">
            <Box as="span" aria-hidden="true" fontSize="md" lineHeight="1">×</Box>
          </Button>
        ) : null}
      </HStack>
      {/* Forty files produce forty rows; bounded, so the report never pushes the
          thing it is reporting on off the screen. */}
      <Stack as="ul" gap="1" mt="2" listStyleType="none" maxH="180px" overflowY="auto">
        {rows.map((row, i) => (
          <HStack as="li" key={`${row.file}-${i}`} gap="2" align="baseline" fontSize="13px" flexWrap="wrap">
            <Text as="span" fontFamily="mono" color="ui.text" truncate maxW="22ch">{row.file}</Text>
            {/* Decoration: the verdict is the sentence beside it, so the mark is
                not read out a second time as "check mark". */}
            <Text as="span" aria-hidden="true" color={row.ok ? 'ui.goodInk' : 'ui.badInk'} fontWeight="700" flexShrink="0">
              {row.ok ? '✓' : '✕'}
            </Text>
            <Text as="span" color="ui.muted">{row.message}</Text>
          </HStack>
        ))}
      </Stack>
    </Box>
  )
}

// A Pro tab a free (or unlicensed) site sees: the teaser + a call to action.
// Title and teaser come localised from the tab definition.
//
// `onActivate` is what the button does when Pro is already INSTALLED and only
// waiting for a key -- sending that person to the shop instead of to the field
// their key goes in is the bug this avoids. App.jsx passes the callback only
// when a licence tab is there to switch to; without one the sales page is the
// honest answer.
export function LockedTab({ tab, proActive, onActivate }) {
  const canActivate = proActive && 'function' === typeof onActivate
  return (
    <Card maxW="640px">
      <Stack gap="4">
        <HStack gap="3" align="center">
          <Heading as="h2" textStyle="sectionTitle">{tab.label}</Heading>
          <ProBadge>{__('Pro', 'easy-svg')}</ProBadge>
        </HStack>
        <Text textStyle="description" maxW="52ch">{tab.teaser}</Text>
        <Box pt="1">
          <Button
            variant="accent"
            onClick={() =>
              canActivate
                ? onActivate()
                : window.open('https://unleash-wp.com/products/easy-svg-pro/', '_blank', 'noopener')
            }
          >
            {canActivate ? __('Activate licence', 'easy-svg') : __('Get Easy SVG Pro', 'easy-svg')}
          </Button>
        </Box>
      </Stack>
    </Card>
  )
}
