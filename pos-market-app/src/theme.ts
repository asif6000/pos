/**
 * Colours and type for the whole app.
 *
 * One palette object per theme and one stylesheet built from it, so a dark mode
 * toggle is a re-render rather than a second copy of every screen.
 *
 * The marketplace is a shopping app, not a till: the customer reads photographs,
 * so surfaces stay quiet (a hairline border and one soft shadow do the work a
 * heavy drop shadow used to) and the brand colour is reserved for things to
 * press and numbers to compare. Nothing here paints a tile by guessing from a
 * name - a picture comes from the server or the card shows a plain surface.
 */

import { StyleSheet } from 'react-native';

export type Palette = {
  bg: string;
  card: string;
  cardAlt: string;
  text: string;
  sub: string;
  faint: string;
  line: string;
  brand: string;
  brandDark: string;
  brandSoft: string;
  onBrand: string;
  onBrandSoft: string;
  /** The two ends of the header panel, and the light sitting on top of it. */
  heroTop: string;
  heroBottom: string;
  onHero: string;
  onHeroSoft: string;
  /** A picture that has not loaded, or a card with no picture at all. */
  photoBlank: string;
  photoBlankText: string;
  ok: string;
  okSoft: string;
  warn: string;
  warnSoft: string;
  danger: string;
  dangerSoft: string;
  star: string;
  chip: string;
  chipOn: string;
  chipOnText: string;
  input: string;
  shadow: string;
};

export const lightPalette: Palette = {
  bg: '#F4F6FB',
  card: '#FFFFFF',
  cardAlt: '#EEF3FD',
  text: '#0C1526',
  sub: '#5A6A85',
  faint: '#93A1B8',
  line: '#E7EBF4',
  brand: '#1B5CFF',
  brandDark: '#0E3FCB',
  brandSoft: '#EAF1FF',
  onBrand: '#FFFFFF',
  onBrandSoft: '#1648C8',
  heroTop: '#1B5CFF',
  heroBottom: '#0E3FCB',
  onHero: '#FFFFFF',
  onHeroSoft: 'rgba(255,255,255,0.78)',
  photoBlank: '#EDF1F8',
  photoBlankText: '#9AA8BF',
  ok: '#0F9D58',
  okSoft: '#E3F6EC',
  warn: '#B4690E',
  warnSoft: '#FCEFD9',
  danger: '#DC2626',
  dangerSoft: '#FCE7E7',
  star: '#F59E0B',
  chip: '#EDF1F8',
  chipOn: '#1B5CFF',
  chipOnText: '#FFFFFF',
  input: '#FFFFFF',
  shadow: 'rgba(12,21,38,0.09)',
};

export const darkPalette: Palette = {
  bg: '#0A1120',
  card: '#131C2E',
  cardAlt: '#1B2537',
  text: '#E9EEF9',
  sub: '#9EAFCA',
  faint: '#6C7E99',
  line: '#243044',
  brand: '#4C8BFF',
  brandDark: '#2563EB',
  brandSoft: '#16233C',
  onBrand: '#FFFFFF',
  onBrandSoft: '#A9C8FF',
  heroTop: '#16233C',
  heroBottom: '#0A1120',
  onHero: '#E9EEF9',
  onHeroSoft: '#9EAFCA',
  photoBlank: '#1B2537',
  photoBlankText: '#6C7E99',
  ok: '#4ADE80',
  okSoft: '#12301F',
  warn: '#FBBF24',
  warnSoft: '#33270D',
  danger: '#F87171',
  dangerSoft: '#3A1616',
  star: '#FBBF24',
  chip: '#1E293B',
  chipOn: '#4C8BFF',
  chipOnText: '#FFFFFF',
  input: '#0F172A',
  shadow: 'rgba(0,0,0,0.45)',
};

export type Styles = ReturnType<typeof makeStyles>;

/** Corner radii, so a card, a tile and a sheet agree with each other. */
export const R = { sm: 12, md: 16, lg: 22, xl: 28, pill: 999 };

