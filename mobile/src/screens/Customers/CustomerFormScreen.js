import React, { useState } from 'react';
import { View, Text, TextInput, StyleSheet, ScrollView, Alert, KeyboardAvoidingView, Platform } from 'react-native';
import { supabase } from '../../config/supabase';
import { COLORS } from '../../utils/helpers';
import Header from '../../components/Header';
import Button from '../../components/Button';

const CustomerFormScreen = ({ navigation, route }) => {
  const customer = route.params?.customer;
  const isEdit = !!customer;

  const [name, setName] = useState(customer?.name || '');
  const [phone, setPhone] = useState(customer?.phone || '');
  const [email, setEmail] = useState(customer?.email || '');
  const [address, setAddress] = useState(customer?.address || '');
  const [loading, setLoading] = useState(false);

  const handleSave = async () => {
    if (!name.trim()) {
      Alert.alert('Error', 'Customer name is required');
      return;
    }
    if (!phone.trim()) {
      Alert.alert('Error', 'Phone number is required');
      return;
    }
    setLoading(true);
    try {
      const { data: { user } } = await supabase.auth.getUser();
      if (!user) throw new Error('Not authenticated');

      const data = { name: name.trim(), phone: phone.trim(), email: email.trim(), address: address.trim() };
      if (isEdit) {
        const { error } = await supabase.from('customers').update(data).eq('id', customer.id);
        if (error) throw error;
        Alert.alert('Success', 'Customer updated', [{ text: 'OK', onPress: () => navigation.goBack() }]);
      } else {
        data.owner_id = user.id;
        const { error } = await supabase.from('customers').insert(data);
        if (error) throw error;
        Alert.alert('Success', 'Customer created', [{ text: 'OK', onPress: () => navigation.goBack() }]);
      }
    } catch (error) {
      Alert.alert('Error', error.message || 'Failed to save customer');
    } finally {
      setLoading(false);
    }
  };

  return (
    <KeyboardAvoidingView style={styles.container} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Header title={isEdit ? 'Edit Customer' : 'New Customer'} onBack={() => navigation.goBack()} />
      <ScrollView contentContainerStyle={styles.form} keyboardShouldPersistTaps="handled">
        <Text style={styles.label}>Name *</Text>
        <TextInput style={styles.input} value={name} onChangeText={setName} placeholder="Customer name" placeholderTextColor={COLORS.muted} />

        <Text style={styles.label}>Phone *</Text>
        <TextInput style={styles.input} value={phone} onChangeText={setPhone} placeholder="Phone number" placeholderTextColor={COLORS.muted} keyboardType="phone-pad" />

        <Text style={styles.label}>Email</Text>
        <TextInput style={styles.input} value={email} onChangeText={setEmail} placeholder="Email address" placeholderTextColor={COLORS.muted} keyboardType="email-address" autoCapitalize="none" />

        <Text style={styles.label}>Address</Text>
        <TextInput
          style={[styles.input, styles.textArea]}
          value={address}
          onChangeText={setAddress}
          placeholder="Address"
          placeholderTextColor={COLORS.muted}
          multiline
          numberOfLines={3}
        />

        <Button title={isEdit ? 'Update Customer' : 'Create Customer'} onPress={handleSave} loading={loading} style={styles.saveBtn} />
      </ScrollView>
    </KeyboardAvoidingView>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: COLORS.background,
  },
  form: {
    padding: 16,
    paddingBottom: 40,
  },
  label: {
    fontSize: 13,
    fontWeight: '600',
    color: COLORS.text,
    marginBottom: 6,
    marginTop: 16,
  },
  input: {
    borderWidth: 1,
    borderColor: COLORS.border,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 12,
    fontSize: 15,
    color: COLORS.text,
    backgroundColor: COLORS.card,
  },
  textArea: {
    height: 80,
    textAlignVertical: 'top',
  },
  saveBtn: {
    marginTop: 24,
  },
});

export default CustomerFormScreen;
