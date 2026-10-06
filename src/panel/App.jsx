import React, { useEffect, useState } from 'react'
import { Box, HStack, Heading, Text, Tabs, Image } from '@chakra-ui/react'
import { getRegistry } from './registry.js'
import Settings from './tabs/Settings.jsx'
import Library from './tabs/Library.jsx'
import Upsell from './tabs/Upsell.jsx'
import { LockedTab } from './parts.jsx'

// Free's own tabs, always functional.
const FREE_TABS = [
  { id: 'settings', label: 'Einstellungen', order: 10, render: (ctx) => <Settings ctx={ctx} /> },
  { id: 'library', label: 'Icon-Library', order: 20, render: (ctx) => <Library ctx={ctx} /> },
]

// The Pro catalogue. Free draws these as locked upsell tabs; when Pro is active
// AND licensed it registers real render()s for these ids through window.EasySvgPanel.
const PRO_TABS = [
  { id: 'sets', label: 'Icon Sets', order: 30, teaser: 'Bündele SVGs zu Collections und rendere sie per Icon-Block oder easy_svg_icon().' },
  { id: 'configurator', label: 'Configurator', order: 40, teaser: 'Erweitere die Sanitizer-Allow-Liste für spezielle Icon-Bibliotheken — mit Guard-Rails.' },
  { id: 'audit', label: 'Audit', order: 50, teaser: 'Finde und säubere SVGs, die nicht über den Uploader kamen.' },
  { id: 'licence', label: 'Lizenz', order: 60, teaser: 'Lizenzschlüssel eingeben und Pro-Features freischalten.' },
]

const UPSELL_TAB = { id: 'upsell', label: 'Pro', order: 70, render: (ctx) => <Upsell ctx={ctx} proTabs={PRO_TABS} /> }

export default function App({ config }) {
  const [, force] = useState(0)
  useEffect(() => {
    const reg = getRegistry()
    if (!reg) return undefined
    return reg.subscribe(() => force((n) => n + 1))
  }, [])

  const reg = getRegistry()
  const ctx = {
    restRoot: config.restRoot,
    nonce: config.nonce,
    proActive: !!config.proActive,
    proLicensed: !!config.proLicensed,
  }

  const tabs = [
    ...FREE_TABS,
    ...PRO_TABS.map((t) => {
      const got = reg && reg.get(t.id)
      const unlocked = got && config.proLicensed
      return {
        ...t,
        locked: !unlocked,
        render: unlocked ? (c) => got.render(c) : () => <LockedTab tab={t} proActive={config.proActive} />,
      }
    }),
    UPSELL_TAB,
  ].sort((a, b) => a.order - b.order)

  return (
    <Box maxW="1100px" pr={{ base: '3', md: '6' }} pb="10">
      <HStack gap="3" align="center" mb="1" mt="2">
        {config.icon ? <Image src={config.icon} alt="" boxSize="28px" borderRadius="forge" /> : null}
        <Heading as="h1" size="xl" color="ui.text" fontWeight="700" letterSpacing="-0.01em">Icons</Heading>
      </HStack>
      <Text color="ui.muted" mb="5" fontSize="sm">
        SVG-Uploads, Icon-Library und — mit Pro — Collections, Configurator, Audit und Lizenz, alles an einem Ort.
      </Text>

      <Tabs.Root defaultValue={tabs[0]?.id} variant="line" colorPalette="brand">
        <Tabs.List borderColor="ui.border" flexWrap="wrap">
          {tabs.map((t) => (
            <Tabs.Trigger key={t.id} value={t.id} fontWeight="500" color={t.locked ? 'ui.muted' : undefined}>
              {t.label}{t.locked ? ' 🔒' : ''}
            </Tabs.Trigger>
          ))}
        </Tabs.List>
        <Box pt="5">
          {tabs.map((t) => (
            <Tabs.Content key={t.id} value={t.id}>{t.render(ctx)}</Tabs.Content>
          ))}
        </Box>
      </Tabs.Root>
    </Box>
  )
}