export function makeStyles(t: Palette) {
  return StyleSheet.create({
    screen: { flex: 1, backgroundColor: t.bg },
    scroll: { flexGrow: 1, padding: 14, paddingBottom: 120 },

    // ── Headers ────────────────────────────────────────────────
    // The brand panel is the app's identity: a tall one on Home, a flat one on a
    // pushed screen, and the same type on both.
    hero: {
      backgroundColor: t.heroTop,
      paddingHorizontal: 16,
      paddingTop: 52,
      paddingBottom: 22,
      gap: 4,
      borderBottomLeftRadius: R.xl,
      borderBottomRightRadius: R.xl,
    },
    heroGlow: {
      position: 'absolute',
      top: -70,
      right: -50,
      width: 190,
      height: 190,
      borderRadius: R.pill,
      backgroundColor: t.heroBottom,
      opacity: 0.55,
    },
    heroTitle: { color: t.onHero, fontSize: 22, fontWeight: '800', letterSpacing: -0.3 },
    heroSub: { color: t.onHeroSoft, fontSize: 13 },
    header: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      paddingHorizontal: 12,
      paddingTop: 46,
      paddingBottom: 14,
      backgroundColor: t.heroTop,
      borderBottomLeftRadius: R.lg,
      borderBottomRightRadius: R.lg,
    },
    headerTitle: { color: t.onHero, fontSize: 17, fontWeight: '800', flex: 1, letterSpacing: -0.2 },
    headerSub: { color: t.onHeroSoft, fontSize: 12 },
    headerBtn: { color: t.onHero, fontSize: 15, fontWeight: '700', paddingHorizontal: 4 },
    /** The round button on a brand header: back, refresh, cart. */
    iconBtn: {
      width: 38,
      height: 38,
      borderRadius: R.pill,
      alignItems: 'center',
      justifyContent: 'center',
      backgroundColor: 'rgba(255,255,255,0.16)',
    },
    locationRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
    locationLabel: { color: t.onHeroSoft, fontSize: 11, fontWeight: '600' },
    locationValue: { color: t.onHero, fontSize: 15, fontWeight: '800' },

    // ── Search ─────────────────────────────────────────────────
    searchRow: { flexDirection: 'row', gap: 8, marginTop: 14 },
    search: {
      flex: 1,
      flexDirection: 'row',
      alignItems: 'center',
      gap: 9,
      backgroundColor: t.card,
      borderRadius: R.md,
      paddingHorizontal: 13,
      paddingVertical: 12,
      shadowColor: t.shadow,
      shadowOpacity: 1,
      shadowRadius: 12,
      shadowOffset: { width: 0, height: 5 },
      elevation: 3,
    },
    searchOnBrand: { backgroundColor: t.card },
    searchInput: { flex: 1, color: t.text, fontSize: 15, padding: 0 },
    searchIcon: { color: t.faint },

    // ── Cards & sections ───────────────────────────────────────
    card: {
      backgroundColor: t.card,
      borderRadius: R.lg,
      padding: 15,
      marginBottom: 12,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: t.line,
      shadowColor: t.shadow,
      shadowOpacity: 1,
      shadowRadius: 14,
      shadowOffset: { width: 0, height: 5 },
      elevation: 2,
    },
    cardFlat: {
      backgroundColor: t.card,
      borderRadius: R.lg,
      marginBottom: 12,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: t.line,
    },
    sectionRow: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      marginTop: 4,
      marginBottom: 12,
    },
    sectionTitle: { color: t.text, fontSize: 17, fontWeight: '800', letterSpacing: -0.2 },
    sectionLink: { color: t.brand, fontSize: 13, fontWeight: '700' },
    rowBetween: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    rowGap: { flexDirection: 'row', alignItems: 'center', gap: 10 },

    // ── Type ──────────────────────────────────────────────────
    title: { color: t.text, fontSize: 15, fontWeight: '600' },
    titleBold: { color: t.text, fontSize: 17, fontWeight: '800', letterSpacing: -0.2 },
    sub: { color: t.sub, fontSize: 13 },
    faint: { color: t.faint, fontSize: 12 },
    price: { color: t.brand, fontSize: 16, fontWeight: '800', letterSpacing: -0.2 },
    priceBig: { color: t.brand, fontSize: 26, fontWeight: '800', letterSpacing: -0.6 },
    strike: { color: t.faint, fontSize: 13, textDecorationLine: 'line-through' },
    ok: { color: t.ok, fontSize: 12, fontWeight: '700' },
    warn: { color: t.warn, fontSize: 12, fontWeight: '700' },
    danger: { color: t.danger, fontSize: 13, fontWeight: '700' },
    star: { color: t.star, fontSize: 12, fontWeight: '700' },

    // ── Chips & pills ──────────────────────────────────────────
    chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 10 },
    chipScrollRow: { flexDirection: 'row', gap: 8, paddingRight: 14 },
    chip: {
      backgroundColor: t.chip,
      borderRadius: R.pill,
      paddingHorizontal: 15,
      paddingVertical: 9,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: 'transparent',
    },
    chipOn: { backgroundColor: t.chipOn, borderColor: t.chipOn },
    chipText: { color: t.sub, fontSize: 13, fontWeight: '700' },
    chipTextOn: { color: t.chipOnText, fontSize: 13, fontWeight: '800' },
    statusPill: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 5,
      borderRadius: R.pill,
      paddingHorizontal: 10,
      paddingVertical: 5,
      alignSelf: 'flex-start',
    },
    statusText: { fontSize: 11, fontWeight: '800' },
    statusDot: { width: 6, height: 6, borderRadius: 3 },
    tag: { borderRadius: R.sm, paddingHorizontal: 9, paddingVertical: 4, alignSelf: 'flex-start' },
    tagText: { fontSize: 11, fontWeight: '800' },
    /** A small label laid over a photograph. */
    photoBadge: {
      position: 'absolute',
      left: 8,
      top: 8,
      borderRadius: R.pill,
      paddingHorizontal: 9,
      paddingVertical: 4,
      backgroundColor: 'rgba(12,21,38,0.72)',
    },
    photoBadgeText: { color: '#FFFFFF', fontSize: 11, fontWeight: '800' },

    // ── Forms ──────────────────────────────────────────────────
    input: {
      backgroundColor: t.input,
      borderWidth: 1,
      borderColor: t.line,
      borderRadius: R.md,
      paddingHorizontal: 13,
      paddingVertical: 12,
      color: t.text,
      fontSize: 15,
    },
    inputMulti: { minHeight: 84, textAlignVertical: 'top' },
    label: { color: t.sub, fontSize: 12, fontWeight: '800', marginBottom: 6, marginTop: 12 },

    // ── Buttons ───────────────────────────────────────────────
    btn: {
      backgroundColor: t.brand,
      borderRadius: R.md,
      paddingVertical: 15,
      alignItems: 'center',
      justifyContent: 'center',
      shadowColor: t.shadow,
      shadowOpacity: 1,
      shadowRadius: 10,
      shadowOffset: { width: 0, height: 5 },
      elevation: 3,
    },
    btnPressed: { backgroundColor: t.brandDark },
    btnDisabled: { backgroundColor: t.faint, shadowOpacity: 0, elevation: 0 },
    btnText: { color: t.onBrand, fontSize: 16, fontWeight: '800', letterSpacing: -0.2 },
    btnSmall: { borderRadius: R.sm, paddingVertical: 8, paddingHorizontal: 13, shadowOpacity: 0, elevation: 0 },
    btnSmallText: { color: t.onBrand, fontSize: 13, fontWeight: '800' },
    btnGhost: {
      backgroundColor: t.card,
      borderWidth: 1,
      borderColor: t.line,
      borderRadius: R.md,
      paddingVertical: 13,
      alignItems: 'center',
      justifyContent: 'center',
    },
    btnGhostPressed: { backgroundColor: t.cardAlt },
    btnGhostText: { color: t.brand, fontSize: 15, fontWeight: '800' },
    btnSoft: { backgroundColor: t.brandSoft, borderRadius: R.md, paddingVertical: 13, alignItems: 'center', justifyContent: 'center' },
    btnSoftPressed: { backgroundColor: t.cardAlt },
    btnSoftText: { color: t.onBrandSoft, fontSize: 15, fontWeight: '800' },
    btnDanger: { backgroundColor: t.danger, borderRadius: R.md, paddingVertical: 12, alignItems: 'center' },
    /** The round "add to cart" control on a product card. */
    addBtn: {
      width: 34,
      height: 34,
      borderRadius: R.pill,
      backgroundColor: t.brand,
      alignItems: 'center',
      justifyContent: 'center',
    },
    addBtnDisabled: { backgroundColor: t.chip },

    // ── Images ─────────────────────────────────────────────────
    thumb: {
      width: 74,
      height: 74,
      borderRadius: R.md,
      backgroundColor: t.photoBlank,
      alignItems: 'center',
      justifyContent: 'center',
      overflow: 'hidden',
    },
    thumbBig: { width: '100%', height: 240, borderRadius: R.lg, backgroundColor: t.photoBlank },
    thumbText: { color: t.photoBlankText, fontSize: 13, fontWeight: '800' },
    cover: { width: '100%', height: 190, backgroundColor: t.photoBlank, alignItems: 'center', justifyContent: 'center' },

    // ── Quantity stepper ───────────────────────────────────────
    stepper: {
      flexDirection: 'row',
      alignItems: 'center',
      alignSelf: 'flex-start',
      backgroundColor: t.chip,
      borderRadius: R.pill,
      padding: 3,
      gap: 2,
    },
    stepperBtn: { paddingHorizontal: 11, paddingVertical: 7 },
    stepperText: { color: t.text, fontSize: 15, fontWeight: '800', minWidth: 24, textAlign: 'center' },

    // ── Badges, bars, tabs ─────────────────────────────────────
    badge: {
      position: 'absolute',
      top: 2,
      right: 4,
      backgroundColor: t.danger,
      borderRadius: R.pill,
      minWidth: 18,
      height: 18,
      alignItems: 'center',
      justifyContent: 'center',
      paddingHorizontal: 4,
      borderWidth: 2,
      borderColor: t.card,
    },
    badgeText: { color: '#FFFFFF', fontSize: 10, fontWeight: '800' },
    // No `bottom` here: the offset is the phone's own navigation bar plus the tab
    // bar, which is layout.barFloor, and a hardcoded one let this land behind
    // Android's gesture pill on phones that have one.
    floatBar: {
      position: 'absolute',
      left: 14,
      right: 14,
      backgroundColor: t.brandDark,
      borderRadius: R.md,
      paddingHorizontal: 16,
      paddingVertical: 14,
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      gap: 10,
      shadowColor: t.shadow,
      shadowOpacity: 1,
      shadowRadius: 14,
      shadowOffset: { width: 0, height: 7 },
      elevation: 7,
    },
    floatText: { color: t.onBrand, fontSize: 15, fontWeight: '800', flexShrink: 1 },
    tabBar: {
      position: 'absolute',
      left: 0,
      right: 0,
      bottom: 0,
      flexDirection: 'row',
      backgroundColor: t.card,
      borderTopWidth: StyleSheet.hairlineWidth,
      borderTopColor: t.line,
      paddingTop: 8,
      shadowColor: t.shadow,
      shadowOpacity: 1,
      shadowRadius: 10,
      shadowOffset: { width: 0, height: -4 },
      elevation: 9,
    },
    tabBtn: { flex: 1, alignItems: 'center', gap: 4 },
    tabIconBox: {
      width: 56,
      height: 32,
      borderRadius: R.pill,
      alignItems: 'center',
      justifyContent: 'center',
    },
    tabIconBoxOn: { backgroundColor: t.brandSoft },
    tabLabel: { fontSize: 11, fontWeight: '700' },

    // ── Grids ──────────────────────────────────────────────────
    // No width here on purpose. A card at '48%' is a mockup's screen answering
    // for every screen: it is too tight on a 320dp handset and absurd on a
    // tablet. src/layout.ts works the width out from the window, and
    // ProductCard / CategoryTile apply it.
    grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
    gridCard: {
      backgroundColor: t.card,
      borderRadius: R.lg,
      overflow: 'hidden',
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: t.line,
      shadowColor: t.shadow,
      shadowOpacity: 1,
      shadowRadius: 12,
      shadowOffset: { width: 0, height: 4 },
      elevation: 2,
    },
    gridBody: { padding: 11, gap: 4 },
    tile: {
      backgroundColor: t.card,
      borderRadius: R.lg,
      alignItems: 'center',
      paddingVertical: 10,
      gap: 7,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: t.line,
      shadowColor: t.shadow,
      shadowOpacity: 1,
      shadowRadius: 12,
      shadowOffset: { width: 0, height: 4 },
      elevation: 2,
    },
    tilePhoto: {
      alignItems: 'center',
      justifyContent: 'center',
      overflow: 'hidden',
      backgroundColor: t.photoBlank,
    },
    tileLabel: { color: t.text, fontSize: 12, fontWeight: '700', textAlign: 'center' },

    // ── Lists ──────────────────────────────────────────────────
    rowCard: {
      flexDirection: 'row',
      alignItems: 'center',
      backgroundColor: t.card,
      borderRadius: R.lg,
      paddingHorizontal: 14,
      paddingVertical: 14,
      gap: 12,
      marginBottom: 10,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: t.line,
      shadowColor: t.shadow,
      shadowOpacity: 1,
      shadowRadius: 10,
      shadowOffset: { width: 0, height: 3 },
      elevation: 1,
    },
    /** A settings row inside one sheet: hairline between rows, no card around it. */
    settingRow: {
      flexDirection: 'row',
      alignItems: 'center',
      backgroundColor: t.card,
      paddingHorizontal: 15,
      paddingVertical: 15,
      gap: 12,
    },
    settingIcon: {
      width: 38,
      height: 38,
      borderRadius: R.md,
      backgroundColor: t.brandSoft,
      alignItems: 'center',
      justifyContent: 'center',
      flexShrink: 0,
    },

    // ── Misc ───────────────────────────────────────────────────
    empty: { alignItems: 'center', paddingVertical: 44, gap: 12, paddingHorizontal: 26 },
    emptyText: { color: t.sub, fontSize: 14, textAlign: 'center', lineHeight: 20 },
    emptyIcon: {
      width: 66,
      height: 66,
      borderRadius: R.pill,
      backgroundColor: t.cardAlt,
      alignItems: 'center',
      justifyContent: 'center',
    },
    divider: { height: StyleSheet.hairlineWidth, backgroundColor: t.line, marginVertical: 11 },
    loading: { color: t.sub, fontSize: 14, textAlign: 'center', paddingVertical: 30 },
  });
}

/** ৳ with thousands separators and no trailing .00 - the shop's own style. */
export function money(value: number | string): string {
  const n = typeof value === 'string' ? Number(value.replace(/[^0-9.\-]/g, '')) : value;
  if (!isFinite(n)) return '৳0';
  const rounded = Math.round(n * 100) / 100;
  const fixed = Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(2);
  const [whole, frac] = fixed.split('.');
  const withCommas = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return `৳${withCommas}${frac ? '.' + frac : ''}`;
}

export function statusColor(status: string, t: Palette): string {
  if (status === 'delivered' || status === 'accepted') return t.ok;
  if (status === 'rejected' || status === 'cancelled') return t.danger;
  return t.warn;
}

/** The tinted background behind a status pill. */
export function statusSoftColor(status: string, t: Palette): string {
  if (status === 'delivered' || status === 'accepted') return t.okSoft;
  if (status === 'rejected' || status === 'cancelled') return t.dangerSoft;
  return t.warnSoft;
}

export function statusLabel(status: string): string {
  if (status === 'pending') return 'Processing';
  if (status === 'accepted') return 'Confirmed';
  if (status === 'delivered') return 'Delivered';
  if (status === 'rejected' || status === 'cancelled') return 'Cancelled';
  return status;
}
