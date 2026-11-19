/**
 * The three browsing doors: Home (what the marketplace has, at a glance),
 * Categories (every published category) and Shops (every shop).
 *
 * Home and Categories read ?action=browse, which spans shops on purpose; Shops
 * reads ?action=shops. Both are public views of what a shopkeeper already chose
 * to publish, and neither is a second copy of a price or a stock count - the
 * server re-derives those when an order is placed.
 *
 * Everything a card shows here came out of a database column: the category's
 * picture is one of the products listed under that name, a shop's cover is the
 * one its owner picked in Online Shop, and the counts are counts.
 */

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { FlatList, Linking, Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { fetchShops } from '../api';
import { useLayout } from '../responsive';
import { useApp } from '../state';
import { useBrowse } from '../useBrowse';
import {
  CategoryTile,
  Cover,
  EmptyState,
  ErrorNote,
  HeaderAction,
  Icon,
  Loading,
  ProductGridCard,
  ScreenHeader,
  SearchBar,
  SectionHead,
  TabBar,
} from '../ui';
import type { ShopRow } from '../types';

function useShops() {
  const { apiUrl } = useApp();
  const [shops, setShops] = useState<ShopRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  // The setState calls all land after the await, so mounting Home does not update
  // state synchronously inside the effect below.
  const load = useCallback(async () => {
    const res = await fetchShops(apiUrl);
    if (res.ok) {
      setShops(res.data.shops || []);
      setError('');
    } else {
      setShops([]);
      setError(res.message);
    }
    setLoading(false);
  }, [apiUrl]);

  useEffect(() => {
    (async () => {
      await load();
    })();
  }, [load]);

  // Pull-to-refresh belongs to an event handler, so it may show the spinner
  // immediately; the fetch itself cannot show one before it has been answered.
  const reload = useCallback(() => {
    setLoading(true);
    (async () => {
      await load();
    })();
  }, [load]);

  return { shops, loading, error, reload };
}

function matchesArea(shop: ShopRow, area: string) {
  if (!area) return true;
  return (shop.area || '').trim().toLowerCase() === area.toLowerCase();
}

/** The dot and word that tell a customer whether this shop is taking orders. */
function OpenTag({ isOpen }: { isOpen: boolean }) {
  const { theme, styles } = useApp();
  const color = isOpen ? theme.ok : theme.danger;
  return (
    <View style={[styles.statusPill, { backgroundColor: isOpen ? theme.okSoft : theme.dangerSoft, paddingVertical: 3 }]}>
      <View style={[styles.statusDot, { backgroundColor: color }]} />
      <Text style={[styles.statusText, { color }]}>{isOpen ? 'Open now' : 'Closed'}</Text>
    </View>
  );
}

/** The horizontal card on Home's "Featured Shops" rail. */
function ShopTile({ shop }: { shop: ShopRow }) {
  const { styles, push } = useApp();
  const layout = useLayout();
  const width = layout.railCardWidth;
  return (
    <Pressable
      style={[styles.cardFlat, { width, marginBottom: 0, overflow: 'hidden' }]}
      onPress={() => push({ name: 'shop', slug: shop.slug })}
    >
      <Cover uri={shop.cover_url} height={Math.round(width * 0.62)} label={shop.name} />
      <View style={{ padding: 11, gap: 4 }}>
        <Text style={styles.title} numberOfLines={1}>
          {shop.name}
        </Text>
        <View style={styles.rowGap}>
          <OpenTag isOpen={shop.is_open > 0} />
          <Text style={styles.faint} numberOfLines={1}>
            {shop.products} items
          </Text>
        </View>
        {shop.area ? (
          <Text style={styles.faint} numberOfLines={1}>
            {shop.area}
          </Text>
        ) : null}
      </View>
    </Pressable>
  );
}

/** The full-width shop row on the Shops tab. */
function ShopCard({ shop }: { shop: ShopRow }) {
  const { styles, theme, push } = useApp();
  const layout = useLayout();
  const coverWidth = layout.rowCoverWidth;
  return (
    <Pressable style={styles.rowCard} onPress={() => push({ name: 'shop', slug: shop.slug })}>
      <Cover uri={shop.cover_url} height={Math.round(coverWidth * 0.86)} width={coverWidth} label={shop.name} />
      <View style={{ flex: 1, minWidth: 0, gap: 4 }}>
        <Text style={styles.titleBold} numberOfLines={1}>
          {shop.name}
        </Text>
        <Text style={styles.faint} numberOfLines={1}>
          {shop.products} product{shop.products === 1 ? '' : 's'} online
          {shop.area ? ` · ${shop.area}` : ''}
        </Text>
        <OpenTag isOpen={shop.is_open > 0} />
      </View>
      {shop.phone ? (
        <Pressable
          onPress={() => Linking.openURL(`tel:${shop.phone}`)}
          hitSlop={8}
          style={({ pressed }) => [styles.settingIcon, pressed ? { opacity: 0.7 } : null, { alignSelf: 'flex-start' }]}
        >
          <Icon name="call" size={17} color={theme.brand} />
        </Pressable>
      ) : null}
    </Pressable>
  );
}

export function HomeScreen() {
  const { styles, theme, push } = useApp();
  const insets = useSafeAreaInsets();
  const layout = useLayout();
  const { shops, loading, error, reload } = useShops();
  const { products, categories, loading: browseLoading, error: browseError } = useBrowse('', '', 'popular', 8);
  const [term, setTerm] = useState('');
  const [area, setArea] = useState('');

  const areas = useMemo(() => {
    const set: string[] = [];
    for (const s of shops) {
      const a = (s.area || '').trim();
      if (a && !set.includes(a)) set.push(a);
    }
    return set.sort();
  }, [shops]);

  const featured = useMemo(() => shops.filter((s) => matchesArea(s, area)).slice(0, 8), [shops, area]);
  const open = shops.filter((s) => s.is_open > 0 && s.products > 0).length;

  return (
    <View style={styles.screen}>
      <View style={[styles.hero, { paddingTop: insets.top + 12 }]}>
        <View style={styles.heroGlow} />
        <View style={styles.locationRow}>
          <Icon name="location" size={16} color={theme.onHero} />
          <View style={{ flex: 1 }}>
            <Text style={styles.locationLabel}>Deliver to</Text>
            <Text style={styles.locationValue} numberOfLines={1}>
              {area || 'All areas'}
            </Text>
          </View>
          {areas.length ? <Icon name="chevron-down" size={15} color={theme.onHero} /> : null}
        </View>
        {areas.length ? (
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 10 }}>
            {['', ...areas].map((a) => {
              const on = area === a;
              return (
                <Pressable
                  key={a || 'all'}
                  onPress={() => setArea(a)}
                  style={{
                    backgroundColor: on ? theme.onBrand : 'rgba(255,255,255,0.16)',
                    borderRadius: 999,
                    paddingHorizontal: 12,
                    paddingVertical: 6,
                  }}
                >
                  <Text style={{ color: on ? theme.brand : theme.onHero, fontSize: 12, fontWeight: '800' }}>
                    {a || 'All areas'}
                  </Text>
                </Pressable>
              );
            })}
          </View>
        ) : null}

        <View style={styles.searchRow}>
          <View style={{ flex: 1 }}>
            <SearchBar value={term} onChange={setTerm} placeholder="Search products, shops…" onSubmit={() => push({ name: 'listing', q: term.trim() })} />
          </View>
        </View>
      </View>

      <ScrollView
        contentContainerStyle={{ padding: layout.gutter, paddingBottom: layout.bottomSpace }}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={reload} tintColor={theme.brand} />}
      >
        <Pressable
          style={({ pressed }) => [
            {
              backgroundColor: theme.heroTop,
              borderRadius: 22,
              padding: 18,
              marginBottom: 18,
              overflow: 'hidden',
            },
            pressed ? { opacity: 0.92 } : null,
          ]}
          onPress={() => push({ name: 'listing' })}
        >
          <View
            style={{
              position: 'absolute',
              right: -40,
              top: -50,
              width: 150,
              height: 150,
              borderRadius: 75,
              backgroundColor: theme.heroBottom,
              opacity: 0.7,
            }}
          />
          <Text style={{ color: theme.onHero, fontSize: 19, fontWeight: '800', maxWidth: '82%', letterSpacing: -0.4 }}>
            Best deals from local shops
          </Text>
          <Text style={{ color: theme.onHeroSoft, fontSize: 12, marginTop: 3, marginBottom: 12, maxWidth: '86%' }}>
            {open} shop{open === 1 ? '' : 's'} ready to take orders
          </Text>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 5, alignSelf: 'flex-start', backgroundColor: theme.onBrand, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 8 }}>
            <Text style={{ color: theme.brand, fontSize: 13, fontWeight: '800' }}>Shop now</Text>
            <Icon name="chevron-forward" size={13} color={theme.brand} />
          </View>
        </Pressable>

        {error ? <ErrorNote message={error} /> : null}

        {categories.length ? (
          <View>
            <SectionHead title="Categories" onViewAll={() => push({ name: 'categories' })} />
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: layout.gutter, paddingBottom: 6 }}>
              {categories.slice(0, 10).map((c) => (
                <View key={c.name} style={{ width: layout.railTileWidth }}>
                  <CategoryTile
                    name={c.name}
                    count={c.products}
                    image={c.image_url}
                    onPress={() => push({ name: 'listing', category: c.name })}
                  />
                </View>
              ))}
            </ScrollView>
          </View>
        ) : null}

        <View style={{ marginTop: 16 }}>
          <SectionHead title="Featured Shops" onViewAll={() => push({ name: 'shops' })} />
          {featured.length ? (
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: layout.gutter, paddingBottom: 6 }}>
              {featured.map((s) => (
                <ShopTile key={s.slug} shop={s} />
              ))}
            </ScrollView>
          ) : loading ? (
            <Loading text="Looking for shops…" />
          ) : (
            <EmptyState
              icon="storefront-outline"
              text="No shop has published products online yet. Shopkeepers switch this on from the POS under Online Shop."
            />
          )}
        </View>

        <View style={{ marginTop: 16 }}>
          <SectionHead title="Popular Products" onViewAll={() => push({ name: 'listing' })} />
          {browseError ? <ErrorNote message={browseError} /> : null}
          {products.length ? (
            <View style={[styles.grid, { gap: layout.gap }]}>
              {products.map((p) => (
                <ProductGridCard key={`${p.shop_slug}-${p.id}`} slug={p.shop_slug} product={p} shopLabel={p.shop_name} />
              ))}
            </View>
          ) : browseLoading ? (
            <Loading />
          ) : (
            <EmptyState icon="cube-outline" text="Nothing is published online yet." />
          )}
        </View>
      </ScrollView>
      <TabBar />
    </View>
  );
}

