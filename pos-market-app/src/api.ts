/**
 * The only place the app talks to the server.
 *
 * Everything goes through shop/api.php, the same public JSON door the web
 * storefront uses. Prices, stock and delivery charges are re-derived on the
 * server when an order is placed, so the numbers shown here are for display and
 * the receipt the shop sees is whatever the database said at that moment.
 */

import type { ApiOrderPlaced, ApiResult, BrowseResult, Catalogue, OrderStatus, ShopRow } from './types';

const TIMEOUT_MS = 15000;

async function request<T>(url: string, init?: RequestInit): Promise<ApiResult<T>> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
  try {
    const res = await fetch(url, { ...init, signal: controller.signal });
    // A server with no marketplace installed answers a missing endpoint with its
    // own 404 page. Saying "the shop is not on this server" is actionable; saying
    // "I could not read the answer" sends the customer to a developer.
    if (res.status === 404) {
      return { ok: false, message: `This server has no marketplace yet (404 at ${url}).` };
    }
    // A PHP warning printed before the JSON, or a login page instead of the API,
    // both arrive as text that is not JSON. Read as text first so the failure is
    // reported as "the server did not answer" rather than a parse crash.
    const raw = await res.text();
    const body = raw.replace(/^\uFEFF/, '').trim();
    let data: any = null;
    try {
      data = body === '' ? null : JSON.parse(body);
    } catch {
      return { ok: false, message: `The server sent a page instead of an answer (HTTP ${res.status}). Check the address in Profile.` };
    }
    if (!data || typeof data !== 'object') {
      return { ok: false, message: `The server sent a page instead of an answer (HTTP ${res.status}). Check the address in Profile.` };
    }
    if (data.success !== true) {
      return { ok: false, message: String(data.message || 'Something went wrong. Please try again.') };
    }
    if (!res.ok && res.status >= 400 && data.order == null && data.products == null && data.shops == null) {
      return { ok: false, message: String(data.message || 'Request failed.') };
    }
    return { ok: true, data: data as T };
  } catch (e: any) {
    if (e && e.name === 'AbortError') {
      return { ok: false, message: 'The shop server took too long to answer.' };
    }
    return { ok: false, message: 'Cannot reach the shop server. Check the address in Profile.' };
  } finally {
    clearTimeout(timer);
  }
}

export function fetchShops(apiUrl: string): Promise<ApiResult<{ shops: ShopRow[] }>> {
  return request(`${apiUrl}?action=shops`);
}

export function fetchCatalogue(apiUrl: string, slug: string): Promise<ApiResult<Catalogue>> {
  return request(`${apiUrl}?action=catalogue&slug=${encodeURIComponent(slug)}`);
}

export type BrowseQuery = { q?: string; category?: string; sort?: string; limit?: number; offset?: number };

/**
 * The marketplace-wide shelf: categories and products from every shop at once.
 *
 * Home, Categories and the search screen all read this one call rather than
 * fetching each shop's catalogue, which on a customer's data plan would be a
 * request per shop just to draw a category strip.
 */
export function fetchBrowse(apiUrl: string, query: BrowseQuery = {}): Promise<BrowseResult> {
  const params = ['action=browse'];
  if (query.q) params.push(`q=${encodeURIComponent(query.q)}`);
  if (query.category) params.push(`category=${encodeURIComponent(query.category)}`);
  if (query.sort) params.push(`sort=${encodeURIComponent(query.sort)}`);
  if (query.limit != null) params.push(`limit=${Math.max(1, Math.min(200, query.limit))}`);
  if (query.offset) params.push(`offset=${Math.max(0, query.offset)}`);
  return request(`${apiUrl}?${params.join('&')}`);
}

export type PlaceOrderBody = {
  name: string;
  phone: string;
  address: string;
  note: string;
  items: { id: number; qty: number }[];
};

export function placeOrder(
  apiUrl: string,
  slug: string,
  body: PlaceOrderBody,
): Promise<ApiOrderPlaced> {
  return request(
    `${apiUrl}?action=order&slug=${encodeURIComponent(slug)}`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    },
  );
}

export function fetchStatus(
  apiUrl: string,
  orderNo: string,
  phone: string,
): Promise<ApiResult<{ order: OrderStatus }>> {
  return request(
    `${apiUrl}?action=status&order_no=${encodeURIComponent(orderNo)}&phone=${encodeURIComponent(phone)}`,
  );
}
