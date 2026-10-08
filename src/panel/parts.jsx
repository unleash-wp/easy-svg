import { Box, HStack, Stack, Text, Heading } from '@chakra-ui/react'
import { __ } from '@wordpress/i18n'
import { Button, Card, ProBadge } from '../ui.jsx'

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
