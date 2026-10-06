import { Button as CButton, Input, Box } from '@chakra-ui/react'

// The UnleashWP buttons, same rules as the account app:
// primary = navy, hover turns it yellow; accent = the one yellow call to action;
// ghost = outline. The "forge" radius and the 500/600 weights are theirs.
export function Button({ variant = 'ghost', size = 'md', danger, children, ...rest }) {
  const common = {
    size: size === 'sm' ? 'sm' : 'md',
    borderRadius: 'forge',
    fontWeight: '500',
    h: 'auto',
    py: size === 'sm' ? '2.5' : '3',
    _focusVisible: { outline: '2px solid', outlineColor: 'ui.primary', outlineOffset: '2px' },
    'data-variant': variant,
  }

  if (variant === 'accent') {
    return (
      <CButton {...common} bg="yellow" color="navy" fontWeight="600" boxShadow="sm"
        _hover={{ bg: '#e6ac00' }} _active={{ transform: 'translateY(1px)' }}
        _disabled={{ opacity: 0.55, bg: 'yellow', color: 'navy', cursor: 'default' }} {...rest}>
        {children}
      </CButton>
    )
  }

  if (variant === 'primary') {
    return (
      <CButton {...common} bg="navy" color="white" boxShadow="sm"
        _hover={{ bg: 'yellow', color: 'navy' }} _active={{ transform: 'translateY(1px)' }}
        _disabled={{ opacity: 0.55, bg: 'navy', color: 'white', cursor: 'default' }} {...rest}>
        {children}
      </CButton>
    )
  }

  return (
    <CButton {...common} variant="outline" color="ui.primary" borderColor="ui.border"
      _hover={danger ? { color: 'ui.bad', borderColor: 'ui.bad' } : { borderColor: 'ui.primary', bg: 'ui.ghostHover' }}
      _active={{ transform: 'translateY(1px)' }} {...rest}>
      {children}
    </CButton>
  )
}

export function Card({ children, ...rest }) {
  return (
    <Box bg="ui.surface" borderWidth="1px" borderColor="ui.border" borderRadius="forge"
      boxShadow="sm" px={{ base: '5', md: '6' }} py={{ base: '5', md: '6' }} {...rest}>
      {children}
    </Box>
  )
}

export function TextInput({ type = 'text', ...rest }) {
  return (
    <Input type={type} bg="ui.sunk" borderWidth="1px" borderColor="ui.border" borderRadius="forge"
      color="ui.text"
      _focus={{ bg: 'ui.surface', borderColor: 'ui.primary', boxShadow: '0 0 0 3px var(--chakra-colors-ui-ring)' }}
      {...rest} />
  )
}
