import React, { useState, useCallback } from 'react';
import { View, Text, StyleSheet, FlatList, TouchableOpacity, Alert } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useFocusEffect } from '@react-navigation/native';
import { supabase } from '../../config/supabase';
import { formatCurrency, formatDate, COLORS } from '../../utils/helpers';
import LoadingSpinner from '../../components/LoadingSpinner';
import EmptyState from '../../components/EmptyState';

const PAYMENT_FILTERS = ['All', 'Cash', 'bKash', 'Nagad', 'Rocket', 'Card', 'Bank'];

const SalesScreen = ({ navigation }) => {
  const [sales, setSales] = useState([]);
  const [loading, setLoading] = useState(true);
  const [paymentFilter, setPaymentFilter] = useState('All');

  const fetchSales = async () => {
    try {
      const { data: { user } } = await supabase.auth.getUser();
      if (!user) return;

      let query = supabase
        .from('sales')
        .select('*, customers(name), sale_items(*, products(name))')
        .eq('owner_id', user.id)
        .order('created_at', { ascending: false });

      if (paymentFilter !== 'All') {
        query = query.eq('payment_method', paymentFilter);
      }

      const { data, error } = await query;
      if (error) throw error;
      setSales(data || []);
    } catch (error) {
      console.error('Fetch sales error:', error);
    } finally {
      setLoading(false);
    }
  };

  useFocusEffect(useCallback(() => { fetchSales(); }, [paymentFilter]));

  const renderItem = ({ item }) => (
    <TouchableOpacity
      style={styles.item}
      onPress={() => navigation.navigate('SaleDetail', { sale: item })}
    >
      <View style={styles.itemLeft}>
        <Text style={styles.invoice}>{item.invoice_number || 'N/A'}</Text>
        <Text style={styles.customer}>{item.customers?.name || 'Walk-in'}</Text>
        <Text style={styles.date}>{formatDate(item.created_at || item.date)}</Text>
      </View>
      <View style={styles.itemRight}>
        <Text style={styles.amount}>{formatCurrency(item.total)}</Text>
        <View style={[styles.badge, { backgroundColor: COLORS.primary + '15' }]}>
          <Text style={styles.badgeText}>{item.payment_method}</Text>
        </View>
      </View>
    </TouchableOpacity>
  );

  if (loading) return <LoadingSpinner />;

  return (
    <View style={styles.container}>
      <View style={styles.filterRow}>
        {PAYMENT_FILTERS.map((method) => (
          <TouchableOpacity
            key={method}
            style={[styles.filterChip, paymentFilter === method && styles.filterChipActive]}
            onPress={() => setPaymentFilter(method)}
          >
            <Text style={[styles.filterText, paymentFilter === method && styles.filterTextActive]}>{method}</Text>
          </TouchableOpacity>
        ))}
      </View>

      <FlatList
        data={sales}
        renderItem={renderItem}
        keyExtractor={(item) => item.id}
        contentContainerStyle={styles.list}
        ListEmptyComponent={<EmptyState icon="receipt-outline" message="No sales found" />}
        onRefresh={fetchSales}
        refreshing={false}
      />
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: COLORS.background,
  },
  filterRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    paddingHorizontal: 12,
    paddingVertical: 8,
    backgroundColor: COLORS.card,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
    gap: 6,
  },
  filterChip: {
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 16,
    backgroundColor: COLORS.background,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  filterChipActive: {
    backgroundColor: COLORS.primary,
    borderColor: COLORS.primary,
  },
  filterText: {
    fontSize: 12,
    color: COLORS.text,
  },
  filterTextActive: {
    color: '#ffffff',
  },
  list: {
    paddingVertical: 8,
  },
  item: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    backgroundColor: COLORS.card,
    marginHorizontal: 16,
    marginVertical: 4,
    padding: 14,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  itemLeft: {
    flex: 1,
  },
  invoice: {
    fontSize: 15,
    fontWeight: '700',
    color: COLORS.text,
  },
  customer: {
    fontSize: 13,
    color: COLORS.muted,
    marginTop: 4,
  },
  date: {
    fontSize: 12,
    color: COLORS.muted,
    marginTop: 2,
  },
  itemRight: {
    alignItems: 'flex-end',
    justifyContent: 'center',
  },
  amount: {
    fontSize: 16,
    fontWeight: '700',
    color: COLORS.success,
    marginBottom: 4,
  },
  badge: {
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 10,
  },
  badgeText: {
    fontSize: 11,
    fontWeight: '500',
    color: COLORS.primary,
  },
});

export default SalesScreen;
