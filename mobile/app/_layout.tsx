import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { DarkTheme, DefaultTheme, Stack, ThemeProvider } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { useEffect, useMemo } from 'react';
import { useColorScheme } from 'react-native';

import { panelFor } from '@/src/lib/roles';
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
 * Stack.Protected swaps which group is reachable as the session changes, so there
 * is no imperative redirect to race the first paint.
 *
 * FOUR groups, not two. The guard used to ask only `user !== null`, which meant
 * every signed-in person landed in the customer tab bar: a driver got a shopfront
 * and an empty shipments list instead of their assigned loads, and an admin got a
 * customer app with no hint that their work lives in the web panel. Role is the
 * second question the guard has to ask, and `panelFor()` answers it in one place
 * so no screen has to re-derive it.
 *
 * The splash stays up until the stored token has been checked. Hiding it earlier
 * would show the sign-in screen for a frame to somebody who is already signed in
 * -- brief, but it reads as being logged out, which is alarming in an app holding
 * shipment records.
 */
function RootNavigator() {
  const { user, isLoading } = useSession();

  useEffect(() => {
    if (!isLoading) SplashScreen.hideAsync();
  }, [isLoading]);

  if (isLoading) return null;

  const panel = panelFor(user);

  return (
    <Stack screenOptions={{ headerShown: false }}>
      {/*
        Exactly one of these can match: `panel` is a single value compared for
        equality, so the groups are mutually exclusive by construction. An earlier
        version guarded the customer group on `panel === 'customer'` while the auth
        group used `user === null` — and because panelFor(null) fell through to
        'customer', both were true at once after logout. The tabs stayed mounted,
        401'd, and showed an auth error instead of the login screen.
      */}
      <Stack.Protected guard={panel === 'guest'}>
        <Stack.Screen name="(auth)" />
      </Stack.Protected>

      {/* Customer */}
      <Stack.Protected guard={panel === 'customer'}>
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="service/[slug]" options={{ headerShown: true, title: 'Service' }} />
        <Stack.Screen name="book/[slug]" options={{ headerShown: true, title: 'Book a shipment' }} />
        <Stack.Screen name="booking/[ulid]" options={{ headerShown: true, title: 'Shipment' }} />
        <Stack.Screen
          name="review/[ulid]"
          options={{ headerShown: true, title: 'Leave a review', presentation: 'modal' }}
        />
      </Stack.Protected>

      {/* Driver: assigned loads, and progress reporting from the cab. */}
      <Stack.Protected guard={panel === 'driver'}>
        <Stack.Screen name="(driver)" />
      </Stack.Protected>

      {/* Staff: a signpost to the web panel, not a second admin UI. */}
      <Stack.Protected guard={panel === 'staff'}>
        <Stack.Screen name="(staff)" />
      </Stack.Protected>
    </Stack>
  );
}
