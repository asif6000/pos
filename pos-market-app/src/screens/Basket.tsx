/**
 * The basket and the cash-on-delivery checkout.
 *
 * WHY A BASKET IS GROUPED BY SHOP
 * -------------------------------
 * A customer puts things from several shops in one basket, but each shop is a
 * different tenant with its own till, and an order must land in the POS branch
 * that holds the item. So one basket becomes one order per shop: the app sends
 * them one at a time and reports each answer separately. Nothing is merged, and
 * no shop can ever see another shop's line.
 *
 * The totals here are the app's own arithmetic on the prices it was shown. The
 * amount the shop actually sees is recomputed on the server from the database,
 * so a stale price changes the bill at the server rather than in the app.
 *
 * Delivery is not a choice the customer makes: each shop sets its own charge and
 * minimum, and the server adds them, so the checkout shows the terms rather than
 * offering a "pickup" or a wallet the till has no idea how to settle.
 */

import React, { useMemo, useState } from 'react';
import { Alert, KeyboardAvoidingView, Platform, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { placeOrder } from '../api';
import { useLayout } from '../responsive';
import { useApp } from '../state';
import { money } from '../theme';
import { EmptyState, HeaderAction, Icon, ScreenHeader, Stepper, TabBar, Thumb } from '../ui';

function shopName(catalogues: Record<string, { shop: { name: string } }>, slug: string): string {
  return catalogues[slug] ? catalogues[slug].shop.name : 'Shop';
}

/** One product line in the basket: picture, name, unit price, stepper, remove. */
function BasketLine({
  name,
  image,
  price,
  qty,
  max,
  onQty,
  onRemove,
}: {
  name: string;
  image: string | null;
  price: number;
  qty: number;
  max: number;
  onQty: (n: number) => void;
  onRemove: () => void;
}) {
  const { styles, theme } = useApp();
  const layout = useLayout();
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: layout.gap, marginTop: 12 }}>
      <Thumb uri={image} size={layout.width < 360 ? 46 : 54} label={name} />
      <View style={{ flex: 1, minWidth: 0 }}>
        <Text style={styles.title} numberOfLines={2}>
          {name}
        </Text>
        <Text style={styles.faint} numberOfLines={1}>
          {money(price)} × {qty} = {money(price * qty)}
        </Text>
      </View>
      <View style={{ alignItems: 'center', gap: 6, flexShrink: 0 }}>
        <Stepper qty={qty} max={max} onChange={onQty} />
        <Pressable onPress={onRemove} hitSlop={8}>
          <Icon name="trash-outline" size={16} color={theme.danger} />
        </Pressable>
      </View>
    </View>
  );
}

