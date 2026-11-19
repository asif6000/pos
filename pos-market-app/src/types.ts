/**
 * The shapes the public shop API (shop/api.php) actually returns.
 *
 * Written from the endpoint's own output, not from an idealised schema. A money
 * column that PDO hands back as a string ("delivery_charge":"50.00") stays a
 * string here, and a price that PHP already casts to a float is a number, so the
 * screen that does arithmetic on it has to say so at compile time instead of in a
 * customer's bill.
 */

/** Every call answers with this: either parsed JSON, or a message to show a human. */
export type ApiResult<T> = { ok: true; data: T } | { ok: false; message: string };

export type ApiOrderPlaced = ApiResult<{ success: true; order: OrderResult; message?: string }>;

export type ShopRow = {
  slug: string;
  name: string;
  phone: string | null;
  area: string | null;
  is_open: number;
  products: number;
  cover: string | null;
  cover_url: string | null;
};

export type ShopInfo = {
  owner_id: number;
  slug: string;
  name: string;
  phone: string | null;
  address: string | null;
  area: string | null;
  delivery_charge: string;
  free_over: string;
  min_order: string;
  is_open: number;
  cover: string | null;
  /** Absolute picture URL built by the server; the shop may never have chosen one. */
  cover_url: string | null;
};

export type ProductRow = {
  id: number;
  name: string;
  barcode: string | null;
  image: string | null;
  image_url: string | null;
  category: string | null;
  unit: string | null;
  description: string | null;
  price: number;
  stock: number;
  in_stock: boolean;
};

export type Catalogue = { shop: ShopInfo; products: ProductRow[] };

/**
 * One published category, counted across every shop by ?action=browse.
 *
 * `image_url` is the photo of a product that really sits in that category, or
 * null when none of them has been photographed - the app draws a plain tile in
 * that case rather than inventing an icon.
 */
export type CategoryRow = { name: string; products: number; shops: number; image_url?: string | null };

/**
 * A product from the marketplace-wide shelf: the shop's own fields plus which shop
 * it came from, because a customer adds to cart by shop and an order can only ever
 * go to one till.
 */
export type BrowseProduct = ProductRow & {
  shop_slug: string;
  shop_name: string;
  shop_area: string | null;
  shop_open: number;
  sold: number;
};

export type BrowseResult = ApiResult<{ categories: CategoryRow[]; products: BrowseProduct[]; total: number }>;

export type OrderResult = {
  order_no: string;
  items_total: number;
  delivery: number;
  total: number;
};

export type OrderItem = {
  product_id: number;
  product_name: string;
  quantity: number;
  unit_price: string;
  total_price: string;
};

export type OrderStatus = {
  order_no: string;
  status: 'pending' | 'accepted' | 'rejected' | 'delivered' | string;
  items_total: string;
  delivery_charge: string;
  total: string;
  store_id: number | null;
  created_at: string;
  items: OrderItem[];
};

/** An order this phone placed, kept on the device so the Orders tab can look it up. */
export type SavedOrder = {
  order_no: string;
  slug: string;
  shop_name: string;
  phone: string;
  total: number;
  placed_at: string;
};

export type Buyer = {
  name: string;
  phone: string;
  address: string;
};
