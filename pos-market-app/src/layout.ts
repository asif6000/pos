/**
 * The arithmetic behind every size in the app, kept away from React on purpose.
 *
 * A phone is not one width. The same build runs on a 320dp budget handset, a
 * 412dp everyday Android, a 768dp tablet and any of them turned sideways, and the
 * screens used to answer all of those with the numbers a mockup happened to
 * draw: a card at '48%', a cover at 190px, a checkout button 84px off the bottom.
 * Those read as broken the moment the window stops being the one that was
 * designed for - the grid runs off a small screen, and a floating button lands
 * under the phone's own navigation bar on a newer one.
 *
 * So the window goes in and the numbers come out, in one place:
 *
 *   width, height  the window, in dp (device-independent pixels)
 *   bottomInset    the phone's navigation bar / home indicator, in dp
 *
 * This file imports nothing from React Native. That is deliberate: the rules
 * below are the kind that can be checked by running them over a list of real
 * screen widths, and a file that imports Dimensions cannot be run outside the
 * app. src/responsive.ts is the thin hook that feeds it.
 */

/** The tab bar's own height, not counting the phone's bottom inset. */
export const TAB_BAR_HEIGHT = 62;

/** Keeps a line of text or a form from stretching across a tablet. */
export const CONTENT_MAX = 620;

export type Layout = {
  width: number;
  height: number;

  /** Horizontal padding at the edge of a screen. */
  gutter: number;
  /** Space between two cards, in both directions. */
  gap: number;

  /** Product cards in one row of a grid. */
  cols: number;
  /** Category tiles in one row. */
  tileCols: number;

  /** Exact card width, so a row of `cols` of them plus the gaps fills the screen. */
  cardWidth: number;
  /** Exact tile width, same rule for `tileCols`. */
  tileWidth: number;
  /** The picture inside a grid card: a fixed ratio of the card, capped. */
  cardImageHeight: number;

  /** A shop's cover on its own page. */
  coverHeight: number;
  /** A product's main picture. */
  pageImageHeight: number;
  /** A shop card in Home's horizontal "Featured Shops" rail. */
  railCardWidth: number;
  /** A category tile in Home's horizontal rail. */
  railTileWidth: number;
  /** The square picture at the left of a list row. */
  thumbSize: number;
  /** A shop's thumbnail inside a wider row. */
  rowCoverWidth: number;

  /** Widest a column of text or forms should get, and the margin that centres it. */
  contentMax: number;
  contentMargin: number;
  /** Horizontal padding for a page of cards: the gutter, plus the centring margin. */
  contentPadX: number;

  bottomInset: number;
  /** Tab bar including that inset - what anything above it must clear. */
  tabBarHeight: number;
  /** Where a floating bar or button sits: clear the tab bar, then breathe. */
  barFloor: number;
  /** Scroll padding at the foot of a list, so the last card clears the bars. */
  bottomSpace: number;
};

function clamp(value: number, min: number, max: number): number {
  return Math.min(Math.max(value, min), max);
}

/** Whole pixels, because half a dp rounds differently on different devices. */
function px(value: number): number {
  return Math.round(value);
}

export function layoutFor(width: number, height: number, bottomInset: number): Layout {
  const narrow = width < 360;

  const gutter = narrow ? 10 : 14;
  const gap = narrow ? 8 : 10;
  const cols = width >= 900 ? 4 : width >= 620 ? 3 : 2;
  const tileCols = width >= 900 ? 6 : width >= 620 ? 5 : width >= 380 ? 4 : 3;

  const available = width - gutter * 2;
  // Floored, not rounded: a card that is half a pixel wider than its share adds
  // up across a row of four or six and the last one wraps onto its own line.
  const cardWidth = Math.floor((available - gap * (cols - 1)) / cols);
  const tileWidth = Math.floor((available - gap * (tileCols - 1)) / tileCols);

  const contentMax = px(Math.min(width, CONTENT_MAX));
  const contentMargin = px((width - contentMax) / 2);

  // A sideways phone is short: 360dp of height is normal, so a picture sized
  // from the width alone can fill the whole window and push the buy button off
  // the screen. Both heights are read against whichever axis is tighter.
  const short = Math.min(width, height);

  const tabBarHeight = px(TAB_BAR_HEIGHT + bottomInset);

  return {
    width,
    height,
    gutter,
    gap,
    cols,
    tileCols,
    cardWidth,
    tileWidth,
    cardImageHeight: px(clamp(cardWidth * 0.8, 92, 168)),
    coverHeight: px(clamp(Math.min(width * 0.5, short * 0.42), 140, 220)),
    pageImageHeight: px(clamp(Math.min(width * 0.72, short * 0.5), 180, 300)),
    railCardWidth: px(clamp(width * 0.46, 150, 240)),
    railTileWidth: px(clamp(width * 0.27, 92, 128)),
    thumbSize: narrow ? 56 : 70,
    rowCoverWidth: px(clamp(width * 0.22, 72, 110)),
    contentMax,
    contentMargin,
    contentPadX: gutter + contentMargin,
    bottomInset,
    tabBarHeight,
    barFloor: px(tabBarHeight + 12),
    bottomSpace: px(tabBarHeight + 88),
  };
}