export function CartScreen() {
  const { styles, theme, catalogues, cart, shopLines, setQty, clearShop, clearCart, cartTotal, cartItemCount, push, pop, goToTab } =
    useApp();
  const layout = useLayout();
  const slugs = Object.keys(cart).filter((s) => Object.keys(cart[s]).length > 0);

  // The header's trash icon is the only way to empty everything at once, so it
  // says what it costs first: a basket built over several shops is not recoverable.
  function askClear() {
    Alert.alert('Empty the cart?', `This removes ${cartItemCount} item${cartItemCount === 1 ? '' : 's'} from all ${slugs.length} shop${slugs.length === 1 ? '' : 's'}.`, [
      { text: 'Keep it', style: 'cancel' },
      { text: 'Empty cart', style: 'destructive', onPress: clearCart },
    ]);
  }

  if (slugs.length === 0) {
    return (
      <View style={styles.screen}>
        <ScreenHeader title="My Cart" onBack={pop} />
        <EmptyState
          icon="cart-outline"
          text="Your cart is empty. Add something from a shop."
          action={
            <Pressable
              style={({ pressed }) => [styles.btnGhost, { marginTop: 6 }, pressed ? styles.btnGhostPressed : null]}
              onPress={() => goToTab('home')}
            >
              <Text style={styles.btnGhostText}>Browse shops</Text>
            </Pressable>
          }
        />
        <TabBar />
      </View>
    );
  }

  return (
    <View style={styles.screen}>
      <ScreenHeader
        title="My Cart"
        subtitle={`${cartItemCount} item${cartItemCount === 1 ? '' : 's'} from ${slugs.length} shop${slugs.length === 1 ? '' : 's'}`}
        onBack={pop}
        right={<HeaderAction name="trash-outline" onPress={askClear} />}
      />
      <ScrollView
        contentContainerStyle={{
          paddingHorizontal: layout.contentPadX,
          paddingTop: layout.gutter,
          paddingBottom: layout.bottomSpace,
        }}
      >
        {slugs.map((slug) => {
          const lines = shopLines(slug);
          const info = catalogues[slug] ? catalogues[slug].shop : null;
          const delivery = info ? Number(info.delivery_charge) : 0;
          const freeOver = info ? Number(info.free_over) : 0;
          const minOrder = info ? Number(info.min_order) : 0;
          const sum = cartTotal(slug);
          const charged = freeOver > 0 && sum >= freeOver ? 0 : delivery;
          const count = lines.reduce((n, l) => n + l.qty, 0);
          return (
            <View key={slug} style={styles.card}>
              <Pressable style={styles.rowBetween} onPress={() => push({ name: 'shop', slug })}>
                <View style={{ flex: 1, minWidth: 0 }}>
                  <Text style={styles.titleBold} numberOfLines={1}>
                    {shopName(catalogues, slug)}
                  </Text>
                  <Text style={styles.faint}>
                    {count} item{count === 1 ? '' : 's'} · {money(sum)}
                  </Text>
                </View>
                <Icon name="chevron-forward" size={18} color={theme.faint} />
              </Pressable>

              {lines.map((line) => (
                <BasketLine
                  key={line.product.id}
                  name={line.product.name}
                  image={line.product.image_url}
                  price={line.product.price}
                  qty={line.qty}
                  max={Math.max(1, line.product.stock)}
                  onQty={(n) => setQty(slug, line.product.id, n)}
                  onRemove={() => setQty(slug, line.product.id, 0)}
                />
              ))}

              <View style={styles.divider} />
              <View style={styles.rowBetween}>
                <Text style={styles.sub}>Subtotal</Text>
                <Text style={styles.title}>{money(sum)}</Text>
              </View>
              <View style={styles.rowBetween}>
                <Text style={styles.sub}>Delivery fee</Text>
                <Text style={styles.sub}>{info ? (charged > 0 ? money(charged) : 'free') : 'at checkout'}</Text>
              </View>
              {minOrder > 0 && sum < minOrder ? (
                <Text style={[styles.danger, { marginTop: 8 }]}>
                  This shop needs a minimum order of {money(minOrder)}.
                </Text>
              ) : null}
              <View style={styles.divider} />
              <View style={styles.rowBetween}>
                <Text style={styles.title}>Total</Text>
                <Text style={styles.price}>{money(sum + (info ? charged : 0))}</Text>
              </View>
              <Pressable onPress={() => clearShop(slug)} hitSlop={8} style={{ marginTop: 10 }}>
                <Text style={styles.faint}>Empty this shop</Text>
              </Pressable>
            </View>
          );
        })}
      </ScrollView>

      <View style={{ position: 'absolute', left: layout.contentPadX, right: layout.contentPadX, bottom: layout.barFloor }}>
        <Pressable
          style={({ pressed }) => [styles.btn, pressed ? styles.btnPressed : null]}
          onPress={() => push({ name: 'checkout' })}
        >
          <View style={styles.rowGap}>
            <Text style={[styles.btnText, { maxWidth: '100%' }]} numberOfLines={1}>
              Checkout · {money(cartTotal())}
            </Text>
            <Icon name="chevron-forward" size={17} color={theme.onBrand} />
          </View>
        </Pressable>
        <Text style={[styles.faint, { textAlign: 'center', marginTop: 8 }]}>
          Cash on delivery. Pay when the shop arrives.
        </Text>
      </View>
      <TabBar />
    </View>
  );
}