export function CategoriesScreen() {
  const { styles, push, pop, canGoBack } = useApp();
  const layout = useLayout();
  const { categories, loading, error } = useBrowse('', '', 'popular', 1);
  const [term, setTerm] = useState('');

  const shown = useMemo(() => {
    const q = term.trim().toLowerCase();
    if (!q) return categories;
    return categories.filter((c) => c.name.toLowerCase().includes(q));
  }, [categories, term]);

  return (
    <View style={styles.screen}>
      <ScreenHeader
        title="Categories"
        subtitle={`${categories.length} published category${categories.length === 1 ? '' : 's'}`}
        onBack={canGoBack ? pop : undefined}
      />
      <View style={{ padding: layout.gutter, paddingBottom: 6 }}>
        <SearchBar value={term} onChange={setTerm} placeholder="Search categories…" />
      </View>
      {error ? (
        <View style={{ paddingHorizontal: layout.gutter }}>
          <ErrorNote message={error} />
        </View>
      ) : null}
      <FlatList
        contentContainerStyle={{ paddingHorizontal: layout.gutter, paddingBottom: layout.bottomSpace }}
        numColumns={layout.tileCols}
        columnWrapperStyle={{ gap: layout.gap, marginBottom: layout.gap }}
        data={shown}
        keyExtractor={(c) => c.name}
        renderItem={({ item }) => (
          <CategoryTile
            name={item.name}
            count={item.products}
            image={item.image_url}
            onPress={() => push({ name: 'listing', category: item.name })}
          />
        )}
        ListEmptyComponent={
          loading ? <Loading /> : <EmptyState icon="grid-outline" text="No category has published products online yet." />
        }
      />
      <TabBar />
    </View>
  );
}

