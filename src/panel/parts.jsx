import { Box, HStack, Stack, Text, Heading } from '@chakra-ui/react'
import { __ } from '@wordpress/i18n'
import { Button, Card, ProBadge } from '../ui.jsx'

// The notice lives in the shared layer now, so both plugins' tabs get the same
// Alert -- with its status icon and colours -- from one definition.
export { Notice } from '../ui.jsx'

// The search field lives in the shared layer; both plugins' tabs use it.
export { SearchField } from '../ui.jsx'

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
            variant="primary"
            onClick={() =>
              canActivate
                ? onActivate()
                : window.open('https://unleash-wp.com/products/easy-svg-pro/', '_blank', 'noopener')
            }
          >
            {canActivate ? __('Activate license', 'easy-svg') : __('Get Easy SVG Pro', 'easy-svg')}
          </Button>
        </Box>
      </Stack>
    </Card>
  )
}
