/**
 * App-wide state: theme, which server we are on, the cart, the buyer's own
 * details, the orders this phone placed, and the screen stack.
 *
 * Deliberately a plain context rather than a state library. The staff app grew to
 * 2 300 lines in one file because screens reached into each other; here every
 * screen gets its data from one place, and the cart is keyed by shop slug, which
 * is what makes a multi-shop basket possible at all.
 */

import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { API_URL, SERVER_URL, loadOverride, saveOverride } from './config';
import { darkPalette, lightPalette, makeStyles, type Palette, type Styles } from './theme';
import type { Buyer, Catalogue, CategoryRow, ProductRow, SavedOrder } from './types';

const CART_KEY = 'market.cart';
const BUYER_KEY = 'market.buyer';
const ORDERS_KEY = 'market.orders';
const THEME_KEY = 'market.theme';

export type CartLine = { product: ProductRow; qty: number };
/** slug -> productId -> line. A basket that spans shops is the whole point. */
export type Cart = Record<string, Record<number, CartLine>>;

export type Route =
  | { name: 'home' }
  | { name: 'categories' }
  | { name: 'shops' }
  | { name: 'listing'; q?: string; category?: string }
  | { name: 'shop'; slug: string }
  | { name: 'product'; slug: string; productId: number }
  | { name: 'cart' }
  | { name: 'checkout' }
  | { name: 'orders' }
  | { name: 'profile' };

/** The five destinations the bottom bar offers. Shop, product, listing and orders are pushed. */
export type TabName = 'home' | 'categories' | 'shops' | 'cart' | 'profile';

// A table rather than `[{ name }]`: building the object from a union of names
// loses which member of the route union it is, and TypeScript is right to
// complain - only these five routes are tab-shaped, so only these five map.
const TAB_ROUTES: Record<TabName, Route> = {
  home: { name: 'home' },
  categories: { name: 'categories' },
  shops: { name: 'shops' },
  cart: { name: 'cart' },
  profile: { name: 'profile' },
};

type AppValue = {
  theme: Palette;
  styles: Styles;
  isDark: boolean;
  toggleTheme: () => void;

  serverUrl: string;
  apiUrl: string;
  setServer: (url: string, path: string) => Promise<void>;

  routes: Route[];
  route: Route;
  push: (r: Route) => void;
  pop: () => void;
  goToTab: (name: TabName) => void;
  canGoBack: boolean;

  /** False until this phone's stored cart, address and server have been read. */
  ready: boolean;

  catalogues: Record<string, Catalogue>;
  rememberCatalogue: (slug: string, data: Catalogue) => void;
  forgetCatalogue: (slug: string) => void;

  /** Every shop's published categories, cached once the first screen asks for them. */
  categories: CategoryRow[];
  rememberCategories: (list: CategoryRow[]) => void;

  cart: Cart;
  addToCart: (slug: string, product: ProductRow, qty?: number) => void;
  setQty: (slug: string, productId: number, qty: number) => void;
  clearShop: (slug: string) => void;
  clearCart: () => void;
  cartItemCount: number;
  cartShopCount: number;
  shopLines: (slug: string) => CartLine[];
  cartTotal: (slug?: string) => number;

  buyer: Buyer;
  setBuyer: (b: Buyer) => void;

  orders: SavedOrder[];
  rememberOrders: (list: SavedOrder[]) => void;
  /** Wiping this phone's history, which is what "Log out" means with no account. */
  clearOrders: () => void;
};

const AppContext = createContext<AppValue | null>(null);

export function useApp(): AppValue {
  const value = useContext(AppContext);
  if (!value) throw new Error('useApp must be used inside <AppProvider>');
  return value;
}

function readCart(raw: string | null): Cart {
  if (!raw) return {};
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? (parsed as Cart) : {};
  } catch {
    return {};
  }
}

