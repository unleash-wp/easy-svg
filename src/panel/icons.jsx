// The panel rail's icons, one per tab.
//
// Hand-drawn rather than pulled from an icon package: RemixIcon, which the
// Forge app uses, is Apache-2.0, and wordpress.org needs everything in the
// directory to be GPLv2-compatible. Same trap the Ubuntu webfont set, so these
// are ours. Stroke-based at 1.5 so they read at the same weight as Forge's set.

const BASE = {
  width: 20,
  height: 20,
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.5,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
  'aria-hidden': 'true',
  focusable: 'false',
}

const PATHS = {
  // A cog. The ray version of this was a SUN: the same circle with eight
  // spokes around it and no teeth, which is the weather, not a setting.
  settings: (
    <>
      <circle cx="12" cy="12" r="3.2" />
      <path d="M19.4 14.5a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5v.2a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H2.8a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3h.1a1.7 1.7 0 0 0 1-1.5V2.8a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.5 1h.2a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" />
    </>
  ),
  library: (
    <>
      <rect x="3" y="3" width="7" height="7" rx="1.5" />
      <rect x="14" y="3" width="7" height="7" rx="1.5" />
      <rect x="3" y="14" width="7" height="7" rx="1.5" />
      <rect x="14" y="14" width="7" height="7" rx="1.5" />
    </>
  ),
  sets: (
    <>
      <path d="M3 7.5 12 3l9 4.5-9 4.5z" />
      <path d="m3 12 9 4.5L21 12" />
      <path d="m3 16.5 9 4.5 9-4.5" />
    </>
  ),
  configurator: (
    <>
      <path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h10M18 18h2" />
      <circle cx="16" cy="6" r="2" />
      <circle cx="10" cy="12" r="2" />
      <circle cx="16" cy="18" r="2" />
    </>
  ),
  audit: (
    <>
      <path d="M12 3l7 3v5.5c0 4.3-2.9 7.6-7 9.5-4.1-1.9-7-5.2-7-9.5V6z" />
      <path d="m9 12 2 2 4-4" />
    </>
  ),
  licence: (
    <>
      <circle cx="8" cy="12" r="3.5" />
      <path d="M11.5 12H21M18 12v3M15 12v2.5" />
    </>
  ),
  // Registered by Pro through the rail's icon keyword, not bundled by it.
  packs: (
    <>
      <rect x="3" y="4" width="18" height="4" rx="1" />
      <path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8" />
      <path d="M10 12h4" />
    </>
  ),
  features: (
    <>
      <rect x="2.5" y="5" width="19" height="7" rx="3.5" />
      <circle cx="16.5" cy="8.5" r="1.8" />
      <rect x="2.5" y="14" width="19" height="7" rx="3.5" />
      <circle cx="7.5" cy="17.5" r="1.8" />
    </>
  ),
  upsell: (
    <>
      <path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" />
      <path d="M19 15.5l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z" />
    </>
  ),
}

// The rail's own collapse control, not a tab icon: exported separately so the
// brand bar can draw it without going through the keyword map.
export function BurgerIcon() {
  return (
    <svg {...BASE}>
      <path d="M4 7h16M4 12h16M4 17h16" />
    </svg>
  )
}

// An unknown name draws an EMPTY slot, not a fallback glyph. A tab registered
// by a future plugin should not borrow a meaning it did not ask for, and
// dropping the element instead would shift that row's label out of line with
// every other one.
export function TabIcon({ name }) {
  return <svg {...BASE}>{PATHS[name] || null}</svg>
}