export function ShopsScreen() {
  const { styles, theme, pop, canGoBack } = useApp();
  const layout = useLayout();
  const { shops, loading, error, reload } = useShops();
  const [term, setTerm] = useState('');
  const [onlyOpen, setOnlyOpen] = useState(false);

  const shown = useMemo(() => {
    const q = term.trim().toLowerCase();
    return shops.filter((s) => {
      if (onlyOpen && !(s.is_open > 0 && s.products > 0)) return false;
      if (!q) return true;
      return s.name.toLowerCase().includes(q) || (s.area || '').toLowerCase().includes(q);
    });
  }, [shops, term, onlyOpen]);

  return (
    <View style={styles.screen}>
      <ScreenHeader
        title="Shops"
        subtitle={`${shops.length} shop${shops.length === 1 ? '' : 's'} online`}
        onBack={canGoBack ? pop : undefined}
        right={<HeaderAction name="refresh" onPress={reload} />}
      />
      <View style={{ padding: layout.gutter, paddingBottom: 6 }}>
        <SearchBar value={term} onChange={setTerm} placeholder="Shop name or area…" />
        <View style={[styles.chipRow, { marginTop: 10, marginBottom: 0 }]}>
          <Pressable style={[styles.chip, onlyOpen ? styles.chipOn : null]} onPress={() => setOnlyOpen((v) => !v)}>
            <Text style={onlyOpen ? styles.chipTextOn : styles.chipText}>Open with stock</Text>
          </Pressable>
          <Text style={[styles.chipText, { alignSelf: 'center', color: theme.sub }]}>
            {shown.length} of {shops.length}
          </Text>
        </View>
      </View>
      <FlatList
        contentContainerStyle={{ padding: layout.gutter, paddingBottom: layout.bottomSpace }}
        data={shown}
        keyExtractor={(s) => s.slug}
        renderItem={({ item }) => <ShopCard shop={item} />}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={reload} tintColor={theme.brand} />}
        ListEmptyComponent={
          loading ? <Loading text="Looking for shops…" /> : error ? <ErrorNote message={error} /> : <EmptyState icon="storefront-outline" text="No shop matches." />
        }
      />
      <TabBar />
    </View>
  );
}
