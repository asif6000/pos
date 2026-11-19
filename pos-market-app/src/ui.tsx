/**
 * Shared building blocks: the splash, headers, bottom tabs, photo tiles, category
 * tile, product cards, quantity stepper, status pill and the empty/error states.
 *
 * Two rules hold this file together.
 *
 * PICTURES. A shop's product photo is whatever the shopkeeper typed into the POS,
 * so a broken or missing path is normal. React Native turns a broken <Image>
 * source into a red box on Android unless source is omitted entirely, so every
 * photo here decides between a real image and a plain surface with the name's
 * first letters on it. Nothing paints a tile by guessing an icon from a name.
 *
 * ICONS. @expo/vector-icons ships the glyph set, so the interface has proper
 * line icons instead of emoji, and the app carries no icon font of its own.
 */

import React from 'react';
import { ActivityIndicator, Image, Pressable, Text, TextInput, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import type { ComponentProps } from 'react';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { safeImageUrl } from './config';
import { useLayout } from './responsive';
import { useApp } from './state';
import { money, statusColor, statusLabel, statusSoftColor } from './theme';
import type { ProductRow } from './types';

export type IconName = ComponentProps<typeof Ionicons>['name'];

export function Icon({ name, size, color }: { name: IconName; size: number; color: string }) {
  return <Ionicons name={name} size={size} color={color} />;
}

/** The first letters of a real name - what a card shows when it has no photo. */
export function initials(label?: string): string {
  const words = (label || '').trim().split(/\s+/).filter((w) => w !== '');
  if (!words.length) return '?';
  if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
  return (words[0][0] + words[1][0]).toUpperCase();
}

/**
 * The first thing a customer sees, and the window the app uses to read its own
 * storage (cart, saved address, which server) before a screen paints with it.
 */
export function Splash() {
  const { theme } = useApp();
  return (
    <View style={{ flex: 1, backgroundColor: theme.heroTop, alignItems: 'center', justifyContent: 'center', gap: 14 }}>
      <View
        style={{
          position: 'absolute',
          top: -90,
          width: 260,
          height: 260,
          borderRadius: 130,
          backgroundColor: theme.heroBottom,
          opacity: 0.6,
        }}
      />
      <Image source={require('../assets/logo-white.png')} style={{ width: 96, height: 96 }} resizeMode="contain" />
      <Text style={{ color: theme.onHero, fontSize: 25, fontWeight: '800', textAlign: 'center', letterSpacing: -0.5 }}>
        Smart POS{'\n'}Marketplace
      </Text>
      <Text style={{ color: theme.onHeroSoft, fontSize: 14, textAlign: 'center', lineHeight: 20 }}>
        Shop from multiple stores{'\n'}all in one place
      </Text>
      <View style={{ marginTop: 26 }}>
        <ActivityIndicator color="#FFFFFF" />
      </View>
    </View>
  );
}

export function Thumb({
  uri,
  size,
  height,
  label,
  wide = false,
  radius,
}: {
  uri: string | null;
  size?: number;
  height?: number;
  label?: string;
  wide?: boolean;
  radius?: number;
}) {
  const { styles, serverUrl } = useApp();
  const layout = useLayout();
  const url = safeImageUrl(serverUrl, uri);
  const box = wide
    ? { height: height ?? layout.coverHeight, alignSelf: 'stretch' as const, borderRadius: radius ?? 0 }
    : { width: size ?? layout.thumbSize, height: height ?? size ?? layout.thumbSize, borderRadius: radius ?? 16 };
  if (!url) {
    return (
      <View style={[styles.thumb, box]}>
        <Text style={[styles.thumbText, { fontSize: Math.round((box.height || 60) * 0.22) }]}>
          {initials(label)}
        </Text>
      </View>
    );
  }
  return (
    <View style={[styles.thumb, box]}>
      <Image source={{ uri: url }} style={{ width: '100%', height: '100%' }} resizeMode="cover" />
    </View>
  );
}

/** A shop's front picture. No picture means a plain surface with its initials. */
export function Cover({ uri, height, width, label }: { uri: string | null; height?: number; width?: number; label?: string }) {
  const { styles, serverUrl } = useApp();
  const layout = useLayout();
  const url = safeImageUrl(serverUrl, uri);
  const h = height ?? layout.coverHeight;
  const box = { height: h, width: width ?? ('100%' as const), borderRadius: width ? 16 : 0 };
  if (!url) {
    return (
      <View style={[styles.cover, box]}>
        <Text style={{ color: styles.thumbText.color, fontSize: Math.round(h * 0.2), fontWeight: '800' }}>{initials(label)}</Text>
      </View>
    );
  }
  return <Image source={{ uri: url }} style={[styles.cover, box]} resizeMode="cover" />;
}

export function ScreenHeader({
  title,
  onBack,
  right,
  subtitle,
}: {
  title: string;
  onBack?: () => void;
  right?: React.ReactNode;
  subtitle?: string;
}) {
  const { styles, theme } = useApp();
  const insets = useSafeAreaInsets();
  return (
    <View style={[styles.header, { paddingTop: insets.top + 10 }]}>
      {onBack ? (
        <Pressable onPress={onBack} hitSlop={10} style={({ pressed }) => [styles.iconBtn, pressed ? { opacity: 0.7 } : null]}>
          <Icon name="chevron-back" size={22} color={theme.onHero} />
        </Pressable>
      ) : null}
      <View style={{ flex: 1, minWidth: 0 }}>
        <Text style={styles.headerTitle} numberOfLines={1}>
          {title}
        </Text>
        {subtitle ? (
          <Text style={styles.headerSub} numberOfLines={1}>
            {subtitle}
          </Text>
        ) : null}
      </View>
      {right}
    </View>
  );
}

/** The round icon button that sits in a brand header. */
export function HeaderAction({ name, onPress }: { name: IconName; onPress: () => void }) {
  const { styles, theme } = useApp();
  return (
    <Pressable
      onPress={onPress}
      hitSlop={10}
      style={({ pressed }) => [styles.iconBtn, pressed ? { opacity: 0.7 } : null]}
    >
      <Icon name={name} size={19} color={theme.onHero} />
    </Pressable>
  );
}

export function SearchBar({
  value,
  onChange,
  onSubmit,
  placeholder,
}: {
  value: string;
  onChange: (v: string) => void;
  onSubmit?: () => void;
  placeholder: string;
}) {
  const { styles, theme } = useApp();
  return (
    <View style={styles.search}>
      <Icon name="search" size={17} color={theme.faint} />
      <TextInput
        value={value}
        onChangeText={onChange}
        onSubmitEditing={onSubmit}
        placeholder={placeholder}
        placeholderTextColor={theme.faint}
        style={styles.searchInput}
        autoCorrect={false}
        returnKeyType="search"
      />
      {value ? (
        <Pressable onPress={() => onChange('')} hitSlop={10}>
          <Icon name="close-circle" size={18} color={theme.faint} />
        </Pressable>
      ) : null}
    </View>
  );
}

const TABS: { name: 'home' | 'categories' | 'shops' | 'cart' | 'profile'; label: string; on: IconName; off: IconName }[] = [
  { name: 'home', label: 'Home', on: 'home', off: 'home-outline' },
  { name: 'categories', label: 'Categories', on: 'grid', off: 'grid-outline' },
  { name: 'shops', label: 'Shops', on: 'storefront', off: 'storefront-outline' },
  { name: 'cart', label: 'Cart', on: 'cart', off: 'cart-outline' },
  { name: 'profile', label: 'Profile', on: 'person', off: 'person-outline' },
];

export function TabBar() {
  const { styles, theme, goToTab, route, cartItemCount } = useApp();
  const layout = useLayout();
  const active = route.name;
  // The bar used to end with 22px of padding, which is roughly the height of one
  // phone's gesture bar and no one else's. Everything above the bar reads
  // layout.tabBarHeight, which is this height.
  const bottomPad = Math.max(layout.bottomInset, 8);
  return (
    <View style={[styles.tabBar, { paddingBottom: bottomPad }]}>
      {TABS.map((tab) => {
        const on =
          tab.name === active ||
          (tab.name === 'home' && active === 'product') ||
          (tab.name === 'shops' && active === 'shop') ||
          (tab.name === 'categories' && active === 'listing');
        return (
          <Pressable key={tab.name} style={styles.tabBtn} onPress={() => goToTab(tab.name)}>
            <View style={[styles.tabIconBox, on ? styles.tabIconBoxOn : null]}>
              <Icon name={on ? tab.on : tab.off} size={21} color={on ? theme.brand : theme.faint} />
              {tab.name === 'cart' && cartItemCount > 0 ? (
                <View style={styles.badge}>
                  <Text style={styles.badgeText}>{cartItemCount > 99 ? '99+' : cartItemCount}</Text>
                </View>
              ) : null}
            </View>
            <Text
              numberOfLines={1}
              maxFontSizeMultiplier={1.25}
              style={[styles.tabLabel, { color: on ? theme.brand : theme.faint }]}
            >
              {tab.label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

export function SectionHead({ title, onViewAll }: { title: string; onViewAll?: () => void }) {
  const { styles, theme } = useApp();
  return (
    <View style={styles.sectionRow}>
      <Text style={styles.sectionTitle}>{title}</Text>
      {onViewAll ? (
        <Pressable onPress={onViewAll} hitSlop={8} style={styles.rowGap}>
          <Text style={styles.sectionLink}>See all</Text>
          <Icon name="chevron-forward" size={14} color={theme.brand} />
        </Pressable>
      ) : null}
    </View>
  );
}

/**
 * A category card: the photograph of a product that really is listed under that
 * name, or a plain surface with its initials when nothing in it has a picture.
 *
 * `width` is passed by the horizontal rail on Home, which scrolls and so sizes its
 * own children; everywhere else the tile takes its width from the number of
 * columns that fit the current window.
 */
export function CategoryTile({
  name,
  count,
  image,
  onPress,
  width,
}: {
  name: string;
  count?: number;
  image?: string | null;
  onPress?: () => void;
  width?: number;
}) {
  const { styles, serverUrl, theme } = useApp();
  const layout = useLayout();
  const w = width ?? layout.tileWidth;
  const photo = Math.round(Math.min(72, Math.max(46, w * 0.66)));
  const url = safeImageUrl(serverUrl, image ?? null);
  return (
    <Pressable style={[styles.tile, { width: w, paddingHorizontal: 5 }]} onPress={onPress}>
      <View style={[styles.tilePhoto, { width: photo, height: photo, borderRadius: 18 }]}>
        {url ? (
          <Image source={{ uri: url }} style={{ width: '100%', height: '100%' }} resizeMode="cover" />
        ) : (
          <Text style={{ color: theme.photoBlankText, fontSize: Math.round(photo * 0.3), fontWeight: '800' }}>
            {initials(name)}
          </Text>
        )}
      </View>
      <Text style={styles.tileLabel} numberOfLines={1} maxFontSizeMultiplier={1.2}>
        {name}
      </Text>
      {count != null ? (
        <Text style={styles.faint} numberOfLines={1}>
          {count} item{count === 1 ? '' : 's'}
        </Text>
      ) : null}
    </Pressable>
  );
}

export function Stepper({ qty, max, onChange }: { qty: number; max: number; onChange: (n: number) => void }) {
  const { styles, theme } = useApp();
  const layout = useLayout();
  // The basket fits picture, name, price, stepper and delete on one line of a
  // 320dp screen only if the stepper stops taking 12dp of air per side.
  const pad = layout.width < 360 ? 8 : 11;
  return (
    <View style={styles.stepper}>
      <Pressable style={[styles.stepperBtn, { paddingHorizontal: pad }]} onPress={() => onChange(qty - 1)} hitSlop={6}>
        <Icon name="remove" size={16} color={qty <= 1 ? theme.faint : theme.brand} />
      </Pressable>
      <Text style={styles.stepperText}>{qty}</Text>
      <Pressable
        style={[styles.stepperBtn, { paddingHorizontal: pad }]}
        onPress={() => onChange(Math.min(max, qty + 1))}
        hitSlop={6}
      >
        <Icon name="add" size={16} color={qty >= max ? theme.faint : theme.brand} />
      </Pressable>
    </View>
  );
}

export function StatusPill({ status }: { status: string }) {
  const { styles, theme } = useApp();
  const color = statusColor(status, theme);
  return (
    <View style={[styles.statusPill, { backgroundColor: statusSoftColor(status, theme) }]}>
      <View style={[styles.statusDot, { backgroundColor: color }]} />
      <Text style={[styles.statusText, { color }]}>{statusLabel(status)}</Text>
    </View>
  );
}

/** The two-up card on a shop's shelf and on the home screen's popular row. */
export function ProductGridCard({
  slug,
  product,
  shopLabel,
}: {
  slug: string;
  product: ProductRow;
  shopLabel?: string;
}) {
  const { styles, theme, addToCart, push } = useApp();
  const layout = useLayout();
  return (
    <Pressable
      style={[styles.gridCard, { width: layout.cardWidth }]}
      onPress={() => push({ name: 'product', slug, productId: product.id })}
    >
      <Thumb uri={product.image_url} height={layout.cardImageHeight} label={product.name} wide radius={0} />
      <View style={styles.gridBody}>
        <Text style={styles.title} numberOfLines={2}>
          {product.name}
        </Text>
        {shopLabel ? (
          <Text style={styles.faint} numberOfLines={1}>
            {shopLabel}
          </Text>
        ) : null}
        <Text style={product.in_stock ? styles.ok : styles.danger} numberOfLines={1}>
          {product.in_stock ? `${product.stock} available` : 'Out of stock'}
        </Text>
        {/* A price of ৳1,25,000 is wider than the card it shares with the Add
            button on a small phone, so the price gives way and the button does
            not: a clipped figure is readable, a pushed-off button is not. */}
        <View style={[styles.rowBetween, { marginTop: 4, gap: 6 }]}>
          <Text style={[styles.price, { flexShrink: 1 }]} numberOfLines={1}>
            {money(product.price)}
          </Text>
          <Pressable
            disabled={!product.in_stock}
            onPress={() => addToCart(slug, product)}
            style={({ pressed }) => [
              styles.addBtn,
              product.in_stock ? null : styles.addBtnDisabled,
              pressed && product.in_stock ? { opacity: 0.75 } : null,
              { flexShrink: 0 },
            ]}
            hitSlop={4}
          >
            <Icon name="add" size={20} color={product.in_stock ? theme.onBrand : theme.faint} />
          </Pressable>
        </View>
      </View>
    </Pressable>
  );
}

/**
 * The listing row: picture, name, which shop it came from, price and a full
 * Add to Cart button. Used by search results and the category screens, where a
 * customer is deciding between shops rather than browsing inside one.
 */
export function ProductListRow({ slug, product, shopLabel }: { slug: string; product: ProductRow; shopLabel?: string }) {
  const { styles, addToCart, push } = useApp();
  const layout = useLayout();
  return (
    <Pressable
      style={[styles.rowCard, { paddingHorizontal: layout.gutter }]}
      onPress={() => push({ name: 'product', slug, productId: product.id })}
    >
      <Thumb uri={product.image_url} size={layout.thumbSize} label={product.name} />
      {/* minWidth 0 is what lets the middle column give way: without it a long
          product name claims its full text width and pushes the Add button off
          the right edge of a narrow screen. */}
      <View style={{ flex: 1, minWidth: 0, gap: 3 }}>
        <Text style={styles.title} numberOfLines={2}>
          {product.name}
        </Text>
        {shopLabel ? (
          <Text style={styles.faint} numberOfLines={1}>
            {shopLabel}
          </Text>
        ) : null}
        <Text style={product.in_stock ? styles.ok : styles.danger} numberOfLines={1}>
          {product.in_stock ? `In stock · ${product.stock}` : 'Out of stock'}
        </Text>
        <Text style={styles.price}>{money(product.price)}</Text>
      </View>
      <Pressable
        disabled={!product.in_stock}
        onPress={() => addToCart(slug, product)}
        style={({ pressed }) => [
          styles.btn,
          styles.btnSmall,
          product.in_stock ? null : styles.btnDisabled,
          pressed && product.in_stock ? { opacity: 0.8 } : null,
          { flexShrink: 0 },
        ]}
      >
        <Text style={styles.btnSmallText}>Add</Text>
      </Pressable>
    </Pressable>
  );
}

export function Loading({ text = 'Loading…' }: { text?: string }) {
  const { styles, theme } = useApp();
  return (
    <View style={styles.empty}>
      <ActivityIndicator size="small" color={theme.brand} />
      <Text style={styles.emptyText}>{text}</Text>
    </View>
  );
}

export function EmptyState({ text, icon = 'bag-handle-outline', action }: { text: string; icon?: IconName; action?: React.ReactNode }) {
  const { styles, theme } = useApp();
  return (
    <View style={styles.empty}>
      <View style={styles.emptyIcon}>
        <Icon name={icon} size={28} color={theme.brand} />
      </View>
      <Text style={styles.emptyText}>{text}</Text>
      {action}
    </View>
  );
}

export function ErrorNote({ message }: { message: string }) {
  const { styles, theme } = useApp();
  return (
    <View style={[styles.card, { borderLeftWidth: 3, borderLeftColor: theme.danger }]}>
      <View style={[styles.rowGap, { alignItems: 'flex-start', gap: 9 }]}>
        <Icon name="alert-circle" size={18} color={theme.danger} />
        <Text style={{ color: theme.text, fontSize: 14, flex: 1 }}>{message}</Text>
      </View>
    </View>
  );
}
