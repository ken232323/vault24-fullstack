import { createTheme } from '@mantine/core';

// Deep ledger green: calm, serious, not the usual fintech blue.
export const theme = createTheme({
  primaryColor: 'vault',
  primaryShade: { light: 7, dark: 5 },
  colors: {
    vault: ['#eaf6f1', '#d3ebe1', '#a5d6c3', '#74c0a4', '#4cad8c', '#33a077', '#1f9a6c', '#0f7f58', '#0a6a49', '#02563a'],
  },
  fontFamily: 'Manrope, system-ui, sans-serif',
  headings: { fontFamily: 'Manrope, system-ui, sans-serif', fontWeight: '800' },
  defaultRadius: 'md',
});
