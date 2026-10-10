import { Box, HStack, Stack, Text, Heading, VisuallyHidden } from '@chakra-ui/react'
import { __ } from '@wordpress/i18n'
import { Button, Card, ProBadge, TextInput } from '../ui.jsx'

// A single-line message panel. `bad` paints the error colours; `role` opts the
// box into a live region so assistive tech announces it when it mounts — pass
// "alert" for an error and "status" for a confirmation. role=alert implies an
// assertive live region and role=status a polite one; we set aria-live to match
// so late-inserted notices are still announced.
export function Notice({ bad, role, children }) {
  const live = role === 'alert' ? 'assertive' : role === 'status' ? 'polite' : undefined
  return (
    <Box
      role={role}
      aria-live={live}
      borderWidth="1px"
      borderLeftWidth="4px"
      borderRadius="forge"
      px="4"
      py="3"
      borderColor={bad ? 'ui.bad' : 'ui.border'}
      borderLeftColor={bad ? 'ui.bad' : 'ui.primary'}
      bg="ui.surface"
      color="ui.text"
      fontSize="sm"
    >
      {children}
    </Box>
  )
}

// A filter field that sits directly above the thing it filters. The label is a
// real <label htmlFor>, hidden visually only: a visible one would be a second
// line saying what the placeholder already says, and a bare input says nothing
// at all to a screen reader. type="search" so the browser offers its own clear
// affordance and the Escape key empties the field.
export function SearchField({ id, label, placeholder, value, onChange, maxW = '300px' }) {
  return (
    <Box flex="1" maxW={maxW} minW="160px">
      <VisuallyHidden as="label" htmlFor={id}>{label}</VisuallyHidden>
      <TextInput id={id} type="search" placeholder={placeholder} value={value} onChange={onChange} />
    </Box>
  )
}

// The bar that appears once a selection exists: how many are picked and the one
// thing that can be done to them. Navy and full width, so it reads as a mode the
// screen is in rather than as another row of the card it sits in. The count is
// announced, because the thing that changed it (a click on a tile far away) does
// not announce itself.
export function BulkBar({ count, label, actionLabel, onAction, busy }) {
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
        accent, not ghost: the ghost variant draws navy text on a navy border,
        which on this bar is navy on navy. Yellow on navy is the only pairing in
        the system that reads here, and the bar is a mode with one action in it.
      */}
      <HStack gap="2">
        <Button
          size="sm"
          variant="accent"
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
// refused and it is not the browser, so nothing here phrases a refusal — it only
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
// The title and teaser come localised from the tab definition; the ProBadge is
// the designed replacement for the old ' 🔒' suffix.
export function LockedTab({ tab, proActive }) {
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
            onClick={() => window.open('https://unleash-wp.com/products/easy-svg-pro/', '_blank', 'noopener')}
          >
            {proActive ? __('Activate licence', 'easy-svg') : __('Get Easy SVG Pro', 'easy-svg')}
          </Button>
        </Box>
      </Stack>
    </Card>
  )
}
