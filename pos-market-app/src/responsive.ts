/**
 * The window, as numbers a screen can use.
 *
 * useWindowDimensions re-renders the app when the device rotates or when a
 * foldable unfolds, and useSafeAreaInsets reports the phone's own navigation bar,
 * which is what a floating button used to guess at with a hardcoded offset. Both
 * feed src/layout.ts, which holds the arithmetic and nothing else - so the sizes
 * can be checked over a list of real screen widths without running the app.
 */

import { useWindowDimensions } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { layoutFor, type Layout } from './layout';

export type { Layout };

export function useLayout(): Layout {
  const { width, height } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  // A tablet with an edge-to-edge gesture bar can report a left or right inset
  // instead of a bottom one; the bars in this app are at the bottom either way.
  return layoutFor(width, height, Math.max(insets.bottom, 0));
}
