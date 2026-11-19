/**
 * One shop's shelf, and one product's page.
 *
 * The catalogue is cached in state by slug, so tapping back from a product does
 * not refetch, and the cart badge survives navigation. Stock shown here is what
 * the server said a moment ago - it is a display number, not a reservation. An
 * order is only confirmed when shop/api.php re-prices and re-checks it.
 *
 * Every picture on these two screens is a file path the shopkeeper typed into
 * the POS. A shop with no chosen cover shows its initials, not a borrowed
 * product photo, because a customer who mistakes one shop's picture for
 * another's product orders the wrong thing.
 */

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { FlatList, Linking, Pressable, ScrollView, Text, View } from 'react-native';
import { fetchCatalogue } from '../api';
import { useLayout } from '../responsive';
import { useApp } from '../state';
import { money } from '../theme';
import {
  Cover,
  EmptyState,
  ErrorNote,
  HeaderAction,
  Icon,
  Loading,
  ProductGridCard,
  ScreenHeader,
  SearchBar,
  Stepper,
  TabBar,
  Thumb,
} from '../ui';
import type { Catalogue } from '../types';

function useCatalogue(slug: string) {
  const { apiUrl, catalogues, rememberCatalogue } = useApp();
  const cached = catalogues[slug];
  const [data, setData] = useState<Catalogue | null>(cached || null);
  const [loading, setLoading] = useState(!cached);
  const [error, setError] = useState('');

  // Every setState here lands after the fetch has been awaited, so the mount
  // effect below never updates state synchronously - React's own lint rule calls
  // that out, and it is right: a synchronous pass re-renders before the network
  // answer exists.
  const load = useCallback(async () => {
    const res = await fetchCatalogue(apiUrl, slug);
    if (res.ok) {
      setData(res.data);
      rememberCatalogue(slug, res.data);
      setError('');
    } else {
      setError(res.message);
    }
    setLoading(false);
  }, [apiUrl, slug, rememberCatalogue]);

  useEffect(() => {
    (async () => {
      await load();
    })();
  }, [load]);

  // The refresh button is an event handler, so it may show the spinner immediately.
  const reload = useCallback(() => {
    setLoading(true);
    (async () => {
      await load();
    })();
  }, [load]);

  return { data, loading, error, reload };
}

