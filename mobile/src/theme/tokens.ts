/**
 * Design tokens. One source of truth for colour, spacing and type so screens
 * never hardcode a hex value -- that is what makes the dark scheme a single
 * lookup rather than an audit of every StyleSheet in the app.
 *
 * Spacing is a 4pt scale: arbitrary values are what make a layout look subtly
 * wrong in a way nobody can point at.
 */

export const palette = {
  /** Brand blue. Reads as "logistics" without being the default RN blue. */
  brand50: '#eef6ff',
  brand100: '#d9ebff',
  brand200: '#b9daff',
  brand400: '#4b93f0',
  brand500: '#1f6fd4',
  brand600: '#1558ad',
  brand700: '#104689',

  amber500: '#d98324',
  amber100: '#fdf0dc',

  green500: '#1f8a54',
  green100: '#dff3e7',

  red500: '#c0392f',
  red100: '#fbe3e1',

  slate950: '#0b1220',
  slate900: '#111a2b',
  slate800: '#1c2740',
  slate700: '#2c3a56',
  slate500: '#5b6b85',
  slate400: '#8794ab',
  slate300: '#aab4c6',
  slate200: '#d8dee8',
  slate100: '#eef1f6',
  slate50: '#f7f9fc',
  white: '#ffffff',
} as const;

export interface ThemeColors {
  background: string;
  surface: string;
  surfaceAlt: string;
  border: string;
  text: string;
  textMuted: string;
  textInverse: string;
  primary: string;
  primaryText: string;
  primarySoft: string;
  success: string;
  successSoft: string;
  warning: string;
  warningSoft: string;
  danger: string;
  dangerSoft: string;
  star: string;
}

export const lightColors: ThemeColors = {
  background: palette.slate50,
  surface: palette.white,
  surfaceAlt: palette.slate100,
  border: palette.slate200,
  text: palette.slate950,
  textMuted: palette.slate500,
  textInverse: palette.white,
  primary: palette.brand500,
  primaryText: palette.white,
  primarySoft: palette.brand50,
  success: palette.green500,
  successSoft: palette.green100,
  warning: palette.amber500,
  warningSoft: palette.amber100,
  danger: palette.red500,
  dangerSoft: palette.red100,
  star: '#e0a422',
};

export const darkColors: ThemeColors = {
  background: palette.slate950,
  surface: palette.slate900,
  surfaceAlt: palette.slate800,
  border: palette.slate700,
  text: palette.slate50,
  textMuted: palette.slate400,
  textInverse: palette.slate950,
  primary: palette.brand400,
  primaryText: palette.slate950,
  primarySoft: 'rgba(75,147,240,0.14)',
  success: '#4cc98a',
  successSoft: 'rgba(76,201,138,0.14)',
  warning: '#e6a552',
  warningSoft: 'rgba(230,165,82,0.14)',
  danger: '#ef6f66',
  dangerSoft: 'rgba(239,111,102,0.14)',
  star: '#f0bc4a',
};

export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
  xxxl: 48,
} as const;

export const radius = {
  sm: 6,
  md: 10,
  lg: 14,
  pill: 999,
} as const;

export const typography = {
  display: { fontSize: 28, fontWeight: '700' as const, letterSpacing: -0.4 },
  title: { fontSize: 20, fontWeight: '700' as const, letterSpacing: -0.2 },
  heading: { fontSize: 17, fontWeight: '600' as const },
  body: { fontSize: 15, fontWeight: '400' as const },
  label: { fontSize: 13, fontWeight: '600' as const },
  caption: { fontSize: 12, fontWeight: '400' as const },
} as const;

/**
 * Minimum touch target. 44pt is the Apple HIG floor and close enough to
 * Android's 48dp that one number serves both; anything smaller fails people
 * with motor impairments first and everyone else in a moving vehicle second --
 * which, for a driver-facing app, is the common case rather than the edge one.
 */
export const HIT_TARGET = 44;
