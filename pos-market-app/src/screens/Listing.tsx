/**
 * The cross-shop product listing: search results, a category's shelf, or the whole
 * marketplace when neither is set.
 *
 * One screen covers all three because they are the same query with different
 * filters, and because a customer who taps "Grocery" and then types "rice" expects
 * to stay on the same list rather than be bounced to another screen.
 */

import React, { useEffect, useState } from 'react';
import { FlatList, Pressable, RefreshControl, Text, View } from 'react-native';
import { useLayout } from '../responsive';
import { useApp } from '../state';
import { useBrowse } from '../useBrowse';
import { EmptyState, ErrorNote, HeaderAction, Icon, Loading, ProductListRow, ScreenHeader, SearchBar, TabBar } from '../ui';
import type { IconName } from '../ui';

const SORTS: { key: string; label: string; icon?: IconName }[] = [
  { key: 'popular', label: 'Popular' },
  { key: 'newest', label: 'Newest' },
  { key: 'price_low', label: 'Price', icon: 'arrow-up' },
  { key: 'price_high', label: 'Price', icon: 'arrow-down' },
  { key: 'name', label: 'A–Z' },
];

export function ListingScreen({ q = '', category = '' }: { q?: string; category?: string }) {
  const { styles, theme, pop, push } = useApp();
  const layout = useLayout();
  const [term, setTerm] = useState(q);
  const [cat, setCat] = useState(category);
  const [sort, setSort] = useState('popular');
  // The list runs on the settled word, not the one being typed: every keystroke
  // would otherwise be a request, and a customer on mobile data pays for them.
  const [debounced, setDebounced] = useState(q.trim());

  useEffect(() => {
    const id = setTimeout(() => setDebounced(term.trim()), 500);
    return () => clearTimeout(id);
  }, [term]);

  // The server does the filtering: a marketplace shelf is bigger than one shop's,
  // and fetching it whole to slice on the phone would cost the customer data.
  const { products, total, categories, loading, error, reload } = useBrowse(debounced, cat, sort, 80);

  return (
    <View style={styles.screen}>
      <ScreenHeader
        title={cat || 'All products'}
        subtitle={`${total} product${total === 1 ? '' : 's'}`}
        onBack={pop}
        right={<HeaderAction name="grid-outline" onPress={() => push({ name: 'categories' })} />}
      />
      <View style={{ padding: layout.gutter, paddingBottom: 6 }}>
        <SearchBar value={term} onChange={setTerm} placeholder="Search products…" />
      </View>

      {categories.length ? (
        <FlatList
          horizontal
          showsHorizontalScrollIndicator={false}
          data={[{ name: '', products: 0 }, ...categories]}
          keyExtractor={(c) => c.name || 'all'}
          style={{ flexGrow: 0, marginBottom: 4 }}
          contentContainerStyle={{ paddingHorizontal: layout.gutter, gap: 8 }}
          renderItem={({ item }) => (
            <Pressable style={[styles.chip, cat === item.name ? styles.chipOn : null]} onPress={() => setCat(item.name)}>
              <Text style={cat === item.name ? styles.chipTextOn : styles.chipText}>{item.name || 'All'}</Text>
            </Pressable>
          )}
        />
      ) : null}

      <View style={[styles.chipScrollRow, { paddingRight: layout.gutter }]}>
        {SORTS.map((s) => (
          <Pressable key={s.key} style={[styles.chip, sort === s.key ? styles.chipOn : null]} onPress={() => setSort(s.key)}>
            <View style={styles.rowGap}>
              {s.icon ? <Icon name={s.icon} size={12} color={sort === s.key ? styles.chipTextOn.color : styles.chipText.color} /> : null}
              <Text style={sort === s.key ? styles.chipTextOn : styles.chipText}>{s.label}</Text>
            </View>
          </Pressable>
        ))}
      </View>

      <FlatList
        contentContainerStyle={{ padding: layout.gutter, paddingBottom: layout.bottomSpace }}
        data={products}
        keyExtractor={(p) => `${p.shop_slug}-${p.id}`}
        renderItem={({ item }) => <ProductListRow slug={item.shop_slug} product={item} shopLabel={item.shop_name} />}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={reload} tintColor={theme.brand} />}
        ListHeaderComponent={error ? <ErrorNote message={error} /> : null}
        ListEmptyComponent={
          loading ? <Loading /> : <EmptyState icon="search-outline" text="No product matches this search." />
        }
      />
      <TabBar />
    </View>
  );
}
