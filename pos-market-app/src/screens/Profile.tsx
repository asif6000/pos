/**
 * The buyer's own settings: details used at checkout, appearance, and which
 * server this app talks to.
 *
 * The server field is not decoration. An address baked into an APK cannot be
 * changed on the phone, and the last time the shop moved hosts every installed
 * copy kept calling the old machine and reported it as a connection failure.
 *
 * There is no account to sign out of - a buyer here is a phone number and an
 * address kept on the device - so "Log out" means forgetting what this phone
 * remembers, which is the only thing it can actually do.
 */

import React, { useState } from 'react';
import { Alert, Linking, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { DEFAULT_API_PATH, DEFAULT_SERVER_URL } from '../config';
import { useLayout } from '../responsive';
import { useApp } from '../state';
import { Icon, TabBar, initials, type IconName } from '../ui';

const SUPPORT_WHATSAPP = 'https://wa.me/8801810902817';

function Row({
  icon,
  label,
  value,
  onPress,
  danger = false,
  last = false,
}: {
  icon: IconName;
  label: string;
  value?: string;
  onPress: () => void;
  danger?: boolean;
  last?: boolean;
}) {
  const { styles, theme } = useApp();
  return (
    <Pressable
      style={({ pressed }) => [
        styles.settingRow,
        { borderBottomWidth: last ? 0 : StyleSheet.hairlineWidth, borderBottomColor: theme.line },
        pressed ? { backgroundColor: theme.cardAlt } : null,
      ]}
      onPress={onPress}
    >
      <View style={[styles.settingIcon, danger ? { backgroundColor: theme.dangerSoft } : null]}>
        <Icon name={icon} size={17} color={danger ? theme.danger : theme.brand} />
      </View>
      {/* A server URL is wider than a small phone: unshrinkable value text here
          pushes the chevron off the edge, so the value gives way and the chevron
          does not. */}
      <Text style={[styles.title, { flex: 1, minWidth: 0, color: danger ? theme.danger : theme.text }]} numberOfLines={1}>
        {label}
      </Text>
      {value ? (
        <Text style={[styles.faint, { flexShrink: 1, textAlign: 'right' }]} numberOfLines={1}>
          {value}
        </Text>
      ) : null}
      <Icon name="chevron-forward" size={16} color={theme.faint} />
    </Pressable>
  );
}

/** A sheet of settings rows, with the hairline handled by the last row. */
function Sheet({ children }: { children: React.ReactNode }) {
  const { styles } = useApp();
  return <View style={[styles.cardFlat, { overflow: 'hidden', marginBottom: 14 }]}>{children}</View>;
}

export function ProfileScreen() {
  const {
    styles,
    theme,
    buyer,
    setBuyer,
    isDark,
    toggleTheme,
    serverUrl,
    apiUrl,
    setServer,
    cartItemCount,
    clearCart,
    orders,
    clearOrders,
    push,
    goToTab,
  } = useApp();
  const layout = useLayout();
  const insets = useSafeAreaInsets();
  const [name, setName] = useState(buyer.name);
  const [phone, setPhone] = useState(buyer.phone);
  const [address, setAddress] = useState(buyer.address);
  const [server, setServerField] = useState(serverUrl);
  const [path, setPath] = useState(apiUrl.replace(serverUrl, '') || DEFAULT_API_PATH);
  const [saved, setSaved] = useState('');
  const [open, setOpen] = useState('');

  function toggle(key: string) {
    setOpen(open === key ? '' : key);
  }

  function saveDetails() {
    setBuyer({ name: name.trim(), phone: phone.replace(/[^0-9]/g, ''), address: address.trim() });
    setSaved('Details saved on this phone.');
    setOpen('');
  }

  async function saveServer() {
    const trimmed = server.trim().replace(/\/+$/, '');
    if (!/^https?:\/\/.+/i.test(trimmed)) {
      setSaved('Use the full address, for example https://smartercollection.shop');
      return;
    }
    await setServer(trimmed, path.trim() || DEFAULT_API_PATH);
    setSaved('Now talking to ' + trimmed);
    setOpen('');
  }

  function logOut() {
    Alert.alert(
      'Forget this phone?',
      'This clears your saved delivery details, your cart and the order list on this device. Orders already placed with a shop are not touched.',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Forget',
          style: 'destructive',
          onPress: () => {
            clearCart();
            clearOrders();
            setBuyer({ name: '', phone: '', address: '' });
            setName('');
            setPhone('');
            setAddress('');
            goToTab('home');
          },
        },
      ],
    );
  }

  const panel = (key: string, body: React.ReactNode) =>
    open === key ? <View style={{ paddingHorizontal: 15, paddingBottom: 16 }}>{body}</View> : null;

  return (
    <View style={styles.screen}>
      <View style={[styles.hero, { paddingTop: insets.top + 18, paddingBottom: 26, alignItems: 'center' }]}>
        <View style={styles.heroGlow} />
        <View
          style={{
            width: 74,
            height: 74,
            borderRadius: 37,
            backgroundColor: 'rgba(255,255,255,0.18)',
            alignItems: 'center',
            justifyContent: 'center',
            borderWidth: 2,
            borderColor: 'rgba(255,255,255,0.35)',
          }}
        >
          <Text style={{ color: theme.onHero, fontSize: 25, fontWeight: '800' }}>{initials(buyer.name || 'Guest')}</Text>
        </View>
        <Text style={[styles.heroTitle, { marginTop: 12, textAlign: 'center', maxWidth: '100%' }]} numberOfLines={2}>
          {buyer.name || 'Guest customer'}
        </Text>
        <Text style={[styles.heroSub, { marginTop: 2 }]}>{buyer.phone || 'No phone saved yet'}</Text>
      </View>

      <ScrollView contentContainerStyle={{ paddingBottom: layout.bottomSpace }}>
        <View style={{ paddingHorizontal: layout.contentMargin }}>
          <Sheet>
            <Row icon="receipt-outline" label="My Orders" value={`${orders.length}`} onPress={() => push({ name: 'orders' })} />
            <Row
              icon="location-outline"
              label="Delivery details"
              value={buyer.address ? 'saved' : 'not set'}
              onPress={() => toggle('details')}
              last={open === 'details'}
            />
            {panel(
              'details',
              <View>
                <Text style={styles.label}>Name</Text>
                <TextInput value={name} onChangeText={setName} style={styles.input} placeholder="Your name" placeholderTextColor={theme.faint} />
                <Text style={styles.label}>Phone</Text>
                <TextInput
                  value={phone}
                  onChangeText={setPhone}
                  keyboardType="phone-pad"
                  style={styles.input}
                  placeholder="01XXXXXXXXX"
                  placeholderTextColor={theme.faint}
                />
                <Text style={styles.label}>Address</Text>
                <TextInput
                  value={address}
                  onChangeText={setAddress}
                  multiline
                  style={[styles.input, styles.inputMulti]}
                  placeholder="House, road, area"
                  placeholderTextColor={theme.faint}
                />
                <Pressable
                  style={({ pressed }) => [styles.btn, { marginTop: 14 }, pressed ? styles.btnPressed : null]}
                  onPress={saveDetails}
                >
                  <Text style={styles.btnText}>Save</Text>
                </Pressable>
                {saved ? <Text style={[styles.ok, { marginTop: 10 }]}>{saved}</Text> : null}
              </View>,
            )}
            <Row icon="cart-outline" label="My Cart" value={`${cartItemCount} item${cartItemCount === 1 ? '' : 's'}`} onPress={() => goToTab('cart')} />
          </Sheet>

          <Sheet>
            <Row icon={isDark ? 'moon' : 'sunny-outline'} label="Dark mode" value={isDark ? 'On' : 'Off'} onPress={toggleTheme} />
            <Row icon="notifications-outline" label="Notifications" value="by phone settings" onPress={() => toggle('notify')} last={open === 'notify'} />
            {panel(
              'notify',
              <Text style={styles.sub}>
                Each shop calls the number on an order to confirm it. This app does not send its own notifications, so
                switch them on for it in the phone’s settings only if a shop asks you to.
              </Text>,
            )}
            <Row
              icon="globe-outline"
              label="Shop server"
              value={serverUrl.replace(/^https?:\/\//, '')}
              onPress={() => toggle('server')}
              last={open === 'server'}
            />
            {panel(
              'server',
              <View>
                <Text style={styles.faint}>Only change this if the shop tells you the address moved.</Text>
                <Text style={styles.label}>Address</Text>
                <TextInput
                  value={server}
                  onChangeText={setServerField}
                  autoCapitalize="none"
                  keyboardType="url"
                  style={styles.input}
                  placeholder={DEFAULT_SERVER_URL}
                  placeholderTextColor={theme.faint}
                />
                <Text style={styles.label}>API path</Text>
                <TextInput
                  value={path}
                  onChangeText={setPath}
                  autoCapitalize="none"
                  style={styles.input}
                  placeholder={DEFAULT_API_PATH}
                  placeholderTextColor={theme.faint}
                />
                <Pressable
                  style={({ pressed }) => [styles.btnSoft, { marginTop: 14 }, pressed ? styles.btnSoftPressed : null]}
                  onPress={saveServer}
                >
                  <Text style={styles.btnSoftText}>Connect</Text>
                </Pressable>
              </View>,
            )}
          </Sheet>

          <Sheet>
            <Row icon="logo-whatsapp" label="Help & Support" value="01810902817" onPress={() => Linking.openURL(SUPPORT_WHATSAPP)} />
            <Row icon="information-circle-outline" label="About Smart POS" onPress={() => toggle('about')} last={open === 'about'} />
            {panel(
              'about',
              <View>
                <Text style={styles.sub}>
                  Orders go straight to each shop’s own Smart POS till. Nothing is charged in this app — you pay in cash
                  when the shop arrives.
                </Text>
                <View style={styles.divider} />
                <View style={styles.rowBetween}>
                  <Text style={styles.sub}>Orders kept on this phone</Text>
                  <Text style={styles.faint}>{orders.length}</Text>
                </View>
                <View style={styles.rowBetween}>
                  <Text style={styles.sub}>Cart</Text>
                  <Pressable onPress={clearCart} disabled={cartItemCount === 0} hitSlop={8}>
                    <Text style={cartItemCount ? styles.danger : styles.faint}>
                      {cartItemCount} item{cartItemCount === 1 ? '' : 's'} · empty
                    </Text>
                  </Pressable>
                </View>
              </View>,
            )}
            <Row icon="log-out-outline" label="Log out" onPress={logOut} danger last />
          </Sheet>
        </View>
      </ScrollView>
      <TabBar />
    </View>
  );
}
