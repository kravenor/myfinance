import colors from 'tailwindcss/colors'

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,ts,tsx}'],
  theme: {
    extend: {
      // Alias semantici: le view usano questi nomi, non il colore grezzo.
      colors: {
        primary: colors.indigo,
        income: colors.emerald,
        expense: colors.rose,
        transfer: colors.sky,
        warning: colors.amber,
        danger: colors.red,
      },
    },
  },
  plugins: [],
}