type Placed = { order_no: string; shop_name: string; total: number };
type Failed = { shop_name: string; message: string };

export function CheckoutScreen() {
  const {
    styles,
    theme,
    apiUrl,
    cart,
    catalogues,
    cartTotal,
    buyer,
    setBuyer,
    shopLines,
    clearShop,
    rememberOrders,
    pop,
    push,
    goToTab,
  } = useApp();
  const layout = useLayout();
  // The address is a card you can change, not a form: it opens itself for someone
  // who has never filled it in.
  const [editing, setEditing] = useState(buyer.name === '' || buyer.phone === '' || buyer.address === '');
  const [name, setName] = useState(buyer.name);
  const [phone, setPhone] = useState(buyer.phone);
  const [address, setAddress] = useState(buyer.address);
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<{ placed: Placed[]; failed: Failed[] } | null>(null);

  const slugs = useMemo(
    () => Object.keys(cart).filter((s) => Object.keys(cart[s]).length > 0),
    [cart],
  );
  const digits = phone.replace(/[^0-9]/g, '');
  const problems: string[] = [];
  if (name.trim() === '') problems.push('Enter your name.');
  if (digits.length < 10) problems.push('Enter a valid phone number.');
  if (address.trim() === '') problems.push('Enter the delivery address.');

  async function submit() {
    if (busy || problems.length || !slugs.length) return;
    setBusy(true);
    setBuyer({ name: name.trim(), phone: digits, address: address.trim() });

    const placed: Placed[] = [];
    const failed: Failed[] = [];
    // One order per shop, in sequence: each is a separate tenant's till and a
    // half-finished batch must stay visible to the customer, not silently retry.
    for (const slug of slugs) {
      const lines = shopLines(slug);
      const shopLabel = shopName(catalogues, slug);
      const res = await placeOrder(apiUrl, slug, {
        name: name.trim(),
        phone: digits,
        address: address.trim(),
        note: note.trim(),
        items: lines.map((l) => ({ id: l.product.id, qty: l.qty })),
      });
      if (res.ok) {
        const o = res.data.order;
        placed.push({ order_no: o.order_no, shop_name: shopLabel, total: Number(o.total) });
        clearShop(slug);
        rememberOrders([
          {
            order_no: o.order_no,
            slug,
            shop_name: shopLabel,
            phone: digits,
            total: Number(o.total),
            placed_at: new Date().toISOString(),
          },
        ]);
      } else {
        failed.push({ shop_name: shopLabel, message: res.message });
      }
    }

    setDone({ placed, failed });
    setBusy(false);
    if (failed.length === 0) setNote('');
  }

  if (done) {
    return (
      <View style={styles.screen}>
        <ScreenHeader title="Order placed" onBack={pop} />
        <ScrollView
          contentContainerStyle={{
            paddingHorizontal: layout.contentPadX,
            paddingTop: layout.gutter,
            paddingBottom: layout.bottomSpace,
          }}
        >
          {done.placed.length > 0 ? (
            <View style={styles.card}>
              <View style={[styles.emptyIcon, { width: 52, height: 52, backgroundColor: theme.okSoft }]}>
                <Icon name="checkmark-circle" size={30} color={theme.ok} />
              </View>
              <Text style={[styles.titleBold, { color: theme.ok, marginTop: 4 }]}>
                {done.placed.length} order{done.placed.length === 1 ? '' : 's'} sent
              </Text>
              {done.placed.map((p) => (
                <View key={p.order_no} style={[styles.rowBetween, { marginTop: 12, gap: 8 }]}>
                  <View style={{ flex: 1, minWidth: 0 }}>
                    <Text style={styles.title}>{p.order_no}</Text>
                    <Text style={styles.faint} numberOfLines={1}>
                      {p.shop_name}
                    </Text>
                  </View>
                  <Text style={[styles.price, { flexShrink: 0 }]}>{money(p.total)}</Text>
                </View>
              ))}
              <View style={styles.divider} />
              <Text style={styles.sub}>
                Keep the order number. The shop will call this number to confirm.
              </Text>
            </View>
          ) : null}

          {done.failed.length > 0 ? (
            <View style={[styles.card, { borderLeftWidth: 3, borderLeftColor: theme.danger }]}>
              <Text style={[styles.titleBold, { color: theme.danger }]}>Not sent</Text>
              {done.failed.map((f) => (
                <Text key={f.shop_name} style={[styles.sub, { marginTop: 8 }]}>
                  {f.shop_name}: {f.message}
                </Text>
              ))}
            </View>
          ) : null}

          <Pressable
            style={({ pressed }) => [styles.btn, pressed ? styles.btnPressed : null]}
            onPress={() => push({ name: 'orders' })}
          >
            <Text style={styles.btnText}>Track my orders</Text>
          </Pressable>
          <Pressable
            style={({ pressed }) => [styles.btnGhost, { marginTop: 10 }, pressed ? styles.btnGhostPressed : null]}
            onPress={() => goToTab('home')}
          >
            <Text style={styles.btnGhostText}>Keep shopping</Text>
          </Pressable>
        </ScrollView>
        <TabBar />
      </View>
    );
  }

  return (
    <View style={styles.screen}>
      <ScreenHeader title="Checkout" subtitle={`${slugs.length} order${slugs.length === 1 ? '' : 's'}`} onBack={pop} />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView
          contentContainerStyle={{
            paddingHorizontal: layout.contentPadX,
            paddingTop: layout.gutter,
            paddingBottom: layout.bottomSpace,
          }}
        >
          <View style={styles.card}>
            <View style={styles.rowBetween}>
              <View style={styles.rowGap}>
                <Icon name="location" size={17} color={theme.brand} />
                <Text style={styles.sectionTitle}>Delivery address</Text>
              </View>
              {editing ? null : (
                <Pressable onPress={() => setEditing(true)} hitSlop={8}>
                  <Text style={styles.sectionLink}>Change</Text>
                </Pressable>
              )}
            </View>
            {editing ? (
              <View>
                <Text style={styles.label}>Your name</Text>
                <TextInput value={name} onChangeText={setName} placeholder="Name" style={styles.input} placeholderTextColor={theme.faint} />
                <Text style={styles.label}>Phone</Text>
                <TextInput
                  value={phone}
                  onChangeText={setPhone}
                  placeholder="01XXXXXXXXX"
                  keyboardType="phone-pad"
                  style={styles.input}
                  placeholderTextColor={theme.faint}
                />
                <Text style={styles.label}>Address</Text>
                <TextInput
                  value={address}
                  onChangeText={setAddress}
                  placeholder="House, road, area"
                  multiline
                  style={[styles.input, styles.inputMulti]}
                  placeholderTextColor={theme.faint}
                />
                <Pressable
                  style={({ pressed }) => [styles.btnSoft, { marginTop: 14 }, pressed ? styles.btnSoftPressed : null]}
                  onPress={() => setEditing(false)}
                >
                  <Text style={styles.btnSoftText}>Use this address</Text>
                </Pressable>
              </View>
            ) : (
              <View style={{ gap: 2, marginTop: 10 }}>
                <Text style={styles.title}>{name}</Text>
                <Text style={styles.sub}>{address}</Text>
                <Text style={styles.faint}>{phone}</Text>
              </View>
            )}
          </View>

          {slugs.map((slug) => {
            const lines = shopLines(slug);
            const info = catalogues[slug] ? catalogues[slug].shop : null;
            const delivery = info ? Number(info.delivery_charge) : 0;
            const freeOver = info ? Number(info.free_over) : 0;
            const sum = cartTotal(slug);
            const charged = freeOver > 0 && sum >= freeOver ? 0 : delivery;
            return (
              <View key={slug} style={styles.card}>
                <Text style={styles.titleBold}>{shopName(catalogues, slug)}</Text>
                {lines.map((l) => (
                  <View key={l.product.id} style={[styles.rowBetween, { marginTop: 8, gap: 8 }]}>
                    <Text style={[styles.sub, { flex: 1, minWidth: 0 }]} numberOfLines={1}>
                      {l.qty} × {l.product.name}
                    </Text>
                    <Text style={[styles.sub, { flexShrink: 0 }]}>{money(l.product.price * l.qty)}</Text>
                  </View>
                ))}
                <View style={styles.divider} />
                <View style={styles.rowBetween}>
                  <Text style={styles.sub}>Items</Text>
                  <Text style={styles.title}>{money(sum)}</Text>
                </View>
                <View style={styles.rowBetween}>
                  <Text style={styles.sub}>Delivery</Text>
                  <Text style={styles.sub}>{info ? (charged > 0 ? money(charged) : 'free') : 'set by the shop'}</Text>
                </View>
              </View>
            );
          })}

          <View style={styles.card}>
            <Text style={styles.sectionTitle}>Payment method</Text>
            <View style={[styles.rowBetween, { paddingVertical: 10 }]}>
              <View style={styles.rowGap}>
                <View style={styles.settingIcon}>
                  <Icon name="cash-outline" size={17} color={theme.brand} />
                </View>
                <View style={{ flex: 1, minWidth: 0 }}>
                  <Text style={styles.title}>Cash on delivery</Text>
                  <Text style={styles.faint}>Pay in cash when the shop arrives</Text>
                </View>
              </View>
              <Icon name="radio-button-on" size={20} color={theme.brand} />
            </View>
            <Text style={styles.faint}>
              Cards, bKash and Nagad are not connected here: each shop settles its own till, so nothing is charged in
              this app.
            </Text>
          </View>

          <View style={styles.card}>
            <Text style={styles.sectionTitle}>Note for the shops (optional)</Text>
            <TextInput
              value={note}
              onChangeText={setNote}
              style={[styles.input, styles.inputMulti]}
              placeholder="Anything the shop should know"
              placeholderTextColor={theme.faint}
              multiline
            />
          </View>

          {problems.length ? (
            <View style={[styles.card, { borderLeftWidth: 3, borderLeftColor: theme.warn }]}>
              <View style={{ gap: 6 }}>
                {problems.map((p) => (
                  <View key={p} style={styles.rowGap}>
                    <Icon name="alert-circle-outline" size={15} color={theme.warn} />
                    <Text style={[styles.sub, { flex: 1 }]}>{p}</Text>
                  </View>
                ))}
              </View>
            </View>
          ) : null}
        </ScrollView>

        <View style={{ paddingHorizontal: layout.contentPadX, paddingBottom: Math.max(layout.bottomInset, 10) + 8 }}>
          <Pressable
            style={({ pressed }) => [
              styles.btn,
              busy || problems.length > 0 ? styles.btnDisabled : null,
              pressed && !busy && problems.length === 0 ? styles.btnPressed : null,
            ]}
            disabled={busy || problems.length > 0}
            onPress={submit}
          >
            <Text style={[styles.btnText, { maxWidth: '100%' }]} numberOfLines={1}>
              {busy ? 'Sending…' : `Place ${slugs.length} order${slugs.length === 1 ? '' : 's'} · ${money(cartTotal())}`}
            </Text>
          </Pressable>
          <Text style={[styles.faint, { textAlign: 'center', marginTop: 8 }]}>
            Prices are confirmed by each shop at this moment.
          </Text>
        </View>
      </KeyboardAvoidingView>
      <TabBar />
    </View>
  );
}
