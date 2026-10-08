import React, { useEffect, useState } from 'react'
import { Box, Flex, HStack, Heading, Text, Tabs, Image, useBreakpointValue } from '@chakra-ui/react'
import { __ } from '@wordpress/i18n'
import { getRegistry } from './registry.js'
import Settings from './tabs/Settings.jsx'
import Library from './tabs/Library.jsx'
import Upsell from './tabs/Upsell.jsx'
import { LockedTab } from './parts.jsx'
import { ProBadge } from '../ui.jsx'

// Free's own tabs, always functional. English msgids, domain 'easy-svg'; the
// German (and every other) locale ships as a JS translation file.
const FREE_TABS = [
  { id: 'settings', group: 'general', order: 10, label: __('Settings', 'easy-svg'), render: (ctx) => <Settings ctx={ctx} /> },
  { id: 'library', group: 'general', order: 20, label: __('Icon library', 'easy-svg'), render: (ctx) => <Library ctx={ctx} /> },
]

// The Pro catalogue. Free draws these as locked upsell tabs; when Pro is active
// AND licensed it registers real render()s for these ids through window.EasySvgPanel.
const PRO_TABS = [
  { id: 'sets', group: 'pro', order: 30, label: __('Icon Sets', 'easy-svg'), teaser: __('Bundle SVGs into collections and render them with the Icon block or easy_svg_icon().', 'easy-svg') },
  { id: 'configurator', group: 'pro', order: 40, label: __('Configurator', 'easy-svg'), teaser: __('Extend the sanitiser allow-list for special icon libraries — with guard-rails.', 'easy-svg') },
  { id: 'audit', group: 'pro', order: 50, label: __('Audit', 'easy-svg'), teaser: __('Find and clean up SVGs that did not come through the uploader.', 'easy-svg') },
  { id: 'licence', group: 'pro', order: 60, label: __('Licence', 'easy-svg'), teaser: __('Enter your licence key and unlock the Pro features.', 'easy-svg') },
]

const UPSELL_TAB = { id: 'upsell', group: 'pro', order: 70, label: __('Pro', 'easy-svg'), render: (ctx) => <Upsell ctx={ctx} proTabs={PRO_TABS} /> }