function mapsUrl(shop: { address: string | null; area: string | null; name: string }): string {
  const where = [shop.address, shop.area].filter((x) => x && x.trim() !== '').join(', ');
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(where || shop.name)}`;
}

/** The shop's own delivery terms, read from its three columns. */
function termsLine(shop: { delivery_charge: string; free_over: string; min_order: string }): string {
  const delivery = Number(shop.delivery_charge);
  const freeOver = Number(shop.free_over);
  const minOrder = Number(shop.min_order);
  const parts = [`Delivery ${delivery > 0 ? money(delivery) : 'free'}`];
  if (freeOver > 0) parts.push(`free over ${money(freeOver)}`);
  if (minOrder > 0) parts.push(`min order ${money(minOrder)}`);
  return parts.join(' · ');
}

/** One line of the About sheet: an icon, a label, and the value the DB holds. */
function DetailRow({ icon, label, value }: { icon: 'call' | 'location' | 'grid'; label: string; value: string }) {
  const { styles, theme } = useApp();
  return (
    <View style={{ flexDirection: 'row', gap: 11, marginTop: 12 }}>
      <View style={[styles.settingIcon, { width: 34, height: 34, borderRadius: 11 }]}>
        <Icon name={icon} size={16} color={theme.brand} />
      </View>
      <View style={{ flex: 1, minWidth: 0 }}>
        <Text style={styles.faint}>{label}</Text>
        <Text style={styles.title}>{value}</Text>
      </View>
    </View>
  );
}

export function ShopScreen({ slug }: { slug: string }) {
  const { styles, theme, pop, push, cart, cartTotal } = useApp();
  const layout = useLayout();
  const { data, loading, error, reload } = useCatalogue(slug);
  const [term, setTerm] = useState('');
  const [cat, setCat] = useState('');
  const [tab, setTab] = useState<'products' | 'about'>('products');

  const shop = data ? data.shop : null;
  const categories = useMemo(() => {
    const set: string[] = [];
    for (const p of data ? data.products : []) {
      const c = (p.category || '').trim();
      if (c && !set.includes(c)) set.push(c);
    }
    return set.sort();
  }, [data]);

  const shown = useMemo(() => {
    const q = term.trim().toLowerCase();
    return (data ? data.products : []).filter((p) => {
      if (cat && (p.category || '') !== cat) return false;
      if (!q) return true;
      return p.name.toLowerCase().includes(q) || (p.barcode || '').includes(q);
    });
  }, [data, term, cat]);

  const inThisShop = Object.keys(cart[slug] || {}).length;

  if (loading && !data) {
    return (
      <View style={styles.screen}>
        <ScreenHeader title="Shop" onBack={pop} />
        <Loading text="Opening the shop…" />
        <TabBar />
      </View>
    );
  }
  if (error && !data) {
    return (
      <View style={styles.screen}>
        <ScreenHeader title="Shop" onBack={pop} />
        <View style={{ paddingHorizontal: layout.contentPadX, paddingTop: layout.gutter }}>
          <ErrorNote message={error} />
        </View>
        <TabBar />
      </View>
    );
  }

  const open = !!shop && shop.is_open > 0;

  const cover = (
    <View style={{ paddingHorizontal: layout.gutter, paddingTop: 4 }}>
      <View style={{ borderRadius: 22, overflow: 'hidden' }}>
        <Cover uri={shop ? shop.cover_url : null} height={layout.coverHeight} label={shop ? shop.name : ''} />
        {shop ? (
          <View
            style={[
              styles.photoBadge,
              { backgroundColor: open ? 'rgba(15,157,88,0.9)' : 'rgba(220,38,38,0.9)' },
            ]}
          >
            <Text style={styles.photoBadgeText}>{open ? 'Open now' : 'Closed'}</Text>
          </View>
        ) : null}
      </View>
    </View>
  );

  const head = (
    <View>
      {cover}
      <View style={[styles.card, { marginHorizontal: layout.gutter, marginTop: 12, marginBottom: 0 }]}>
        <Text style={styles.titleBold}>{shop ? shop.name : 'Shop'}</Text>
        <Text style={[styles.sub, { marginTop: 4 }]}>
          {data ? `${data.products.length} items online` : ''}
          {shop && shop.area ? ` · ${shop.area}` : ''}
        </Text>
        {shop ? <Text style={[styles.faint, { marginTop: 3 }]}>{termsLine(shop)}</Text> : null}

        <View style={{ flexDirection: 'row', gap: 8, marginTop: 14 }}>
          {shop && shop.phone ? (
            <Pressable
              style={({ pressed }) => [styles.btnGhost, { flex: 1, minWidth: 0 }, pressed ? styles.btnGhostPressed : null]}
              onPress={() => Linking.openURL(`tel:${shop.phone}`)}
            >
              <View style={styles.rowGap}>
                <Icon name="call" size={16} color={theme.brand} />
                <Text style={styles.btnGhostText}>Call</Text>
              </View>
            </Pressable>
          ) : null}
          <Pressable
            style={({ pressed }) => [styles.btn, { flex: 1, minWidth: 0, paddingVertical: 13 }, pressed ? styles.btnPressed : null]}
            onPress={() =>
              Linking.openURL(
                mapsUrl(shop ? { address: shop.address, area: shop.area, name: shop.name } : { address: null, area: null, name: 'Shop' }),
              )
            }
          >
            <View style={styles.rowGap}>
              <Icon name="navigate-outline" size={16} color={theme.onBrand} />
              <Text style={styles.btnText}>Directions</Text>
            </View>
          </Pressable>
        </View>
      </View>

      <View style={{ flexDirection: 'row', marginHorizontal: layout.gutter, marginTop: 14, gap: 4, backgroundColor: theme.chip, borderRadius: 999, padding: 4 }}>
        {(['products', 'about'] as const).map((k) => (
          <Pressable
            key={k}
            onPress={() => setTab(k)}
            style={{ flex: 1, alignItems: 'center', paddingVertical: 9, borderRadius: 999, backgroundColor: tab === k ? theme.card : 'transparent' }}
          >
            <Text style={{ color: tab === k ? theme.brand : theme.sub, fontWeight: '800', fontSize: 13, textTransform: 'capitalize' }}>
              {k}
            </Text>
          </Pressable>
        ))}
      </View>
    </View>
  );

  return (
    <View style={styles.screen}>
      <ScreenHeader
        title={shop ? shop.name : 'Shop'}
        subtitle={data ? `${data.products.length} items online` : undefined}
        onBack={pop}
        right={<HeaderAction name="refresh" onPress={reload} />}
      />

      {tab === 'about' ? (
        <ScrollView contentContainerStyle={{ paddingBottom: layout.bottomSpace }}>
          {head}
          <View style={{ padding: layout.gutter }}>
            <View style={styles.card}>
              <Text style={styles.sectionTitle}>Contact & address</Text>
              <DetailRow icon="call" label="Phone" value={shop && shop.phone ? shop.phone : 'Not given'} />
              <DetailRow icon="location" label="Address" value={(shop && shop.address) || 'Not given'} />
              {shop && shop.area ? <DetailRow icon="location" label="Area" value={shop.area} /> : null}
            </View>

            <View style={styles.card}>
              <Text style={styles.sectionTitle}>Delivery terms</Text>
              {shop ? (
                <>
                  <View style={[styles.rowBetween, { marginTop: 10 }]}>
                    <Text style={styles.sub}>Delivery charge</Text>
                    <Text style={styles.title}>{Number(shop.delivery_charge) > 0 ? money(shop.delivery_charge) : 'Free'}</Text>
                  </View>
                  {Number(shop.free_over) > 0 ? (
                    <View style={styles.rowBetween}>
                      <Text style={styles.sub}>Free delivery over</Text>
                      <Text style={styles.title}>{money(shop.free_over)}</Text>
                    </View>
                  ) : null}
                  {Number(shop.min_order) > 0 ? (
                    <View style={styles.rowBetween}>
                      <Text style={styles.sub}>Minimum order</Text>
                      <Text style={styles.title}>{money(shop.min_order)}</Text>
                    </View>
                  ) : null}
                </>
              ) : (
                <Text style={styles.sub}>The shop has not published delivery terms.</Text>
              )}
            </View>

            <View style={styles.card}>
              <Text style={styles.sectionTitle}>Categories in this shop</Text>
              {categories.length ? (
                <View style={[styles.chipRow, { marginTop: 10, marginBottom: 0 }]}>
                  {categories.map((c) => (
                    <View key={c} style={[styles.chip, { backgroundColor: theme.chip }]}>
                      <Text style={styles.chipText}>{c}</Text>
                    </View>
                  ))}
                </View>
              ) : (
                <Text style={[styles.sub, { marginTop: 10 }]}>Nothing here has a category yet.</Text>
              )}
            </View>

            {shop && !open ? (
              <View style={[styles.card, { borderLeftWidth: 3, borderLeftColor: theme.danger }]}>
                <Text style={styles.danger}>This shop is closed right now and is not taking orders.</Text>
              </View>
            ) : null}
          </View>
        </ScrollView>
      ) : (
        <FlatList
          key={`${slug}-${cat}`}
          numColumns={layout.cols}
          columnWrapperStyle={{ gap: layout.gap, marginBottom: layout.gap }}
          contentContainerStyle={{ paddingHorizontal: layout.gutter, paddingBottom: layout.bottomSpace }}
          data={shown}
          keyExtractor={(p) => String(p.id)}
          renderItem={({ item }) => <ProductGridCard slug={slug} product={item} />}
          ListHeaderComponent={
            <View>
              {head}
              <View style={{ padding: layout.gutter, gap: 8 }}>
                {shop && !open ? (
                  <View style={[styles.card, { marginBottom: 0, borderLeftWidth: 3, borderLeftColor: theme.danger }]}>
                    <Text style={styles.danger}>This shop is closed right now and is not taking orders.</Text>
                  </View>
                ) : null}
                <SearchBar value={term} onChange={setTerm} placeholder="Search this shop…" />
                {categories.length > 1 ? (
                  <View style={[styles.chipRow, { marginTop: 4, marginBottom: 0 }]}>
                    <Pressable style={[styles.chip, cat === '' ? styles.chipOn : null]} onPress={() => setCat('')}>
                      <Text style={cat === '' ? styles.chipTextOn : styles.chipText}>All</Text>
                    </Pressable>
                    {categories.map((c) => (
                      <Pressable key={c} style={[styles.chip, cat === c ? styles.chipOn : null]} onPress={() => setCat(c)}>
                        <Text style={cat === c ? styles.chipTextOn : styles.chipText}>{c}</Text>
                      </Pressable>
                    ))}
                  </View>
                ) : null}
              </View>
            </View>
          }
          ListEmptyComponent={<EmptyState icon="cube-outline" text="Nothing is online here yet." />}
        />
      )}

      {inThisShop > 0 ? (
        <Pressable
          style={({ pressed }) => [styles.floatBar, { left: layout.gutter, right: layout.gutter, bottom: layout.barFloor }, pressed ? { opacity: 0.93 } : null]}
          onPress={() => push({ name: 'cart' })}
        >
          <Icon name="cart" size={19} color={theme.onBrand} />
          <Text style={[styles.floatText, { flex: 1, marginLeft: 10 }]} numberOfLines={1}>
            {inThisShop} item{inThisShop === 1 ? '' : 's'} · {money(cartTotal(slug))}
          </Text>
          <View style={styles.rowGap}>
            <Text style={[styles.floatText, { flexShrink: 0 }]}>View cart</Text>
            <Icon name="chevron-forward" size={16} color={theme.onBrand} />
          </View>
        </Pressable>
      ) : null}
      <TabBar />
    </View>
  );
}

export function ProductScreen({ slug, productId }: { slug: string; productId: number }) {
  const { styles, theme, pop, push, addToCart, cart } = useApp();
  const layout = useLayout();
  const { data, loading, error } = useCatalogue(slug);
  const [qty, setQty] = useState(1);
  const product = data ? data.products.find((p) => p.id === productId) : undefined;
  const shop = data ? data.shop : null;
  const already = cart[slug] && cart[slug][productId] ? cart[slug][productId].qty : 0;

  if (loading && !data) {
    return (
      <View style={styles.screen}>
        <ScreenHeader title="Product" onBack={pop} />
        <Loading />
      </View>
    );
  }
  if (error && !product) {
    return (
      <View style={styles.screen}>
        <ScreenHeader title="Product" onBack={pop} />
        <View style={{ padding: layout.gutter }}>
          <ErrorNote message={error} />
        </View>
      </View>
    );
  }
  if (!product) {
    return (
      <View style={styles.screen}>
        <ScreenHeader title="Product" onBack={pop} />
        <EmptyState icon="cube-outline" text="This product is no longer online." />
      </View>
    );
  }

  const max = Math.max(1, product.stock);
  return (
    <View style={styles.screen}>
      <ScreenHeader
        title={product.name}
        onBack={pop}
        right={<HeaderAction name="cart-outline" onPress={() => push({ name: 'cart' })} />}
      />
      <ScrollView contentContainerStyle={{ paddingBottom: layout.bottomSpace }} showsVerticalScrollIndicator={false}>
        <View style={{ paddingHorizontal: layout.gutter, paddingTop: 4 }}>
          <View style={{ borderRadius: 22, overflow: 'hidden' }}>
            <Thumb uri={product.image_url} height={layout.pageImageHeight} label={product.name} wide radius={0} />
          </View>
        </View>

        <View style={[styles.card, { marginHorizontal: layout.gutter, marginTop: 12, marginBottom: 0 }]}>
          <View style={[styles.rowBetween, { gap: 8 }]}>
            <Text style={[styles.titleBold, { flex: 1, minWidth: 0 }]} numberOfLines={2}>
              {product.name}
            </Text>
            <View style={[styles.statusPill, { backgroundColor: product.in_stock ? theme.okSoft : theme.dangerSoft, flexShrink: 0 }]}>
              <View style={[styles.statusDot, { backgroundColor: product.in_stock ? theme.ok : theme.danger }]} />
              <Text style={[styles.statusText, { color: product.in_stock ? theme.ok : theme.danger }]}>
                {product.in_stock ? 'In stock' : 'Out of stock'}
              </Text>
            </View>
          </View>
          <Text style={[styles.faint, { marginTop: 4 }]}>
            {product.category ? `${product.category} · ` : ''}
            {product.in_stock ? `${product.stock} available${product.unit ? ` ${product.unit}` : ''}` : ''}
          </Text>
          <Text style={[styles.priceBig, { marginTop: 10 }]}>{money(product.price)}</Text>
        </View>

        {shop ? (
          <Pressable
            style={({ pressed }) => [styles.card, { marginHorizontal: layout.gutter }, pressed ? { backgroundColor: theme.cardAlt } : null]}
            onPress={() => push({ name: 'shop', slug })}
          >
            <View style={[styles.rowGap, { gap: layout.gap }]}>
              <View style={{ width: 46, height: 46, borderRadius: 14, overflow: 'hidden' }}>
                <Cover uri={shop.cover_url} height={46} width={46} label={shop.name} />
              </View>
              <View style={{ flex: 1, minWidth: 0 }}>
                <Text style={styles.title} numberOfLines={1}>
                  {shop.name}
                </Text>
                <Text style={styles.faint} numberOfLines={2}>
                  {shop.area || 'Local shop'} · {termsLine(shop)}
                </Text>
              </View>
              <Icon name="chevron-forward" size={18} color={theme.faint} />
            </View>
          </Pressable>
        ) : null}

        <View style={[styles.card, { marginHorizontal: layout.gutter }]}>
          <Text style={styles.sectionTitle}>Product details</Text>
          <Text style={[styles.sub, { marginTop: 8, lineHeight: 20 }]}>
            {product.description && product.description.trim() !== ''
              ? product.description
              : 'The shop has not written a description for this item.'}
          </Text>
          {product.barcode ? (
            <>
              <View style={styles.divider} />
              <View style={[styles.rowBetween, { gap: 10 }]}>
                <Text style={styles.sub}>Barcode</Text>
                <Text style={[styles.title, { textAlign: 'right', flexShrink: 1 }]} numberOfLines={1}>
                  {product.barcode}
                </Text>
              </View>
            </>
          ) : null}
          {product.unit ? (
            <View style={[styles.rowBetween, { gap: 10 }]}>
              <Text style={styles.sub}>Unit</Text>
              <Text style={styles.title}>{product.unit}</Text>
            </View>
          ) : null}
        </View>

        <View style={{ flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 12, paddingHorizontal: layout.gutter }}>
          <Stepper qty={qty} max={max} onChange={(n) => setQty(Math.max(1, n))} />
          {already > 0 ? <Text style={styles.faint}>{already} already in cart</Text> : null}
        </View>
      </ScrollView>

      <View style={{ position: 'absolute', left: layout.gutter, right: layout.gutter, bottom: layout.barFloor }}>
        <Pressable
          disabled={!product.in_stock}
          style={({ pressed }) => [
            styles.btn,
            !product.in_stock ? styles.btnDisabled : null,
            pressed && product.in_stock ? styles.btnPressed : null,
          ]}
          onPress={() => {
            addToCart(slug, product, qty);
            push({ name: 'cart' });
          }}
        >
          <View style={styles.rowGap}>
            <Icon name="cart-outline" size={18} color={theme.onBrand} />
            <Text style={[styles.btnText, { maxWidth: '100%' }]} numberOfLines={1}>
              {product.in_stock ? `Add to cart · ${money(product.price * qty)}` : 'Not available'}
            </Text>
          </View>
        </Pressable>
      </View>
      <TabBar />
    </View>
  );
}
