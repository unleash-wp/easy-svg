import { Stack, HStack, Text, Box } from '@chakra-ui/react'
import { __ } from '@wordpress/i18n'
import { Section, Button } from '../../ui.jsx'

export default function Upsell({ proTabs = [] }) {
  return (
    <Stack gap="5" maxW="720px">
      <Section
        title={__('Easy SVG Pro', 'easy-svg')}
        description={__('The agency layer for SVG icons — all right here in the panel, once Pro is active and licensed.', 'easy-svg')}
      >
        <Stack gap="4" pt="1">
          {proTabs.map((t) => (
            <HStack key={t.id} gap="3" align="start">
              <Text color="ui.primary" fontWeight="700" aria-hidden>▸</Text>
              <Box>
                <Text textStyle="fieldLabel">{t.label}</Text>
                <Text textStyle="description" maxW="52ch">{t.teaser}</Text>
              </Box>
            </HStack>
          ))}
        </Stack>
        <Box pt="5">
          <Button
            variant="accent"
            onClick={() => window.open('https://unleash-wp.com/products/easy-svg-pro/', '_blank', 'noopener')}
          >
            {__('Get Easy SVG Pro', 'easy-svg')}
          </Button>
        </Box>
      </Section>
    </Stack>
  )
}