export default function App({ config }) {
  const [, force] = useState(0)
  const [active, setActive] = useState('')
  useEffect(() => {
    const reg = getRegistry()
    if (!reg) return undefined
    const unsub = reg.subscribe(() => force((n) => n + 1))
    // Pro may have registered a tab between this component's first render and
    // this effect running — that notify had no listener yet and was lost. Read
    // the registry once more now so a registration caught in that race shows.
    force((n) => n + 1)
    return unsub
  }, [])

  const reg = getRegistry()
  const ctx = {
    restRoot: config.restRoot,
    nonce: config.nonce,
    proActive: !!config.proActive,
    proLicensed: !!config.proLicensed,
    iconsEnabled: !!config.iconsEnabled,
  }

  // Tabs Pro registered that are NOT in the catalogue (e.g. Features): shown only
  // when Pro is active and licensed, using the label/order it passed.
  const knownIds = new Set([...FREE_TABS, ...PRO_TABS, UPSELL_TAB].map((t) => t.id))
  const extras = (reg && config.proLicensed ? reg.list() : [])
    .filter((t) => !knownIds.has(t.id))
    .map((t) => ({ id: t.id, group: 'pro', label: t.label || t.id, order: t.order ?? 65, locked: false, render: (c) => t.render(c) }))

  const tabs = [
    ...FREE_TABS,
    ...PRO_TABS.map((t) => {
      const got = reg && reg.get(t.id)
      // The licence tab is how a site activates, so a registered real one is
      // always usable — otherwise you could never enter a key to unlock the
      // rest. Every other pro tab stays locked until the licence is in (and the
      // pro REST refuses it server-side regardless of what the client shows).
      const unlocked = got && (t.id === 'licence' || config.proLicensed)
      return {
        ...t,
        locked: !unlocked,
        render: unlocked ? (c) => got.render(c) : () => <LockedTab tab={t} proActive={config.proActive} />,
      }
    }),
    ...extras,
    UPSELL_TAB,
  ].sort((a, b) => a.order - b.order)

  // zag reads `orientation` as a concrete string (it drives arrow-key direction
  // and the data-orientation styling), so resolve the responsive intent to a
  // value rather than handing it an object: a horizontal strip on phones, a
  // vertical left rail from md up.
  const orientation = useBreakpointValue({ base: 'horizontal', md: 'vertical' }) ?? 'horizontal'
  const groupLabels = { general: __('General', 'easy-svg'), pro: __('Pro', 'easy-svg') }
  // Controlled selection drives a lazy-mount / unmount-on-exit render: only the
  // active tab's content is in the DOM. (ark's own lazyMount/unmountOnExit props
  // leak onto the DOM through Chakra's styled wrapper, so this gates it instead.)
  const activeValue = active || tabs[0]?.id

  // Flatten the rail into triggers with a non-interactive group eyebrow before
  // each new group. The eyebrow is aria-hidden (the tablist stays a clean list
  // of tabs for assistive tech) and shows only in the vertical rail.
  const rail = []
  let lastGroup = null
  tabs.forEach((t) => {
    if (t.group !== lastGroup) {
      rail.push(
        <Box
          key={`eyebrow-${t.group}`}
          aria-hidden="true"
          display={{ base: 'none', md: 'block' }}
          textStyle="caption"
          textTransform="uppercase"
          letterSpacing="0.06em"
          color="ui.muted"
          px="3"
          pt={rail.length ? '4' : '1'}
          pb="1.5"
        >
          {groupLabels[t.group] || t.group}
        </Box>
      )
      lastGroup = t.group
    }
    rail.push(
      <Tabs.Trigger
        key={t.id}
        value={t.id}
        gap="2"
        fontWeight="400"
        color={t.locked ? 'ui.muted' : 'ui.text'}
        flexShrink="0"
        w={{ md: 'full' }}
        justifyContent={{ md: 'flex-start' }}
        _selected={{ fontWeight: '700', color: 'ui.heading' }}
      >
        <Text as="span" truncate>{t.label}</Text>
        {t.locked ? <ProBadge ml="auto">{groupLabels.pro}</ProBadge> : null}
      </Tabs.Trigger>
    )
  })

  return (
    <Box maxW="1100px" pr={{ base: '3', md: '6' }} pb="10">
      <HStack gap="3" align="center" mb="1" mt="2">
        {config.icon ? <Image src={config.icon} alt="" boxSize="28px" borderRadius="forge" /> : null}
        <Heading as="h1" textStyle="h1">{__('Icons', 'easy-svg')}</Heading>
      </HStack>
      <Text color="ui.muted" mb="5" fontSize="sm" maxW="72ch">
        {__('SVG uploads, the icon library and — with Pro — collections, configurator, audit and licence, all in one place.', 'easy-svg')}
      </Text>

      <Tabs.Root
        value={activeValue}
        onValueChange={(e) => setActive(e.value)}
        orientation={orientation}
        variant="line"
        colorPalette="brand"
      >
        <Flex direction={{ base: 'column', md: 'row' }} align="stretch" gap={{ base: '0', md: '6' }}>
          <Tabs.List
            borderColor="ui.border"
            flexWrap="nowrap"
            overflowX={{ base: 'auto', md: 'visible' }}
            gap="1"
            flexShrink="0"
            w={{ md: '240px' }}
            minW={{ md: '240px' }}
            py={{ md: '2' }}
            pr={{ md: '3' }}
            position={{ md: 'sticky' }}
            top={{ md: '42px' }}
            alignSelf={{ md: 'flex-start' }}
          >
            {rail}
          </Tabs.List>
          <Box
            flex="1"
            minW="0"
            bg="ui.bg"
            borderRadius={{ md: 'forge' }}
            px={{ base: '0', md: '6' }}
            py={{ base: '5', md: '6' }}
            mt={{ base: '5', md: '0' }}
          >
            {tabs.map((t) => (
              <Tabs.Content key={t.id} value={t.id} p="0">
                {activeValue === t.id ? t.render(ctx) : null}
              </Tabs.Content>
            ))}
          </Box>
        </Flex>
      </Tabs.Root>
    </Box>
  )
}
