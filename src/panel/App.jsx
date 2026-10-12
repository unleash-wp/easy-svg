import { useEffect, useState } from 'react'
import { Box, Flex, HStack, Heading, Text, Tabs, Image, Separator, chakra, useBreakpointValue } from '@chakra-ui/react'
import { __ } from '@wordpress/i18n'
import { getRegistry } from './registry.js'
import Settings from './tabs/Settings.jsx'
import Library from './tabs/Library.jsx'
import Upsell from './tabs/Upsell.jsx'
import { LockedTab } from './parts.jsx'
import { ProBadge, useDropGuard } from '../ui.jsx'
import { TabIcon, BurgerIcon } from './icons.jsx'
import logo from '../brand/unleashwp-logo-full.svg?raw'

// The XML prolog is legal in a file and illegal inside an inline <svg>.
const LOGO = logo.replace(/<\?xml[^?]*\?>/, '').trim()

// Free's own tabs, always functional. English msgids, domain 'easy-svg'; the
// German (and every other) locale ships as a JS translation file.
const FREE_TABS = [
  { id: 'settings', icon: 'settings', group: 'general', order: 10, label: __('Settings', 'easy-svg'), render: (ctx) => <Settings ctx={ctx} /> },
  { id: 'library', icon: 'library', group: 'general', order: 20, label: __('Icon library', 'easy-svg'), render: (ctx) => <Library ctx={ctx} /> },
]

// The Pro catalogue. Free draws these as locked upsell tabs; when Pro is active
// AND licensed it registers real render()s for these ids through window.EasySvgPanel.
const PRO_TABS = [
  { id: 'sets', icon: 'sets', group: 'pro', order: 30, label: __('Icon Sets', 'easy-svg'), teaser: __('Bundle SVGs into collections and render them with the Icon block or easy_svg_icon().', 'easy-svg') },
  { id: 'configurator', icon: 'configurator', group: 'pro', order: 40, label: __('Configurator', 'easy-svg'), teaser: __('Extend the sanitiser allow-list for special icon libraries. The guard-rails stay.', 'easy-svg') },
  { id: 'audit', icon: 'audit', group: 'pro', order: 50, label: __('SVG cleaner', 'easy-svg'), teaser: __('Find and clean up SVGs that did not come through the uploader.', 'easy-svg') },
  { id: 'licence', icon: 'licence', group: 'pro', order: 60, label: __('Licence', 'easy-svg'), teaser: __('Enter your licence key and unlock the Pro features.', 'easy-svg') },
]

const UPSELL_TAB = { id: 'upsell', icon: 'upsell', group: 'pro', order: 70, label: __('Pro', 'easy-svg'), render: (ctx) => <Upsell ctx={ctx} proTabs={PRO_TABS} onActivate={ctx.onActivate} /> }

const RAIL_KEY = 'easy-svg:rail-collapsed'

/*
 * Which tab the address bar asks for.
 *
 * Every tab has its own entry in the WordPress menu, and those entries are
 * this one screen with `?tab=` on it -- so the parameter, not the first tab,
 * decides what opens. Read once per load; an id nothing registered falls
 * through to the default rather than showing an empty well.
 */
function tabFromUrl() {
  try {
    return new URLSearchParams(window.location.search).get('tab') || ''
  } catch {
    return ''
  }
}

