import { Tabs } from 'expo-router';
import { Text, type ColorValue } from 'react-native';

import { useTheme } from '@/src/theme/useTheme';

function TabIcon({ glyph, color }: { glyph: string; color: ColorValue }) {
  return (
    <Text aria-hidden importantForAccessibility="no" style={{ fontSize: 20, color }}>
      {glyph}
    </Text>
  );
}

/**
 * The driver panel. Two tabs, not four: a driver in a cab needs today's run and
 * a way out, and every extra tab is a mis-tap while wearing gloves.
 */
export default function DriverLayout() {
  const { colors } = useTheme();

  return (
    <Tabs
      screenOptions={{
        tabBarActiveTintColor: colors.primary,
        tabBarInactiveTintColor: colors.textMuted,
        tabBarStyle: { backgroundColor: colors.surface, borderTopColor: colors.border },
        headerStyle: { backgroundColor: colors.surface },
        headerTitleStyle: { color: colors.text },
        headerShadowVisible: false,
      }}
    >
      <Tabs.Screen
        name="index"
        options={{
          title: 'My jobs',
          tabBarIcon: ({ color }) => <TabIcon glyph="◈" color={color} />,
        }}
      />
      <Tabs.Screen
        name="account"
        options={{
          title: 'Account',
          tabBarIcon: ({ color }) => <TabIcon glyph="☺" color={color} />,
        }}
      />
    </Tabs>
  );
}
