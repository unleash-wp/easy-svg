import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// One self-contained IIFE bundle for the Icons panel (Chakra + Emotion inlined,
// the UnleashWP look). React and ReactDOM are NOT bundled: WordPress ships them
// (script handles `react` / `react-dom` -> window.React / window.ReactDOM), so
// panel.php enqueues them as dependencies and the bundle references the globals.
// Pro enqueues its own bundle that extends this one via window.EasySvgPanel.
export default defineConfig({
  plugins: [react()],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    outDir: 'build',
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
      external: ['react', 'react-dom', 'react-dom/client', 'react/jsx-runtime', 'react/jsx-dev-runtime'],
      output: {
        assetFileNames: 'panel.[ext]',
        globals: {
          react: 'React',
          'react-dom': 'ReactDOM',
          'react-dom/client': 'ReactDOM',
          'react/jsx-runtime': 'ReactJSXRuntime',
          'react/jsx-dev-runtime': 'ReactJSXRuntime',
        },
      },
    },
  },
})
