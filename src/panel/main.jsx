import React from 'react'
import { createRoot } from 'react-dom/client'
import { ChakraProvider } from '@chakra-ui/react'
import { system } from '../theme.js'
import App from './App.jsx'
import { installRegistry } from './registry.js'

// Define the extension registry BEFORE anything renders, so the Pro bundle
// (enqueued with this one as a dependency) can registerTab() and have the panel
// pick it up. Light only: the panel lives inside wp-admin.
installRegistry()

const el = document.getElementById('easy-svg-panel')
if (el) {
  // StrictMode stays. With SCRIPT_DEBUG on, WordPress serves the development
  // React, which runs every effect twice: the panel then shows two /settings
  // requests, the first aborted at once and carrying no bytes. A customer site
  // serves the production build and makes one. Measured, not assumed.
  createRoot(el).render(
    <React.StrictMode>
      <ChakraProvider value={system}>
        <App config={window.EasySvgPanelData || {}} />
      </ChakraProvider>
    </React.StrictMode>
  )
}
