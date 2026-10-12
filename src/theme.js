// Chakra UI v3 design system: the UnleashWP tokens (navy brand, yellow accent,
// Ubuntu, the "forge" radius), copied from the account app so the editor looks
// like the rest of UnleashWP. Light-only here: it lives inside wp-admin.
import { createSystem, defaultConfig, defineConfig } from '@chakra-ui/react'

const config = defineConfig({
  globalCss: {
    // colorPalette makes navy the default for every Chakra component that
    // resolves colorPalette.* (badges, tab indicators, switches). Without it
    // they fall back to Chakra's zinc and the panel stops looking like ours.
    // Scoped to the panel root, never html: wp-admin is not ours to restyle.
    '#easy-svg-panel': { fontFamily: 'body', color: 'ui.text', colorPalette: 'brand' },
    '#easy-svg-panel *:focus-visible': {
      outline: '2px solid',
      outlineColor: 'ui.primary',
      outlineOffset: '2px',
    },
  },
  theme: {
    breakpoints: { sm: '560px', md: '640px', lg: '780px', xl: '1280px', '2xl': '1536px' },
    tokens: {
      colors: {
        navy: { value: '#203159' },
        navyDeep: { value: '#0f131f' },
        navy2: { value: '#2a3f6f' },
        yellow: { value: '#fcbe00' },
        slate: { value: '#727f9f' },
        slate2: { value: '#35415b' },
        brand: {
          50: { value: '#eef1f6' },
          100: { value: '#d7dded' },
          200: { value: '#b0bcd6' },
          300: { value: '#8496bd' },
          400: { value: '#5d6f9f' },
          500: { value: '#3c4e7d' },
          600: { value: '#2a3b64' },
          700: { value: '#203159' },
          800: { value: '#1a2747' },
          900: { value: '#141d35' },
          950: { value: '#0f131f' },
        },
      },
      // Ubuntu -- the same two faces Pro's set editor loads -- ships with the
      // plugin (public/fonts -> build/fonts, UFL 1.0 text beside the files;
      // panel.php declares them). Only headings, tabs and buttons use it;
      // body text stays system type on purpose.
      fonts: {
        heading: { value: 'Ubuntu, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' },
        body: { value: 'system-ui, -apple-system, "Segoe UI", Roboto, Ubuntu, sans-serif' },
      },
      radii: {
        forge: { value: '0.3125rem' },
      },
    },
    semanticTokens: {
      colors: {
        // Every semantic token carries both surfaces. The dark column is the one
        // the Forge app ships, copied value for value so the two products are
        // the same screen in two places. The panel mounts under a `dark` class,
        // so dark is what renders; the base column stays because it is what the
        // tokens mean and what any future light switch would use.
        brand: {
          solid: { value: { base: '{colors.brand.700}', _dark: '{colors.brand.400}' } },
          contrast: { value: '#ffffff' },
          fg: { value: { base: '{colors.brand.700}', _dark: '{colors.brand.300}' } },
          muted: { value: { base: '{colors.brand.100}', _dark: '{colors.brand.900}' } },
          subtle: { value: { base: '{colors.brand.50}', _dark: '{colors.brand.950}' } },
          emphasized: { value: { base: '{colors.brand.200}', _dark: '{colors.brand.800}' } },
          focusRing: { value: { base: '{colors.brand.600}', _dark: '{colors.brand.400}' } },
        },
        'ui.bg': { value: { base: '#eef1f6', _dark: '#0a0e16' } },
        'ui.surface': { value: { base: '#ffffff', _dark: '#141a27' } },
        'ui.sunk': { value: { base: '#f5f7fa', _dark: '#1b2231' } },
        'ui.border': { value: { base: '#e3e7f0', _dark: '#2b3446' } },
        'ui.heading': { value: { base: '{colors.navy}', _dark: '#eaf0ff' } },
        'ui.text': { value: { base: '#2b3242', _dark: '#dbe2ef' } },
        'ui.muted': { value: { base: '#55607a', _dark: '#94a1bd' } },
        'ui.primary': { value: { base: '{colors.navy}', _dark: '#7c93ff' } },
        'ui.accent': { value: '{colors.yellow}' },
        'ui.tagbg': { value: { base: '#eceef5', _dark: '#232b40' } },
        'ui.tagfg': { value: { base: '{colors.navy}', _dark: '#b9c7ff' } },
        'ui.good': { value: '#1a8f57' },
        'ui.goodInk': { value: { base: '#157a45', _dark: '#4bd08a' } },
        'ui.bad': { value: '#c0392b' },
        'ui.goodBg': { value: { base: '#e3f3ea', _dark: '#13301f' } },
        'ui.badBg': { value: { base: '#fbe7e4', _dark: '#331a17' } },
        'ui.badInk': { value: { base: '#a5342a', _dark: '#ff9c8f' } },
        'ui.ghostHover': { value: { base: 'rgba(32,49,89,.06)', _dark: 'rgba(124,147,255,.12)' } },
        'ui.ring': { value: { base: 'rgba(32,49,89,.26)', _dark: 'rgba(124,147,255,.42)' } },
        'ui.rangeFill': { value: { base: '#e7ebf5', _dark: '#26314d' } },
      },
      shadows: {
        // Plain values, not { base, _dark }: the conditional form on these three
        // names silently lost to Chakra's own shadows.sm/md/lg and the panel
        // rendered its zinc default. Measured --chakra-shadows-sm at
        // color-mix(#18181b ...) until this went back.
        sm: { value: '0 1px 2px rgba(32,49,89,.06)' },
        md: { value: '0 6px 24px rgba(32,49,89,.09)' },
        lg: { value: '0 18px 48px rgba(32,49,89,.16)' },
      },
    },
    // Typography scale for the panel. Only 400 and 700 are used: Ubuntu ships
    // exactly those two, so a machine that has it renders both for real instead
    // of synthesising 500/600 as a faux-bold. Apply with textStyle="…".
    textStyles: {
      h1: {
        value: {
          fontSize: '28px',
          fontWeight: '700',
          letterSpacing: '-0.01em',
          color: 'ui.heading',
        },
      },
      sectionTitle: {
        value: {
          fontSize: '16px',
          fontWeight: '700',
          color: 'ui.heading',
        },
      },
      fieldLabel: {
        value: {
          fontSize: '14px',
          fontWeight: '700',
          color: 'ui.heading',
        },
      },
      description: {
        value: {
          fontSize: '13px',
          fontWeight: '400',
          lineHeight: '1.5',
          color: 'ui.muted',
        },
      },
      caption: {
        value: {
          // 13px, the same step as descriptions: the audit found four body
          // sizes on one screen, and the raster allows two (13 and 14).
          fontSize: '13px',
          fontWeight: '400',
          color: 'ui.muted',
        },
      },
    },
  },
})

export const system = createSystem(defaultConfig, config)
