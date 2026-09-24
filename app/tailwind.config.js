/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      // Roles, not hues (bible-assistant's convention): values live in CSS
      // variables in src/index.css as RGB channels, so alpha modifiers work.
      colors: {
        night: {
          DEFAULT: 'rgb(var(--night) / <alpha-value>)', // page background
          deep: 'rgb(var(--night-deep) / <alpha-value>)',
          raised: 'rgb(var(--night-raised) / <alpha-value>)', // cards
          line: 'rgb(var(--night-line) / <alpha-value>)', // hairlines, borders
        },
        ink: {
          DEFAULT: 'rgb(var(--ink) / <alpha-value>)',
          muted: 'rgb(var(--ink-muted) / <alpha-value>)',
          faint: 'rgb(var(--ink-faint) / <alpha-value>)',
        },
        brand: {
          DEFAULT: 'rgb(var(--brand) / <alpha-value>)', // ARCHE blue
          bright: 'rgb(var(--brand-bright) / <alpha-value>)',
        },
        live: 'rgb(var(--live) / <alpha-value>)',
        heart: 'rgb(var(--heart) / <alpha-value>)',
        // The four submission tiles from the mockup.
        song: { from: '#5b2fd6', to: '#8e4dff' },
        story: { from: '#0c6f64', to: '#13a892' },
        prayer: { from: '#1a4fd6', to: '#2f7bff' },
        room: { from: '#b86a06', to: '#e0a11a' },
      },
      fontFamily: {
        sans: ['"Inter Variable"', 'Inter', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
      },
      boxShadow: {
        glow: '0 0 0 1px rgb(var(--brand) / 0.35), 0 8px 30px -6px rgb(var(--brand) / 0.35)',
        card: '0 10px 30px -12px rgb(0 0 0 / 0.6)',
      },
      animation: {
        'pulse-soft': 'pulse 2.4s cubic-bezier(0.4, 0, 0.6, 1) infinite',
        'fly-in': 'flyIn 0.7s cubic-bezier(0.2, 0.8, 0.2, 1) both',
        'fade-out': 'fadeOut 0.45s ease-in both',
        'ring': 'ring 2.2s ease-out infinite',
      },
      keyframes: {
        flyIn: {
          '0%': { opacity: '0', transform: 'translateY(16px) scale(0.98)' },
          '100%': { opacity: '1', transform: 'translateY(0) scale(1)' },
        },
        fadeOut: {
          '0%': { opacity: '1', transform: 'translateX(0)' },
          '100%': { opacity: '0', transform: 'translateX(24px)' },
        },
        ring: {
          '0%': { transform: 'scale(0.9)', opacity: '0.7' },
          '100%': { transform: 'scale(1.6)', opacity: '0' },
        },
      },
    },
  },
  plugins: [],
};
