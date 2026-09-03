import React, { useState, useCallback } from 'react';
import { View, Text, TextInput, StyleSheet, ScrollView, Alert } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { supabase } from '../../config/supabase';
import { useAuth } from '../../context/AuthContext';
import { COLORS } from '../../utils/helpers';
import Button from '../../components/Button';
import LoadingSpinner from '../../components/LoadingSpinner';

const SettingsScreen = ({ navigation }) => {
  const { logout, user } = useAuth();
  const [shopName, setShopName] = useState('');
  const [shopAddress, setShopAddress] = useState('');
  const [shopPhone, setShopPhone] = useState('');
  const [shopEmail, setShopEmail] = useState('');
  const [currency, setCurrency] = useState('৳');
  const [vatPercent, setVatPercent] = useState('0');
  const [receiptFooter, setReceiptFooter] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const fetchSettings = async () => {
    try {
      const { data: { user: authUser } } = await supabase.auth.getUser();
      if (!authUser) return;
      const { data } = await supabase.from('settings').select('*').eq('owner_id', authUser.id).single();
      if (data) {
        setShopName(data.shop_name || '');
        setShopAddress(data.shop_address || '');
        setShopPhone(data.shop_phone || '');
        setShopEmail(data.shop_email || '');
        setCurrency(data.currency || '৳');
        setVatPercent(String(data.vat_percent || 0));
        setReceiptFooter(data.receipt_footer || '');
      }
    } catch (error) {
      console.error('Fetch settings error:', error);
    } finally {
      setLoading(false);
    }
  };

  useFocusEffect(useCallback(() => { fetchSettings(); }, []));

  const handleSave = async () => {
    setSaving(true);
    try {
      const { data: { user: authUser } } = await supabase.auth.getUser();
      if (!authUser) throw new Error('Not authenticated');

      const { error } = await supabase.from('settings').upsert({
        owner_id: authUser.id,
        shop_name: shopName.trim(),
        shop_address: shopAddress.trim(),
        shop_phone: shopPhone.trim(),
        shop_email: shopEmail.trim(),
        currency,
        vat_percent: parseFloat(vatPercent) || 0,
        receipt_footer: receiptFooter.trim(),
      }, { onConflict: 'owner_id' });
      if (error) throw error;
      Alert.alert('Success', 'Settings saved');
    } catch (error) {
      Alert.alert('Error', error.message || 'Failed to save settings');
    } finally {
      setSaving(false);
    }
  };

  const handleLogout = () => {
    Alert.alert('Logout', 'Are you sure you want to logout?', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Logout', style: 'destructive', onPress: logout },
    ]);
  };

  if (loading) return <LoadingSpinner />;

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <View style={styles.card}>
        <Text style={styles.sectionTitle}>Shop Information</Text>

        <Text style={styles.label}>Shop Name</Text>
        <TextInput style={styles.input} value={shopName} onChangeText={setShopName} placeholder="Shop name" placeholderTextColor={COLORS.muted} />

        <Text style={styles.label}>Address</Text>
        <TextInput style={styles.input} value={shopAddress} onChangeText={setShopAddress} placeholder="Address" placeholderTextColor={COLORS.muted} />

        <Text style={styles.label}>Phone</Text>
        <TextInput style={styles.input} value={shopPhone} onChangeText={setShopPhone} placeholder="Phone number" placeholderTextColor={COLORS.muted} keyboardType="phone-pad" />

        <Text style={styles.label}>Email</Text>
        <TextInput style={styles.input} value={shopEmail} onChangeText={setShopEmail} placeholder="Email" placeholderTextColor={COLORS.muted} keyboardType="email-address" autoCapitalize="none" />
      </View>

      <View style={styles.card}>
        <Text style={styles.sectionTitle}>Preferences</Text>

        <Text style={styles.label}>Currency Symbol</Text>
        <TextInput style={styles.input} value={currency} onChangeText={setCurrency} placeholder="৳" placeholderTextColor={COLORS.muted} />

        <Text style={styles.label}>VAT Percentage (%)</Text>
        <TextInput style={styles.input} value={vatPercent} onChangeText={setVatPercent} keyboardType="numeric" placeholder="0" placeholderTextColor={COLORS.muted} />

        <Text style={styles.label}>Receipt Footer Text</Text>
        <TextInput
          style={[styles.input, styles.textArea]}
          value={receiptFooter}
          onChangeText={setReceiptFooter}
          placeholder="Thank you for your purchase!"
          placeholderTextColor={COLORS.muted}
          multiline
          numberOfLines={3}
        />
      </View>

      <Button title="Save Settings" onPress={handleSave} loading={saving} style={styles.saveBtn} />

      <View style={styles.card}>
        <Text style={styles.sectionTitle}>Account</Text>
        <Text style={styles.accountInfo}>Logged in as: {user?.name || 'User'}</Text>
        <Text style={styles.accountEmail}>{user?.email || ''}</Text>
      </View>

      <Button title="Logout" variant="danger" onPress={handleLogout} style={styles.logoutBtn} />
      <View style={{ height: 40 }} />
    </ScrollView>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: COLORS.background,
  },
  content: {
    padding: 16,
  },
  card: {
    backgroundColor: COLORS.card,
    borderRadius: 10,
    padding: 16,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  sectionTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: COLORS.text,
    marginBottom: 16,
  },
  label: {
    fontSize: 13,
    fontWeight: '600',
    color: COLORS.text,
    marginBottom: 6,
    marginTop: 12,
  },
  input: {
    borderWidth: 1,
    borderColor: COLORS.border,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 12,
    fontSize: 15,
    color: COLORS.text,
    backgroundColor: COLORS.background,
  },
  textArea: {
    height: 80,
    textAlignVertical: 'top',
  },
  saveBtn: {
    marginBottom: 12,
  },
  accountInfo: {
    fontSize: 15,
    fontWeight: '600',
    color: COLORS.text,
  },
  accountEmail: {
    fontSize: 13,
    color: COLORS.muted,
    marginTop: 4,
  },
  logoutBtn: {
    marginTop: 12,
  },
});

export default SettingsScreen;
