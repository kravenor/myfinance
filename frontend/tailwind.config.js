const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]
// I colori puntano alle variabili di src/theme.css, invertite in dark mode.
const palette = (token) =>
  Object.fromEntries(SHADES.map((s) => [s, `rgb(var(--c-${token}-${s}) / <alpha-value>)`]))

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,ts,tsx}'],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        slate: palette('slate'),
        surface: 'rgb(var(--c-surface) / <alpha-value>)',
        primary: palette('primary'),
        income: palette('income'),
        expense: palette('expense'),
        transfer: palette('transfer'),
        warning: palette('warning'),
        danger: palette('danger'),
      },
    },
  },
  plugins: [],
}
