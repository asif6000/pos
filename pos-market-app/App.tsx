/**
 * Smart POS Marketplace - the customer app.
 *
 * It is a thin front end over shop/api.php, the same public JSON door the web
 * storefront (shop/index.php) uses, which is what keeps a phone order and a
 * website order identical by the time they reach a shop.
 *
 * Navigation is a stack in state rather than a router library: five tabs and four
 * pushed screens do not need a dependency, and the staff app proved the pattern
 * survives a real release. Android's back button pops the stack and only leaves
 * the app at the root.
 */

import React, { useEffect, useState } from 'react';
import { BackHandler, View } from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { AppProvider, useApp } from './src/state';
import { CategoriesScreen, HomeScreen, ShopsScreen } from './src/screens/Browse';
import { ListingScreen } from './src/screens/Listing';
import { ProductScreen, ShopScreen } from './src/screens/Shop';
import { CartScreen, CheckoutScreen } from './src/screens/Basket';
import { OrdersScreen } from './src/screens/Orders';
import { ProfileScreen } from './src/screens/Profile';
import { Splash } from './src/ui';

function CurrentScreen() {
  const { route } = useApp();
  // The keys on the pushed routes remount them per slug/product/category, so a
  // shop's search box and fetched shelf cannot carry over to the next shop.
  switch (route.name) {
    case 'categories':
      return <CategoriesScreen />;
    case 'shops':
      return <ShopsScreen />;
    case 'listing':
      return <ListingScreen key={`listing-${route.q || ''}-${route.category || ''}`} q={route.q} category={route.category} />;
    case 'shop':
      return <ShopScreen key={route.slug} slug={route.slug} />;
    case 'product':
      return <ProductScreen key={`${route.slug}-${route.productId}`} slug={route.slug} productId={route.productId} />;
    case 'cart':
      return <CartScreen />;
    case 'checkout':
      return <CheckoutScreen />;
    case 'orders':
      return <OrdersScreen />;
    case 'profile':
      return <ProfileScreen />;
    default:
      return <HomeScreen />;
  }
}

function Root() {
  const { pop, canGoBack, ready } = useApp();
  // Storage usually wins the race, and a splash that flashes for 80ms reads as a
  // bug, so the screen is held for a beat either way.
  const [held, setHeld] = useState(true);
  useEffect(() => {
    const timer = setTimeout(() => setHeld(false), 1400);
    return () => clearTimeout(timer);
  }, []);

  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      if (canGoBack) {
        pop();
        return true;
      }
      return false;
    });
    return () => sub.remove();
  }, [canGoBack, pop]);

  if (held || !ready) {
    return <Splash />;
  }

  return (
    <View style={{ flex: 1 }}>
      {/* Every screen opens on the brand panel, dark theme or light, so the phone's
          own clock and battery icons are always the light pair. */}
      <StatusBar style="light" />
      <CurrentScreen />
    </View>
  );
}

export default function App() {
  return (
    <SafeAreaProvider>
      <AppProvider>
        <Root />
      </AppProvider>
    </SafeAreaProvider>
  );
}
