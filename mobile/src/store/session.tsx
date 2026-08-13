import { createContext, useContext, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';

import { api, saveToken, clearToken, setUnauthenticatedHandler, type ApiError } from '@/src/api/client';
import { endpoints } from '@/src/api/endpoints';
import type { User } from '@/src/types/api';

/**
 * The session. Deliberately a context rather than a module-level store: the
 * root layout's Stack.Protected guard has to re-render when auth changes, and a
 * value read outside React would not trigger that.
 */

interface SessionValue {
  user: User | null;
  /** True until the stored token has been checked. Guards must not run before this clears. */
  isLoading: boolean;
  signIn: (email: string, password: string) => Promise<void>;
  register: (input: RegisterInput) => Promise<void>;
  signOut: () => Promise<void>;
}

export interface RegisterInput {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  phone?: string;
}

const SessionContext = createContext<SessionValue | null>(null);

export function useSession(): SessionValue {
  const value = useContext(SessionContext);

  if (value === null) {
    throw new Error('useSession must be used inside <SessionProvider>.');
  }

  return value;
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  /*
   * Restore on cold start. The token lives in SecureStore and is attached by the
   * axios request interceptor, so this only has to ask the server who it belongs
   * to -- and that call is also what detects a token revoked while the app was
   * closed. A failure here is the normal "not signed in" path, not an error
   * worth surfacing, so it resolves to null rather than throwing.
   */
  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const { data } = await api.get<{ user: User }>(endpoints.auth.me);
        if (!cancelled) setUser(data.user ?? null);
      } catch {
        if (!cancelled) setUser(null);
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  /*
   * A 401 on any later request means the token died mid-session. The client
   * already clears it; this drops the user object so the guard swaps the
   * navigator over instead of leaving a signed-out user on a private screen
   * that renders empty.
   */
  useEffect(() => {
    setUnauthenticatedHandler(() => setUser(null));
  }, []);

  const signIn = useCallback(async (email: string, password: string) => {
    const { data } = await api.post<{ token: string; user: User }>(endpoints.auth.login, {
      email,
      password,
    });

    await saveToken(data.token);
    setUser(data.user);
  }, []);

  const register = useCallback(async (input: RegisterInput) => {
    const { data } = await api.post<{ token: string; user: User }>(endpoints.auth.register, input);

    await saveToken(data.token);
    setUser(data.user);
  }, []);

  /*
   * Clear locally even if the network call fails. A user who taps "sign out" on
   * a dead connection must not stay signed in on the device -- the server-side
   * token is revoked on the next successful call or expires on its own, and
   * leaving a session live on a handset the user believes they logged out of is
   * the worse of the two failures.
   */
  const signOut = useCallback(async () => {
    try {
      await api.post(endpoints.auth.logout);
    } catch {
      // ignored on purpose; see above
    } finally {
      await clearToken();
      setUser(null);
    }
  }, []);

  const value = useMemo<SessionValue>(
    () => ({ user, isLoading, signIn, register, signOut }),
    [user, isLoading, signIn, register, signOut],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

/** Pulls the field-level messages out of a Laravel 422 for inline display. */
export function fieldErrors(error: unknown): Record<string, string> {
  const bag = (error as ApiError | undefined)?.errors ?? {};
  const flat: Record<string, string> = {};

  for (const [field, messages] of Object.entries(bag)) {
    if (Array.isArray(messages) && messages.length > 0) flat[field] = messages[0];
  }

  return flat;
}
