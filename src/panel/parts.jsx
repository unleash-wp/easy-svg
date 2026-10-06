import React from 'react'
import { Box, HStack, Stack, Spinner, Text, Heading } from '@chakra-ui/react'
import { Button, Card } from '../ui.jsx'

export function Notice({ bad, children }) {
  return (
    <Box
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

export function Loading() {
  return (
    <HStack color="ui.muted" gap="3" py="4">
      <Spinner size="sm" />
      <Text fontSize="sm">Lädt…</Text>
    </HStack>
  )
}

// A Pro tab a free (or unlicensed) site sees: the teaser + a call to action.
export function LockedTab({ tab, proActive }) {
  return (
    <Card maxW="640px">
      <Stack gap="3">
        <HStack gap="2" align="center">
          <Text fontSize="xl" aria-hidden>🔒</Text>
          <Heading as="h2" size="md" color="ui.text">{tab.label}</Heading>
        </HStack>
        <Text color="ui.muted" fontSize="sm">{tab.teaser}</Text>
        <Box pt="1">
          <Button
            variant="accent"
            onClick={() => window.open('https://unleash-wp.com/products/easy-svg-pro/', '_blank', 'noopener')}
          >
            {proActive ? 'Lizenz aktivieren' : 'Easy SVG Pro holen'}
          </Button>
        </Box>
      </Stack>
    </Card>
  )
}
