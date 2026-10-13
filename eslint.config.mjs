import react from 'eslint-plugin-react'
import hooks from 'eslint-plugin-react-hooks'

/*
 * Narrow on purpose: this is here to catch what a human reading a diff does
 * not -- an import left behind by a refactor, a name that no longer exists.
 * Three findings of exactly that shape reached review before it existed.
 *
 * exhaustive-deps WARNS. Several effects here deliberately leave a dependency
 * out and say why, so failing on it would turn the file into disable comments.
 */
export default [
  {
    files: ['src/**/*.jsx', 'src/**/*.js'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
      parserOptions: { ecmaFeatures: { jsx: true } },
      // React and wp come from WordPress at runtime, not from an import.
      globals: {
        window: 'readonly',
        document: 'readonly',
        console: 'readonly',
        setTimeout: 'readonly',
        clearTimeout: 'readonly',
        setInterval: 'readonly',
        clearInterval: 'readonly',
        requestAnimationFrame: 'readonly',
        AbortController: 'readonly',
        URLSearchParams: 'readonly',
        FormData: 'readonly',
        Blob: 'readonly',
        File: 'readonly',
        FileReader: 'readonly',
        fetch: 'readonly',
        navigator: 'readonly',
        localStorage: 'readonly',
        URL: 'readonly',
        wp: 'readonly',
      },
    },
    plugins: { react, 'react-hooks': hooks },
    rules: {
      'no-unused-vars': ['error', { args: 'none' }],
      'no-undef': 'error',
      'react/jsx-uses-vars': 'error',
      'react/jsx-uses-react': 'error',
      'react-hooks/rules-of-hooks': 'error',
      'react-hooks/exhaustive-deps': 'warn',
    },
  },
]
