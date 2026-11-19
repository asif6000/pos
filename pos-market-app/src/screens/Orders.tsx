/**
 * Order tracking.
 *
 * A buyer has no account, so the app remembers the order numbers this phone
 * placed and asks the server for each one's status. The lookup needs the order
 * number AND the phone, because ECM-000012 on its own is guessable and would
 * otherwise hand a stranger someone's name, address and bill.
 */

import React, { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { fetchStatus } from '../api';
import { useLayout } from '../responsive';
import { useApp } from '../state';
import { money } from '../theme';
import { EmptyState, ErrorNote, HeaderAction, Icon, Loading, ScreenHeader, StatusPill, TabBar } from '../ui';
import type { OrderStatus, SavedOrder } from '../types';

const FILTERS = [
  { key: '', label: 'All' },
  { key: 'pending', label: 'Processing' },
  { key: 'accepted', label: 'Confirmed' },
  { key: 'delivered', label: 'Delivered' },
  { key: 'rejected', label: 'Cancelled' },
];

/** A line of an order's own detail, as the server returned it. */
function ItemLine({ qty, name, total }: { qty: number; name: string; total: string }) {
  const { styles } = useApp();
  return (
    <View style={[styles.rowBetween, { marginTop: 4, gap: 8 }]}>
      <Text style={[styles.sub, { flex: 1, minWidth: 0 }]} numberOfLines={1}>
        {qty} × {name}
      </Text>
      <Text style={[styles.faint, { flexShrink: 0 }]} numberOfLines={1}>
        {money(total)}
      </Text>
    </View>
  );
}

export function OrdersScreen() {
  const { styles, theme, apiUrl, orders, pop, goToTab, rememberOrders } = useApp();
  const layout = useLayout();
  const [statuses, setStatuses] = useState<Record<string, OrderStatus>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [filter, setFilter] = useState('');
  const [lookupNo, setLookupNo] = useState('');
  const [lookupPhone, setLookupPhone] = useState('');

  // One lookup per order number, and the phone has to travel with it: the server
  // will not hand out a bill for ECM-000012 alone. A wrong number on one card is
  // simply skipped rather than failing the whole list.
  const askAll = useCallback(
    async (list: SavedOrder[]): Promise<Record<string, OrderStatus>> => {
      const next: Record<string, OrderStatus> = {};
      for (const saved of list) {
        if (saved.phone.length < 10) continue;
        const res = await fetchStatus(apiUrl, saved.order_no, saved.phone);
        if (res.ok) next[saved.order_no] = res.data.order;
      }
      return next;
    },
    [apiUrl]
  );

  async function refresh() {
    if (!orders.length) return;
    setBusy(true);
    setError('');
    const next = await askAll(orders);
    setStatuses((prev) => ({ ...prev, ...next }));
    setBusy(false);
  }

  // The first pass runs on mount, before any button exists to press. Awaiting
  // before touching state keeps the render quiet: nothing is set synchronously
  // while the effect is still running.
  useEffect(() => {
    let alive = true;
    (async () => {
      if (!orders.length) return;
      const next = await askAll(orders);
      if (!alive) return;
      setStatuses((prev) => ({ ...prev, ...next }));
    })();
    return () => {
      alive = false;
    };
  }, [askAll, orders]);

  async function lookup() {
    const no = lookupNo.trim().toUpperCase();
    const phone = lookupPhone.replace(/[^0-9]/g, '');
    if (!no || phone.length < 10) {
      setError('Type the order number and the phone number used for it.');
      return;
    }
    setBusy(true);
    const res = await fetchStatus(apiUrl, no, phone);
    if (res.ok) {
      setStatuses((prev) => ({ ...prev, [no]: res.data.order }));
      rememberOrders([
        {
          order_no: no,
          slug: '',
          shop_name: 'Ordered shop',
          phone,
          total: Number(res.data.order.total),
          placed_at: res.data.order.created_at,
        },
      ]);
      setError('');
    } else {
      setError(res.message);
    }
    setBusy(false);
  }

  const shown = orders.filter((o) => {
    if (!filter) return true;
    const st = statuses[o.order_no];
    return (st ? st.status : 'pending') === filter;
  });

  return (
    <View style={styles.screen}>
      <ScreenHeader
        title="My Orders"
        subtitle={`${orders.length} order${orders.length === 1 ? '' : 's'} from this phone`}
        onBack={pop}
        right={<HeaderAction name="refresh" onPress={refresh} />}
      />
      <ScrollView
        contentContainerStyle={{
          paddingHorizontal: layout.contentPadX,
          paddingTop: layout.gutter,
          paddingBottom: layout.bottomSpace,
        }}
      >
        {error ? <ErrorNote message={error} /> : null}

        <View style={styles.chipRow}>
          {FILTERS.map((f) => (
            <Pressable key={f.key} style={[styles.chip, filter === f.key ? styles.chipOn : null]} onPress={() => setFilter(f.key)}>
              <Text style={filter === f.key ? styles.chipTextOn : styles.chipText}>{f.label}</Text>
            </Pressable>
          ))}
        </View>

        {shown.length === 0 ? (
          <EmptyState
            icon="receipt-outline"
            text="No order placed from this phone yet."
            action={
              <Pressable
                style={({ pressed }) => [styles.btnGhost, { marginTop: 6 }, pressed ? styles.btnGhostPressed : null]}
                onPress={() => goToTab('home')}
              >
                <Text style={styles.btnGhostText}>Start shopping</Text>
              </Pressable>
            }
          />
        ) : (
          shown.map((saved) => {
            const st = statuses[saved.order_no];
            const status = st ? st.status : 'pending';
            return (
              <View key={saved.order_no} style={styles.card}>
                <View style={[styles.rowBetween, { gap: 8 }]}>
                  <View style={{ flex: 1, minWidth: 0, gap: 3 }}>
                    <Text style={[styles.titleBold, { color: theme.brand }]}>{saved.order_no}</Text>
                    <View style={styles.rowGap}>
                      <Icon name="storefront-outline" size={13} color={theme.faint} />
                      <Text style={styles.faint} numberOfLines={1}>
                        {saved.shop_name}
                      </Text>
                    </View>
                    <View style={styles.rowGap}>
                      <Icon name="time-outline" size={13} color={theme.faint} />
                      <Text style={styles.faint}>{st ? st.created_at : saved.placed_at}</Text>
                    </View>
                  </View>
                  <View style={{ flexShrink: 0, alignItems: 'flex-end', gap: 6 }}>
                    <StatusPill status={status} />
                    <Text style={styles.price}>{money(st ? Number(st.total) : saved.total)}</Text>
                  </View>
                </View>

                {st ? (
                  <View style={{ marginTop: 6 }}>
                    <View style={styles.divider} />
                    {st.items.map((it) => (
                      <ItemLine key={`${st.order_no}-${it.product_id}`} qty={it.quantity} name={it.product_name} total={it.total_price} />
                    ))}
                    <View style={styles.divider} />
                    <Text style={styles.faint}>
                      Items {money(st.items_total)} · Delivery{' '}
                      {Number(st.delivery_charge) > 0 ? money(st.delivery_charge) : 'free'}
                    </Text>
                  </View>
                ) : (
                  <Text style={[styles.sub, { marginTop: 10 }]}>{busy ? 'Checking with the shop…' : 'Tap refresh to check status.'}</Text>
                )}
              </View>
            );
          })
        )}

        <View style={styles.card}>
          <Text style={styles.sectionTitle}>Look up another order</Text>
          <Text style={[styles.faint, { marginTop: 4 }]}>
            The order number and the phone number it was placed with, together.
          </Text>
          <Text style={styles.label}>Order number</Text>
          <TextInput
            value={lookupNo}
            onChangeText={setLookupNo}
            placeholder="ECM-000001"
            autoCapitalize="characters"
            style={styles.input}
            placeholderTextColor={theme.faint}
          />
          <Text style={styles.label}>Phone used</Text>
          <TextInput
            value={lookupPhone}
            onChangeText={setLookupPhone}
            keyboardType="phone-pad"
            placeholder="01XXXXXXXXX"
            style={styles.input}
            placeholderTextColor={theme.faint}
          />
          <Pressable
            style={({ pressed }) => [styles.btnSoft, { marginTop: 14 }, pressed ? styles.btnSoftPressed : null]}
            onPress={lookup}
          >
            <View style={styles.rowGap}>
              <Icon name="search-outline" size={16} color={theme.onBrandSoft} />
              <Text style={styles.btnSoftText}>Find order</Text>
            </View>
          </Pressable>
        </View>
        {busy ? <Loading text="Checking…" /> : null}
      </ScrollView>
      <TabBar />
    </View>
  );
}
