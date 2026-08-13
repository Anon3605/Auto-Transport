import axios, { AxiosError } from 'axios';

import { getItem, removeItem, setItem } from '@/src/lib/storage';

const TOKEN_KEY = 'autotrans.token';

export const api = axios.create({
  baseURL: process.env.EXPO_PUBLIC_API_URL ?? 'http://localhost:8000/api/v1',
  timeout: 15000,
  headers: { Accept: 'application/json' },
});

// Sanctum personal access token. On native this is Keychain (iOS) / Keystore
// (Android) via expo-secure-store; never AsyncStorage, which is plaintext on a
// rooted or jailbroken device. See src/lib/storage.ts for the web fallback and
// why it is weaker.
api.interceptors.request.use(async (config) => {
  const token = await getItem(TOKEN_KEY);
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

let onUnauthenticated: (() => void) | null = null;
export const setUnauthenticatedHandler = (fn: () => void) => { onUnauthenticated = fn; };

api.interceptors.response.use(
  (r) => r,
  async (error: AxiosError) => {
    if (error.response?.status === 401) {
      await removeItem(TOKEN_KEY);
      onUnauthenticated?.();
    }
    return Promise.reject(normalizeError(error));
  },
);

export interface ApiError {
  message: string;
  status: number | null;
  errors: Record<string, string[]>;   // Laravel 422 validation bag
}

function normalizeError(error: AxiosError<any>): ApiError {
  return {
    message: error.response?.data?.message ?? error.message ?? 'Network error',
    status: error.response?.status ?? null,
    errors: error.response?.data?.errors ?? {},
  };
}

export const saveToken = (t: string) => setItem(TOKEN_KEY, t);
export const clearToken = () => removeItem(TOKEN_KEY);