export function AppProvider({ children }: { children: React.ReactNode }) {
  const [isDark, setIsDark] = useState(false);
  const [serverUrl, setServerUrl] = useState<string>(SERVER_URL);
  const [apiUrl, setApiUrl] = useState<string>(API_URL);
  const [routes, setRoutes] = useState<Route[]>([{ name: 'home' }]);
  const [catalogues, setCatalogues] = useState<Record<string, Catalogue>>({});
  const [categories, setCategories] = useState<CategoryRow[]>([]);
  const [cart, setCart] = useState<Cart>({});
  const [buyer, setBuyerState] = useState<Buyer>({ name: '', phone: '', address: '' });
  const [orders, setOrders] = useState<SavedOrder[]>([]);
  const [loaded, setLoaded] = useState(false);

  // One read of everything stored on the device, before the first paint of a
  // screen that would otherwise overwrite storage with its empty defaults.
  useEffect(() => {
    (async () => {
      try {
        const [themeRaw, cartRaw, buyerRaw, ordersRaw, override] = await Promise.all([
          AsyncStorage.getItem(THEME_KEY),
          AsyncStorage.getItem(CART_KEY),
          AsyncStorage.getItem(BUYER_KEY),
          AsyncStorage.getItem(ORDERS_KEY),
          loadOverride(),
        ]);
        setIsDark(themeRaw === 'dark');
        setCart(readCart(cartRaw));
        if (buyerRaw) {
          const b = JSON.parse(buyerRaw);
          if (b && typeof b === 'object') {
            setBuyerState({ name: String(b.name ?? ''), phone: String(b.phone ?? ''), address: String(b.address ?? '') });
          }
        }
        if (ordersRaw) {
          const o = JSON.parse(ordersRaw);
          if (Array.isArray(o)) setOrders(o.filter((x) => x && typeof x.order_no === 'string'));
        }
        if (override) {
          setServerUrl(override.serverUrl);
          setApiUrl(override.apiUrl);
        }
      } catch {
        // A device with broken storage still runs on the build-time defaults.
      } finally {
        setLoaded(true);
      }
    })();
  }, []);

  useEffect(() => {
    if (loaded) AsyncStorage.setItem(CART_KEY, JSON.stringify(cart)).catch(() => {});
  }, [cart, loaded]);
  useEffect(() => {
    if (loaded) AsyncStorage.setItem(BUYER_KEY, JSON.stringify(buyer)).catch(() => {});
  }, [buyer, loaded]);
  useEffect(() => {
    if (loaded) AsyncStorage.setItem(ORDERS_KEY, JSON.stringify(orders)).catch(() => {});
  }, [orders, loaded]);
  useEffect(() => {
    if (loaded) AsyncStorage.setItem(THEME_KEY, isDark ? 'dark' : 'light').catch(() => {});
  }, [isDark, loaded]);

  const theme: Palette = isDark ? darkPalette : lightPalette;
  const styles = useMemo(() => makeStyles(theme), [theme]);

  const push = useCallback((r: Route) => setRoutes((prev) => [...prev.slice(-12), r]), []);
  const pop = useCallback(() => setRoutes((prev) => (prev.length > 1 ? prev.slice(0, -1) : prev)), []);
  const goToTab = useCallback((name: TabName) => setRoutes([TAB_ROUTES[name]]), []);

  const rememberCatalogue = useCallback((slug: string, data: Catalogue) => {
    setCatalogues((prev) => ({ ...prev, [slug]: data }));
  }, []);
  const forgetCatalogue = useCallback((slug: string) => {
    setCatalogues((prev) => {
      const next = { ...prev };
      delete next[slug];
      return next;
    });
  }, []);

  const rememberCategories = useCallback((list: CategoryRow[]) => {
    setCategories(list);
  }, []);

  const addToCart = useCallback((slug: string, product: ProductRow, qty = 1) => {
    setCart((prev) => {
      const shopCart = { ...(prev[slug] || {}) };
      const existing = shopCart[product.id];
      const wanted = (existing ? existing.qty : 0) + qty;
      // Never let the basket claim more than the shop published; the server will
      // refuse it anyway, and an error after tapping Add reads as a broken app.
      shopCart[product.id] = { product, qty: Math.max(1, Math.min(wanted, Math.max(1, product.stock))) };
      return { ...prev, [slug]: shopCart };
    });
  }, []);

  const setQty = useCallback((slug: string, productId: number, qty: number) => {
    setCart((prev) => {
      const shopCart = { ...(prev[slug] || {}) };
      const line = shopCart[productId];
      if (!line) return prev;
      if (qty <= 0) {
        delete shopCart[productId];
      } else {
        shopCart[productId] = { ...line, qty: Math.min(qty, Math.max(1, line.product.stock)) };
      }
      const next = { ...prev };
      if (Object.keys(shopCart).length === 0) delete next[slug];
      else next[slug] = shopCart;
      return next;
    });
  }, []);

  const clearShop = useCallback((slug: string) => {
    setCart((prev) => {
      const next = { ...prev };
      delete next[slug];
      return next;
    });
  }, []);

  const clearCart = useCallback(() => setCart({}), []);

  const shopLines = useCallback(
    (slug: string): CartLine[] => Object.values(cart[slug] || {}).sort((a, b) => a.product.name.localeCompare(b.product.name)),
    [cart],
  );

  const cartTotal = useCallback(
    (slug?: string) => {
      const slugs = slug ? [slug] : Object.keys(cart);
      let sum = 0;
      for (const s of slugs) {
        for (const line of Object.values(cart[s] || {})) sum += Number(line.product.price) * line.qty;
      }
      return Math.round(sum * 100) / 100;
    },
    [cart],
  );

  const cartItemCount = useMemo(() => {
    let n = 0;
    for (const shopCart of Object.values(cart)) {
      for (const line of Object.values(shopCart)) n += line.qty;
    }
    return n;
  }, [cart]);

  const cartShopCount = useMemo(() => Object.keys(cart).length, [cart]);

  const rememberOrders = useCallback((list: SavedOrder[]) => {
    setOrders((prev) => {
      const byNo: Record<string, SavedOrder> = {};
      for (const o of prev) byNo[o.order_no] = o;
      for (const o of list) byNo[o.order_no] = o;
      return Object.values(byNo).sort((a, b) => (a.placed_at < b.placed_at ? 1 : -1)).slice(0, 60);
    });
  }, []);

  const setServer = useCallback(async (url: string, path: string) => {
    await saveOverride(url, path);
    const resolved = await loadOverride();
    if (resolved) {
      setServerUrl(resolved.serverUrl);
      setApiUrl(resolved.apiUrl);
    } else {
      setServerUrl(SERVER_URL);
      setApiUrl(API_URL);
    }
    // Cached catalogues belong to the server we just left.
    setCatalogues({});
    setCategories([]);
  }, []);

  const clearOrders = useCallback(() => setOrders([]), []);

  const value: AppValue = {
    theme,
    styles,
    isDark,
    toggleTheme: () => setIsDark((v) => !v),
    serverUrl,
    apiUrl,
    setServer,
    routes,
    route: routes[routes.length - 1],
    push,
    pop,
    goToTab,
    canGoBack: routes.length > 1,
    ready: loaded,
    catalogues,
    rememberCatalogue,
    forgetCatalogue,
    categories,
    rememberCategories,
    cart,
    addToCart,
    setQty,
    clearShop,
    clearCart,
    cartItemCount,
    cartShopCount,
    shopLines,
    cartTotal,
    buyer,
    setBuyer: setBuyerState,
    orders,
    rememberOrders,
    clearOrders,
  };

  return <AppContext.Provider value={value}>{children}</AppContext.Provider>;
}
