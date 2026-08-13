import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { DarkTheme, DefaultTheme, Stack, ThemeProvider } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { useEffect, useMemo } from 'react';
import { useColorScheme } from 'react-native';

import { SessionProvider, useSession } from '@/src/store/session';

export { ErrorBoundary } from 'expo-router';

SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  const scheme = useColorScheme();

  const queryClient = useMemo(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            // A phone loses signal constantly. One silent retry absorbs a tunnel;
            // more than that just delays showing the user what went wrong.
            retry: 1,
            staleTime: 30_000,
            refetchOnWindowFocus: false,
          },
        },
      }),
    [],
  );

  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider value={scheme === 'dark' ? DarkTheme : DefaultTheme}>
        <SessionProvider>
          <RootNavigator />
        </SessionProvider>
      </ThemeProvider>
    </QueryClientProvider>
  );
}

/**
 * Stack.Protected swaps which group is reachable as `user` changes, so there is
 * no imperative redirect to race the first paint.
 *
 * The splash stays up until the stored token has been checked. Hiding it earlier
 * would show the sign-in screen for a frame to somebody who is already signed
 * in -- brief, but it reads as being logged out, which is alarming in an app
 * holding shipment records.
 */
function RootNavigator() {
  const { user, isLoading } = useSession();

  useEffect(() => {
    if (!isLoading) SplashScreen.hideAsync();
  }, [isLoading]);

  if (isLoading) return null;

  return (
    <Stack screenOptions={{ headerShown: false }}>
      <Stack.Protected guard={user !== null}>
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="service/[slug]" options={{ headerShown: true, title: 'Service' }} />
        <Stack.Screen name="book/[slug]" options={{ headerShown: true, title: 'Book a shipment' }} />
        <Stack.Screen name="booking/[ulid]" options={{ headerShown: true, title: 'Shipment' }} />
        <Stack.Screen
          name="review/[ulid]"
          options={{ headerShown: true, title: 'Leave a review', presentation: 'modal' }}
        />
      </Stack.Protected>

      <Stack.Protected guard={user === null}>
        <Stack.Screen name="(auth)" />
      </Stack.Protected>
    </Stack>
  );
}
