/**
 * Which server this app talks to.
 *
 * Same problem the staff app already solved, so the same solution: an address
 * baked into an APK cannot be changed on the phone, and when the shop moved hosts
 * every installed copy was still calling the old LAN IP. So the build-time value
 * is only a default and the Profile screen can override it, remembered on the
 * device.
 *
 * The marketplace door is shop/api.php - the same endpoint the web storefront at
 * shop/index.php uses - so the app and the website can never drift apart.
 */

import AsyncStorage from '@react-native-async-storage/async-storage';

export const DEFAULT_SERVER_URL = 'https://smartercollection.shop';
export const DEFAULT_API_PATH = '/shop/api.php';

function trimSlash(url: string): string {
  return url.replace(/\/+$/, '');
}

export function resolveEndpoints(serverUrl: string, apiPath: string) {
  const server = trimSlash(serverUrl.trim());
  const path = (apiPath.trim() || DEFAULT_API_PATH).replace(/\/+$/, '');
  return { serverUrl: server, apiUrl: `${server}${path}` };
}

function buildTimeEndpoints() {
  return resolveEndpoints(
    process.env.EXPO_PUBLIC_SERVER_URL?.trim() || DEFAULT_SERVER_URL,
    process.env.EXPO_PUBLIC_API_PATH?.trim() || DEFAULT_API_PATH,
  );
}

const build = buildTimeEndpoints();

export const SERVER_URL: string = build.serverUrl;
export const API_URL: string = build.apiUrl;

export const SERVER_OVERRIDE_KEY = 'market.serverUrl';
export const API_PATH_OVERRIDE_KEY = 'market.apiPath';

export async function loadOverride(): Promise<{ serverUrl: string; apiUrl: string } | null> {
  try {
    const [savedServer, savedPath] = await Promise.all([
      AsyncStorage.getItem(SERVER_OVERRIDE_KEY),
      AsyncStorage.getItem(API_PATH_OVERRIDE_KEY),
    ]);
    if (!savedServer) return null;
    return resolveEndpoints(savedServer, savedPath ?? DEFAULT_API_PATH);
  } catch {
    return null;
  }
}

export async function saveOverride(serverUrl: string, apiPath: string): Promise<void> {
  const { serverUrl: server } = resolveEndpoints(serverUrl, apiPath);
  if (server === build.serverUrl) {
    await AsyncStorage.multiRemove([SERVER_OVERRIDE_KEY, API_PATH_OVERRIDE_KEY]);
    return;
  }
  await AsyncStorage.multiSet([
    [SERVER_OVERRIDE_KEY, server],
    [API_PATH_OVERRIDE_KEY, (apiPath.trim() || DEFAULT_API_PATH).replace(/\/+$/, '')],
  ]);
}

/**
 * A product image arrives as a full URL (image_url), but a host that was typed by
 * hand can still be relative - and an empty or malformed base would otherwise
 * crash every <Image>. This is the single place that decides whether to render.
 */
export function safeImageUrl(base: string, candidate: string | null): string | null {
  if (!candidate) return null;
  const value = candidate.trim();
  if (value === '') return null;
  if (/^https?:\/\//i.test(value)) {
    // Only trust an absolute URL that really points at the server we chose.
    return value.startsWith(base) ? value : null;
  }
  if (value.startsWith('//') || value.startsWith('data:') || value.startsWith('file:')) return null;
  return `${base}/${value.replace(/^\/+/, '')}`;
}
