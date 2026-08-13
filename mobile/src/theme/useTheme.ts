import { useColorScheme } from 'react-native';

import { darkColors, lightColors, radius, spacing, typography, type ThemeColors } from './tokens';

export interface Theme {
  colors: ThemeColors;
  spacing: typeof spacing;
  radius: typeof radius;
  typography: typeof typography;
  isDark: boolean;
}

/**
 * useColorScheme() returns null before the OS value is known, so the ?? pins the
 * first paint to light rather than letting it flash a half-built dark palette.
 */
export function useTheme(): Theme {
  const scheme = useColorScheme() ?? 'light';
  const isDark = scheme === 'dark';

  return {
    colors: isDark ? darkColors : lightColors,
    spacing,
    radius,
    typography,
    isDark,
  };
}
