import React from 'react'
import { Stack } from '@chakra-ui/react'
import { Notice } from '../parts.jsx'

// Phase 2: the media-SVG icon manager (currently under Media -> SVG icons) moves
// here, driven by the free /library REST. Stub for now so the panel is complete.
export default function Library() {
  return (
    <Stack gap="4" maxW="640px">
      <Notice>Die Icon-Library (deine SVGs aus der Mediathek) zieht als nächstes hierher.</Notice>
    </Stack>
  )
}
