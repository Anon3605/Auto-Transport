import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';

/**
 * Token storage with a platform split.
 *
 * expo-secure-store has NO web implementation (SDK 57 docs: "Web is not
 * supported") -- calling it under `expo start --web` throws rather than
 * degrading, and app.json configures a web bundler, so the web target is a
 * real one here. Native therefore goes to Keychain/Keystore and web falls back
 * to localStorage.
 *
 * That fallback is deliberately weaker and worth being honest about:
 * localStorage is readable by any script that achieves XSS on the origin. It is
 * acceptable for a browser preview of a mobile app; if the web build ever
 * becomes a product surface, move the session to an httpOnly cookie instead of
 * hardening this file, because a token JavaScript can read is a token XSS can
 * steal no matter where it is stashed.
 */

const isWeb = Platform.OS === 'web';

/** localStorage is absent during SSR/static export, so every access is guarded. */
const webStorage = {
  getItem(key: string): string | null {
    if (typeof localStorage === 'undefined') return null;
    return localStorage.getItem(key);
  },
  setItem(key: string, value: string): void {
    if (typeof localStorage === 'undefined') return;
    localStorage.setItem(key, value);
  },
  removeItem(key: string): void {
    if (typeof localStorage === 'undefined') return;
    localStorage.removeItem(key);
  },
};

export async function getItem(key: string): Promise<string | null> {
  if (isWeb) return webStorage.getItem(key);
  return SecureStore.getItemAsync(key);
}

export async function setItem(key: string, value: string): Promise<void> {
  if (isWeb) return webStorage.setItem(key, value);
  return SecureStore.setItemAsync(key, value);
}

export async function removeItem(key: string): Promise<void> {
  if (isWeb) return webStorage.removeItem(key);
  return SecureStore.deleteItemAsync(key);
}
