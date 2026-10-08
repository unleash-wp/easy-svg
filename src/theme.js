// Chakra UI v3 design system — the UnleashWP tokens (navy brand, yellow accent,
// Ubuntu, the "forge" radius), copied from the account app so the editor looks
// like the rest of UnleashWP. Light-only here: it lives inside wp-admin.
import { createSystem, defaultConfig, defineConfig } from '@chakra-ui/react'

const config = defineConfig({
  globalCss: {
    '#easy-svg-panel': { fontFamily: 'body', color: 'ui.text' },
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
      fonts: {
        heading: { value: '"Ubuntu", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' },
        body: { value: '"Ubuntu", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' },
      },
      radii: {
        forge: { value: '0.3125rem' },
      },
    },
    semanticTokens: {
      colors: {
        brand: {
          solid: { value: '{colors.brand.700}' },
          contrast: { value: '#ffffff' },
          fg: { value: '{colors.brand.700}' },
          muted: { value: '{colors.brand.100}' },
          subtle: { value: '{colors.brand.50}' },
          emphasized: { value: '{colors.brand.200}' },
          focusRing: { value: '{colors.brand.600}' },
        },
        'ui.bg': { value: '#eef1f6' },
        'ui.surface': { value: '#ffffff' },
        'ui.sunk': { value: '#f5f7fa' },
        'ui.border': { value: '#e3e7f0' },
        'ui.heading': { value: '{colors.navy}' },
        'ui.text': { value: '#2b3242' },
        'ui.muted': { value: '#55607a' },
        'ui.primary': { value: '{colors.navy}' },
        'ui.accent': { value: '{colors.yellow}' },
        'ui.tagbg': { value: '#eceef5' },
        'ui.tagfg': { value: '{colors.navy}' },
        'ui.good': { value: '#1a8f57' },
        'ui.goodInk': { value: '#157a45' },
        'ui.bad': { value: '#c0392b' },
        'ui.goodBg': { value: '#e3f3ea' },
        'ui.badBg': { value: '#fbe7e4' },
        'ui.badInk': { value: '#a5342a' },
        'ui.ghostHover': { value: 'rgba(32,49,89,.06)' },
        'ui.ring': { value: 'rgba(32,49,89,.26)' },
        'ui.rangeFill': { value: '#e7ebf5' },
      },
      shadows: {
        sm: { value: '0 1px 2px rgba(32,49,89,.06)' },
        md: { value: '0 6px 24px rgba(32,49,89,.09)' },
        lg: { value: '0 18px 48px rgba(32,49,89,.16)' },
      },
    },
    // Typography scale for the panel. The self-hosted Ubuntu (assets/panel-fonts.css)
    // ships ONLY 400 and 700, so the scale uses only those two weights — 500/600
    // would render as a faux-bold the font never shipped. Apply with textStyle="…".
    textStyles: {
      h1: {
        value: {
          fontSize: '24px',
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
          fontSize: '12px',
          fontWeight: '400',
          color: 'ui.muted',
        },
      },
    },
  },
})

export const system = createSystem(defaultConfig, config)
