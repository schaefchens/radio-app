import js from '@eslint/js';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import tseslint from 'typescript-eslint';
import { defineConfig, globalIgnores } from 'eslint/config';

const unusedVars = [
  'error',
  {
    ignoreRestSiblings: true,
    args: 'after-used',
    argsIgnorePattern: '^_',
    varsIgnorePattern: '^_',
    caughtErrorsIgnorePattern: '^_',
    destructuredArrayIgnorePattern: '^_',
  },
];

export default defineConfig([
  globalIgnores([
    '**/dist',
    '**/node_modules',
    'build',
    '.data',
    'server/vendor',
    '**/playwright-report',
    '**/test-results',
    '**/blob-report',
    '**/coverage',
  ]),
  {
    files: ['app/**/*.{ts,tsx}'],
    extends: [
      js.configs.recommended,
      tseslint.configs.recommended,
      reactHooks.configs.flat.recommended,
      reactRefresh.configs.vite,
    ],
    languageOptions: { globals: globals.browser },
    rules: { '@typescript-eslint/no-unused-vars': unusedVars },
  },
  {
    // Configs and the three test layers run in node, not the browser.
    files: ['app/tests/**/*.{ts,tsx}', 'app/*.config.ts'],
    languageOptions: { globals: { ...globals.node, ...globals.browser } },
    rules: { 'react-refresh/only-export-components': 'off' },
  },
  {
    files: ['realtime/**/*.ts', 'shared/**/*.ts', 'scripts/**/*.{js,mjs,ts}', 'eslint.config.js'],
    extends: [js.configs.recommended, tseslint.configs.recommended],
    languageOptions: { globals: globals.node },
    rules: { '@typescript-eslint/no-unused-vars': unusedVars },
  },
]);
