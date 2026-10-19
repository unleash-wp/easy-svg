import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { mkdirSync, readdirSync, renameSync, rmSync } from 'node:fs'
import { join } from 'node:path'

// Built aside, then renamed into build/ file by file: wp-env serves build/ live,
// and a page loaded mid-build got no panel. The whole folder moves, because
// vite copies public/fonts in beside the bundle.
const ASIDE = 'node_modules/.cache/build-panel'
function swapIn(into) {
  const move = (from, to) => {
    mkdirSync(to, { recursive: true })
    for (const entry of readdirSync(from, { withFileTypes: true })) {
      if (entry.isDirectory()) {
        move(join(from, entry.name), join(to, entry.name))
      } else {
        renameSync(join(from, entry.name), join(to, entry.name))
      }
    }
  }
  return {
    name: 'swap-in',
    closeBundle() {
      move(ASIDE, into)
      rmSync(ASIDE, { recursive: true, force: true })
    },
  }
}

// One self-contained IIFE bundle for the Icons panel (Chakra + Emotion inlined,
// the UnleashWP look). React and ReactDOM are NOT bundled: WordPress ships them
// (script handles `react` / `react-dom` -> window.React / window.ReactDOM), so
// panel.php enqueues them as dependencies and the bundle references the globals.
// Pro enqueues its own bundle that extends this one via window.EasySvgPanel.
export default defineConfig({
  plugins: [react(), swapIn('build')],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    outDir: ASIDE,
    emptyOutDir: true,
    lib: {
      entry: 'src/panel/main.jsx',
      formats: ['iife'],
      name: 'EasySvgPanelApp',
      fileName: () => 'panel.js',
    },
    rollupOptions: {
      // React, ReactDOM AND the JSX runtime come from WordPress, so Chakra and
      // our code share one React instance (two copies -> "Objects are not valid
      // as a React child"). WP ships the `react-jsx-runtime` handle for this.
      // @wordpress/i18n is externalised to the wp.i18n global (WordPress ships it
      // as the `wp-i18n` script handle), exactly like the React externals above,
      // so __()/_x() calls map to the global instead of being bundled.
      external: ['react', 'react-dom', 'react-dom/client', 'react/jsx-runtime', 'react/jsx-dev-runtime', '@wordpress/i18n'],
      output: {
        assetFileNames: 'panel.[ext]',
        globals: {
          react: 'React',
          'react-dom': 'ReactDOM',
          'react-dom/client': 'ReactDOM',
          'react/jsx-runtime': 'ReactJSXRuntime',
          'react/jsx-dev-runtime': 'ReactJSXRuntime',
          '@wordpress/i18n': 'wp.i18n',
        },
      },
    },
  },
})
