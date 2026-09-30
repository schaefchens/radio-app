/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  // lib/theme.ts sets <html data-theme>; dark: follows it, not the device.
  darkMode: ['selector', '[data-theme="dark"]'],
  theme: {
    extend: {
      // Roles, not hues (bible-assistant's convention): values live in CSS
      // variables in src/styles/tokens.css as RGB channels, so alpha modifiers work.
      colors: {
        base: 'rgb(var(--base) / <alpha-value>)', // page background
        surface: 'rgb(var(--surface) / <alpha-value>)', // cards
        soft: 'rgb(var(--soft) / <alpha-value>)', // fields, insets, sheets
        line: 'rgb(var(--line) / <alpha-value>)', // hairlines, borders
        ink: {
          DEFAULT: 'rgb(var(--ink) / <alpha-value>)',
          muted: 'rgb(var(--ink-muted) / <alpha-value>)',
          faint: 'rgb(var(--ink-faint) / <alpha-value>)',
        },
        accent: {
          DEFAULT: 'rgb(var(--accent) / <alpha-value>)', // links, icons, the current tab
          fill: 'rgb(var(--accent-fill) / <alpha-value>)', // solid buttons under white text
        },
        live: 'rgb(var(--live) / <alpha-value>)',
        heart: 'rgb(var(--heart) / <alpha-value>)',
        gold: 'rgb(var(--gold) / <alpha-value>)', // praying along
        ok: 'rgb(var(--ok) / <alpha-value>)',
        warn: 'rgb(var(--warn) / <alpha-value>)',
        // The four submission tiles from the mockup.
        song: { from: '#5b2fd6', to: '#8e4dff' },
        story: { from: '#0c6f64', to: '#13a892' },
        prayer: { from: '#1a4fd6', to: '#2f7bff' },
        room: { from: '#b86a06', to: '#e0a11a' },
      },
      fontFamily: {
        sans: ['"Inter Variable"', 'Inter', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
        // The dark theme's "Arche Radio": a system serif, nothing to download.
        serif: ['Georgia', '"Times New Roman"', 'serif'],
      },
      boxShadow: {
        glow: '0 0 0 1px rgb(var(--accent-fill) / 0.35), 0 8px 30px -6px rgb(var(--accent-fill) / 0.35)',
        card: 'var(--card-shadow)',
        player: 'var(--player-shadow)',
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
