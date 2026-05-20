module.exports = {
  content: ['./resources/js/dashboard/**/*.{vue,js}', './resources/views/sunset-app.blade.php'],
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        mono: ['ui-monospace', '"Cascadia Code"', 'Menlo', 'monospace'],
        sans: ['system-ui', '-apple-system', 'sans-serif'],
      },
      colors: {
        sunset: {
          bg:     'rgb(var(--sunset-bg) / <alpha-value>)',
          rail:   'rgb(var(--sunset-rail) / <alpha-value>)',
          card:   'rgb(var(--sunset-card) / <alpha-value>)',
          border: 'rgb(var(--sunset-border) / <alpha-value>)',
          text:   'rgb(var(--sunset-text) / <alpha-value>)',
          muted:  'rgb(var(--sunset-muted) / <alpha-value>)',
          accent: 'rgb(var(--sunset-accent) / <alpha-value>)',
        },
        status: {
          error: 'rgb(239 68 68)',
          warn:  'rgb(245 158 11)',
          ok:    'rgb(34 197 94)',
          info:  'rgb(96 165 250)',
        },
      },
    },
  },
  plugins: [],
};
