import React from 'react'
import { Stack, HStack, Text, Heading, Box } from '@chakra-ui/react'
import { Button, Card } from '../../ui.jsx'

export default function Upsell({ proTabs = [] }) {
  return (
    <Stack gap="5" maxW="720px">
      <Card>
        <Stack gap="3">
          <Heading as="h2" size="md" color="ui.text">Easy SVG Pro</Heading>
          <Text color="ui.muted" fontSize="sm">
            Die Agentur-Schicht für SVG-Icons — alles direkt hier im Panel, sobald Pro aktiv und lizenziert ist.
          </Text>
          <Stack gap="3" pt="1">
            {proTabs.map((t) => (
              <HStack key={t.id} gap="3" align="start">
                <Text color="ui.primary" fontWeight="700" aria-hidden>▸</Text>
                <Box>
                  <Text fontWeight="600" color="ui.text" fontSize="sm">{t.label}</Text>
                  <Text color="ui.muted" fontSize="sm">{t.teaser}</Text>
                </Box>
              </HStack>
            ))}
          </Stack>
          <Box pt="2">
            <Button variant="accent" onClick={() => window.open('https://unleash-wp.com/products/easy-svg-pro/', '_blank', 'noopener')}>
              Easy SVG Pro holen
            </Button>
          </Box>
        </Stack>
      </Card>
    </Stack>
  )
}