export default function App({ config }) {
  const [, force] = useState(0)
  const [active, setActive] = useState(tabFromUrl)
  const [missed, setMissed] = useState(false)
  // Remembered across loads, the way the Forge rail is. localStorage throws in
  // a private window rather than returning null, so both ends are guarded and
  // a blocked store just means the rail opens expanded every time.
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return window.localStorage.getItem(RAIL_KEY) === '1'
    } catch {
      return false
    }
  })
  const toggleRail = () =>
    setCollapsed((c) => {
      const next = !c
      try {
        window.localStorage.setItem(RAIL_KEY, next ? '1' : '0')
      } catch {
        /* a blocked store is not a reason to refuse the click */
      }
      return next
    })

  // Dropping a file next to a zone used to open it in the tab and take the
  // panel with it. The guard swallows those; this says where the file goes, and
  // clears itself so it never becomes furniture.
  useDropGuard(() => {
    setMissed(true)
    window.setTimeout(() => setMissed(false), 6000)
  })

  useEffect(() => {
    const reg = getRegistry()
    if (!reg) return undefined
    const unsub = reg.subscribe(() => force((n) => n + 1))
    // Pro may have registered a tab between this component's first render and
    // this effect running; that notify had no listener yet and was lost. Read
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
    // Set below, once we know Pro registered a licence tab to switch to.
    onActivate: undefined,
  }

  // Tabs Pro registered that are NOT in the catalogue (e.g. Features): shown only
  // when Pro is active and licensed, using the label/order it passed.
  //
  // The order Pro passes is clamped into the pro band. The rail emits a group
  // eyebrow on every group change in sorted order, so an extra that sorted in
  // among the free tabs would read general → pro → general: "General" printed
  // twice, the second with a duplicate React key.
  const PRO_BAND_START = 30
  const PRO_EXTRA_DEFAULT = 65
  const knownIds = new Set([...FREE_TABS, ...PRO_TABS, UPSELL_TAB].map((t) => t.id))
  const extras = (reg && config.proLicensed ? reg.list() : [])
    .filter((t) => !knownIds.has(t.id))
    .map((t) => {
      const asked = Number(t.order)
      const order = Math.max(Number.isFinite(asked) ? asked : PRO_EXTRA_DEFAULT, PRO_BAND_START)
      // icon is a keyword this rail resolves, so an extra tab can look like
      // the rest without Pro bundling any icon of ours.
      return { id: t.id, icon: t.icon, group: 'pro', label: t.label || t.id, order, locked: false, render: (c) => t.render(c) }
    })

  const tabs = [
    ...FREE_TABS,
    ...PRO_TABS.map((t) => {
      const got = reg && reg.get(t.id)
      // The licence tab is how a site activates, so a registered real one is
      // always usable; otherwise you could never enter a key to unlock the
      // rest. Every other pro tab stays locked until the licence is in (and the
      // pro REST refuses it server-side regardless of what the client shows).
      const unlocked = got && (t.id === 'licence' || config.proLicensed)
      // Only offer "go to the licence tab" when there IS one to go to: Pro
      // registers it, so a Pro that is active but has not registered would
      // otherwise send the reader to another locked tab.
      const licenceReachable = !!(reg && reg.get('licence'))
      ctx.onActivate = licenceReachable ? () => setActive('licence') : undefined
      return {
        ...t,
        locked: !unlocked,
        render: unlocked
          ? (c) => got.render(c)
          : () => (
              <LockedTab
                tab={t}
                proActive={config.proActive}
                onActivate={licenceReachable ? () => setActive('licence') : undefined}
              />
            ),
      }
    }),
    ...extras,
    // A licensed site has no use for a tab selling what it already owns.
    ...(config.proLicensed ? [] : [UPSELL_TAB]),
  ].sort((a, b) => a.order - b.order)

  // zag reads `orientation` as a concrete string (it drives arrow-key direction
  // and the data-orientation styling), so resolve the responsive intent to a
  // value rather than handing it an object: a horizontal strip on phones, a
  // vertical left rail from md up.
  //
  // ssr:false because this panel is client-only. The hook defaults to seeding
  // its state with the `base` value and reading matchMedia in an effect, which
  // on a desktop paints one frame of horizontal tab chrome before flipping to
  // the rail.
  const orientation = useBreakpointValue({ base: 'horizontal', md: 'vertical' }, { ssr: false }) ?? 'horizontal'
  const groupLabels = { general: __('General', 'easy-svg'), pro: __('Pro', 'easy-svg') }
  // An unknown ?tab= (a stale bookmark, a tab whose feature was switched off)
  // opens the first one instead of an empty well.
  const activeValue = tabs.some((t) => t.id === active) ? active : tabs[0]?.id

  /*
   * The address bar follows the tab, so the menu entry stays highlighted, the
   * browser's back button works and a link somebody copies opens what they
   * were looking at. replaceState, not push: a tab is not a page in history.
   */
  useEffect(() => {
    if (!activeValue) {
      return
    }
    try {
      const url = new URL(window.location.href)
      if (url.searchParams.get('tab') === activeValue) {
        return
      }
      url.searchParams.set('tab', activeValue)
      window.history.replaceState({}, '', url)
    } catch {
      /* a blocked history is no reason to break the panel */
    }
  }, [activeValue])

  // Flatten the rail into triggers with a non-interactive group eyebrow before
  // each new group. The eyebrow is aria-hidden (the tablist stays a clean list
  // of tabs for assistive tech) and shows only in the vertical rail.
  const rail = []
  let lastGroup = null
  tabs.forEach((t) => {
    if (t.group !== lastGroup) {
      // Collapsed there is no room for the word, and dropping it entirely would
      // run the two groups together, so the break becomes a rule.
      rail.push(
        collapsed ? (
          <Box
            key={`eyebrow-${t.group}`}
            aria-hidden="true"
            display={{ base: 'none', md: 'block' }}
            mx="2"
            mt={rail.length ? '3' : '1'}
            mb="1.5"
            borderTopWidth={rail.length ? '1px' : '0'}
            borderColor="ui.border"
          />
        ) : (
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
      )
      lastGroup = t.group
    }
    rail.push(
      <Tabs.Trigger
        key={t.id}
        value={t.id}
        gap="2.5"
        minH="44px"
        px="3"
        fontSize="sm"
        fontFamily="heading"
        borderRadius="forge"
        fontWeight="400"
        color={t.locked ? 'ui.muted' : 'ui.text'}
        flexShrink="0"
        w={{ md: 'full' }}
        // Collapsed the icon is the whole control, so it centres and the name
        // moves to the tooltip. aria-label keeps it named for a screen reader,
        // which never sees the label element disappear.
        justifyContent={{ base: 'flex-start', md: collapsed ? 'center' : 'flex-start' }}
        px={{ base: '3', md: collapsed ? '0' : '3' }}
        title={collapsed ? t.label : undefined}
        aria-label={collapsed ? t.label : undefined}
        // Not _hover: hover and selected carry the same specificity, so hovering
        // the selected row repainted it with the 6% wash while the white text
        // stayed -- white on near-white, the label gone. Measured before it was
        // written this way.
        css={{ '&:hover:not([data-selected])': { bg: 'ui.ghostHover' } }}
        /*
         * Selected is the solid navy row the Forge sidebar uses. The Pro chip
         * inside it is navy too, so on this row it flips to the yellow accent:
         * only one row can be selected, so the screen still carries exactly one
         * yellow.
         */
        _selected={{
          bg: 'ui.primary',
          color: 'white',
          fontWeight: '700',
          '& .uwp-pro-badge': { bg: 'ui.accent', color: 'navy' },
          // White on the dark ui.primary measures 2.81, under AA. Forge does not
          // use a coloured row on dark at all, so neither does this: the row
          // becomes the surface and the text stays ui.heading, at 13.37.
          _dark: { bg: 'ui.surface', color: 'ui.heading' },
        }}
      >
        <TabIcon name={t.icon} />
        <Text as="span" truncate display={{ base: 'inline', md: collapsed ? 'none' : 'inline' }}>
          {t.label}
        </Text>
        {t.locked ? (
          <ProBadge
            className="uwp-pro-badge"
            ml="auto"
            display={{ base: 'inline-flex', md: collapsed ? 'none' : 'inline-flex' }}
          >
            {groupLabels.pro}
          </ProBadge>
        ) : null}
      </Tabs.Trigger>
    )
  })

  return (
    /*
      One window, not three cards. Header across the top, sidebar flush against
      the content well, footer across the bottom, all inside a single surface
      with one border -- the shape the Forge app has. overflow hidden so the
      corners cut the sidebar and the well instead of them poking out.
    */
    <Box
      maxW="1100px"
      mr={{ base: '3', md: '6' }}
      mb="10"
      bg="ui.surface"
      borderWidth="1px"
      borderColor="ui.border"
      borderRadius="forge"
      /*
        NOT overflow:hidden, however much the corners want it: an overflow
        other than visible makes this box the scroll container for everything
        inside, and a sticky descendant then sticks to a box that does not
        scroll -- which is to say it does not stick at all. The header and the
        footer carry the corner radii instead, and they are the only children
        that reach a corner.
      */
      /*
        One offset for everything that sticks. wp-admin fixes its bar only
        above 782px -- its own breakpoint, not a Chakra one -- and below that
        the bar scrolls away, so keeping its height here pinned the header 46px
        down with nothing in the gap.
      */
      css={{
        '--esw-admin-bar': 'var(--wp-admin--admin-bar--height, 0px)',
        '@media screen and (max-width: 782px)': { '--esw-admin-bar': '0px' },
      }}
    >
      {/*
        The panel's own header bar, the shape the Forge app uses: a white
        surface on the canvas, so the screen opens with something of ours
        rather than straight into wp-admin's grey.
      */}
      {/*
        The brand bar, the way the Forge app opens: the UnleashWP wordmark, a
        rule, then which product this is. The screen's own title is not here --
        it belongs with the screen, in the well below, which is where Forge puts
        it too.
      */}
      <Flex
        borderBottomWidth="1px"
        borderColor="ui.border"
        borderTopRadius="forge"
        px={{ base: '4', md: '5' }}
        h="65px"
        align="center"
        gap="3.5"
        // Sticky under wp-admin's bar, which is fixed on desktop. Its own
        // background, because the content well scrolls underneath it.
        position="sticky"
        top="var(--esw-admin-bar)"
        zIndex="3"
        bg="ui.surface"
      >
        {/*
          Only from md up: below that the rail is a horizontal strip and there is
          nothing to collapse. Same placement as Forge, left of the wordmark.
        */}
        <chakra.button
          type="button"
          onClick={toggleRail}
          hideBelow="md"
          aria-label={collapsed ? __('Expand menu', 'easy-svg') : __('Collapse menu', 'easy-svg')}
          aria-expanded={!collapsed}
          title={collapsed ? __('Expand menu', 'easy-svg') : __('Collapse menu', 'easy-svg')}
          display="grid"
          placeItems="center"
          w="34px"
          h="34px"
          ml="-1.5"
          borderRadius="forge"
          color="ui.muted"
          _hover={{ bg: 'ui.ghostHover', color: 'ui.heading' }}
          _focusVisible={{ outline: '2px solid', outlineColor: 'ui.primary', outlineOffset: '2px' }}
        >
          <BurgerIcon />
        </chakra.button>
        <Box
          role="img"
          aria-label="UnleashWP"
          css={{ '& svg': { height: '1.75rem', width: 'auto', display: 'block' } }}
          dangerouslySetInnerHTML={{ __html: LOGO }}
        />
        <Separator orientation="vertical" h="1.25rem" borderColor="ui.border" hideBelow="sm" />
        {/* The product name, not a sentence: nothing to translate, so no msgid. */}
        <Text color="ui.heading" fontWeight="700" fontSize="0.9688rem" hideBelow="sm">
          Easy SVG
        </Text>
        {config.version ? <Text ml="auto" textStyle="caption">{`v${config.version}`}</Text> : null}
      </Flex>

      {missed ? (
        <Box
          role="status"
          aria-live="polite"
          mb="5"
          px="4"
          py="3"
          borderWidth="1px"
          borderColor="ui.border"
          borderRadius="forge"
          bg="ui.ghostHover"
        >
          <Text fontSize="sm" color="ui.text">
            {__('That landed next to the drop area. Drop SVG files on the dashed area in the Icon Library tab.', 'easy-svg')}
          </Text>
        </Box>
      ) : null}

      {/*
        lazyMount + unmountOnExit belong on the Root: ark lifts them out with
        splitRenderStrategyProps() into a context the panels read, so only the
        active tab's content is ever in the DOM. (On Tabs.Content they are not
        split out and React warns about unknown DOM props, which is what the
        earlier hand-rolled gate here was working around.)
      */}
      <Tabs.Root
        value={activeValue}
        onValueChange={(e) => setActive(e.value)}
        orientation={orientation}
        variant="plain"
        colorPalette="brand"
        lazyMount
        unmountOnExit
      >
        {/*
          flex="1" is load-bearing: with orientation="vertical" the Tabs.Root is
          itself a flex container, so this row is a flex ITEM and would default
          to flex:0 1 auto, shrink-wrapping to its content instead of filling
          the panel. Every tab was already content-sized; it only showed on a tab
          whose content is intrinsically narrow (Pro's Icon Sets table collapsed
          the content column to 244px beside a 240px rail). minW="0" so a wide
          table scrolls inside the column rather than pushing the rail away.
        */}
        <Flex direction={{ base: 'column', md: 'row' }} align="stretch" gap="0" flex="1" minW="0">
          <Tabs.List
            flexWrap="nowrap"
            gap="1"
            flexShrink="0"
            w={{ md: collapsed ? '68px' : '216px' }}
            minW={{ md: collapsed ? '68px' : '216px' }}
            transition="width .16s ease"
            overflowX={{ base: 'auto', md: 'hidden' }}
            overflowY={{ base: 'hidden', md: 'auto' }}
            /*
              Sticky below the header, so the tabs stay reachable down a long
              screen. flex-start rather than stretch: a stretched item is as
              tall as the row and has nowhere to travel. The max height is what
              is left of the viewport under wp-admin's bar and our header, so a
              rail longer than that scrolls on its own instead of being cut.
            */
            position={{ md: 'sticky' }}
            top={{ md: 'calc(var(--esw-admin-bar) + 65px)' }}
            maxH={{ md: 'calc(100vh - var(--esw-admin-bar) - 65px)' }}
            // On dark the rail has no fill of its own: the selected row IS the
            // surface, raised off the canvas, which is what Forge does there.
            // On light it is the other way round, a white rail with a navy row.
            borderBottomWidth={{ base: '1px', md: '0' }}
            borderColor="ui.border"
            p={{ base: '2', md: '3' }}
            alignSelf={{ base: 'stretch', md: 'flex-start' }}
          >
            {rail}
          </Tabs.List>
          <Box
            flex="1"
            minW="0"
            bg="ui.bg"
            // The rule between rail and well, drawn by the well: the rail is
            // only as tall as its tabs now, so its own right border would stop
            // halfway down the screen.
            borderLeftWidth={{ md: '1px' }}
            borderColor="ui.border"
            px={{ base: '4', md: '6' }}
            py={{ base: '5', md: '6' }}
          >
            <Box mb="5">
              <HStack gap="3" align="center">
                {config.icon ? <Image src={config.icon} alt="" boxSize="28px" borderRadius="forge" /> : null}
                <Heading as="h1" textStyle="h1">{__('Icons', 'easy-svg')}</Heading>
              </HStack>
              <Text color="ui.muted" fontSize="sm" maxW="72ch" mt="1">
                {__('SVG uploads and the icon library. With Pro: collections, configurator, audit and licence, in the same panel.', 'easy-svg')}
              </Text>
            </Box>

            {/*
              _closed hides a panel that is in the DOM but not current.
              lazyMount/unmountOnExit on the Root are a render strategy, not a
              guarantee: a panel already mounted stayed mounted AND visible after
              the tab changed, so clicking a second tab left the first tab's
              content on screen and pushed the new one a full 1212px below it.
              Measured in the panel: 2610px of page with two panels stacked,
              982px once closed ones are hidden. One declaration, and it does
              not depend on ark's internals.
            */}
            {tabs.map((t) => (
              <Tabs.Content key={t.id} value={t.id} p="0" _closed={{ display: 'none' }}>
                {t.render(ctx)}
              </Tabs.Content>
            ))}
          </Box>
        </Flex>
      </Tabs.Root>

      {/*
        The footer Forge has and this panel did not. It closes the canvas, and
        it answers the first question a support mail has to answer anyway:
        which version is installed.
      */}
      <Flex
        px={{ base: '4', md: '5' }}
        py="3"
        borderTopWidth="1px"
        borderColor="ui.border"
        borderBottomRadius="forge"
        bg="ui.surface"
        align="center"
        justify="space-between"
        gap="3"
        wrap="wrap"
      >
        <Text textStyle="caption">{__('Easy SVG by UnleashWP', 'easy-svg')}</Text>
        {config.version ? <Text textStyle="caption">{`v${config.version}`}</Text> : null}
      </Flex>
    </Box>
  )
}
