import React, { useState, useCallback } from 'react';
import {
  View, Text, StyleSheet, FlatList, TouchableOpacity, Alert, Modal, ScrollView, TextInput,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useFocusEffect } from '@react-navigation/native';
import { supabase } from '../../config/supabase';
import { formatCurrency, formatDate, COLORS } from '../../utils/helpers';
import LoadingSpinner from '../../components/LoadingSpinner';
import EmptyState from '../../components/EmptyState';
import Button from '../../components/Button';

const REFUND_METHODS = ['Cash', 'bKash', 'Nagad', 'Rocket', 'Card', 'Bank'];

const ReturnsScreen = () => {
  const [returns, setReturns] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showProcessModal, setShowProcessModal] = useState(false);
  const [sales, setSales] = useState([]);
  const [selectedSale, setSelectedSale] = useState(null);
  const [selectedItems, setSelectedItems] = useState([]);
  const [refundMethod, setRefundMethod] = useState('Cash');
  const [reason, setReason] = useState('');
  const [searchSale, setSearchSale] = useState('');
  const [processing, setProcessing] = useState(false);

  const fetchReturns = async () => {
    try {
      const { data: { user } } = await supabase.auth.getUser();
      if (!user) return;
      const { data } = await supabase.from('returns').select('*, sales(invoice_number)').eq('owner_id', user.id).order('created_at', { ascending: false });
      setReturns(data || []);
    } catch (error) {
      console.error('Fetch returns error:', error);
    } finally {
      setLoading(false);
    }
  };

  useFocusEffect(useCallback(() => { fetchReturns(); }, []));

  const openProcessModal = async () => {
    try {
      const { data: { user } } = await supabase.auth.getUser();
      if (!user) return;
      const { data } = await supabase
        .from('sales')
        .select('*, customers(name), sale_items(*, products(name))')
        .eq('owner_id', user.id)
        .order('created_at', { ascending: false });
      setSales(data || []);
    } catch (error) {
      console.error('Fetch sales error:', error);
    }
    setSelectedSale(null);
    setSelectedItems([]);
    setRefundMethod('Cash');
    setReason('');
    setShowProcessModal(true);
  };

  const selectSale = (sale) => {
    setSelectedSale(sale);
    setSelectedItems([]);
  };

  const toggleItem = (item) => {
    const exists = selectedItems.find((i) => i.product_id === item.product_id);
    if (exists) {
      setSelectedItems(selectedItems.filter((i) => i.product_id !== item.product_id));
    } else {
      setSelectedItems([...selectedItems, { ...item, returnQty: 1 }]);
    }
  };

  const updateReturnQty = (productId, qty) => {
    if (qty < 1) return;
    setSelectedItems(selectedItems.map((i) =>
      i.product_id === productId ? { ...i, returnQty: Math.min(qty, i.qty) } : i
    ));
  };

  const processReturn = async () => {
    if (!selectedSale) {
      Alert.alert('Error', 'Select a sale');
      return;
    }
    if (selectedItems.length === 0) {
      Alert.alert('Error', 'Select at least one item to return');
      return;
    }
    setProcessing(true);
    try {
      const { data: { user } } = await supabase.auth.getUser();
      if (!user) throw new Error('Not authenticated');

      const returnData = {
        sale_id: selectedSale.id,
        owner_id: user.id,
        items: selectedItems.map((i) => ({
          product_id: i.product_id,
          qty: i.returnQty,
          price: i.price,
        })),
        refund_method: refundMethod,
        reason: reason.trim(),
        total_refund: selectedItems.reduce((sum, i) => sum + i.returnQty * i.price, 0),
      };

      const { error } = await supabase.rpc('process_return', { p_return_data: returnData });
      if (error) throw error;
      Alert.alert('Success', 'Return processed', [{ text: 'OK', onPress: () => { setShowProcessModal(false); fetchReturns(); } }]);
    } catch (error) {
      Alert.alert('Error', error.message || 'Failed to process return');
    } finally {
      setProcessing(false);
    }
  };

  const filteredSales = sales.filter((s) =>
    !searchSale || s.invoice_number?.toLowerCase().includes(searchSale.toLowerCase()) || s.customers?.name?.toLowerCase().includes(searchSale.toLowerCase())
  );

  const renderItem = ({ item }) => (
    <View style={styles.item}>
      <View style={styles.itemHeader}>
        <Ionicons name="return-down-back" size={20} color={COLORS.danger} />
        <View style={styles.itemInfo}>
          <Text style={styles.itemTitle}>Return #{item.return_number || item.id?.slice(-6) || 'N/A'}</Text>
          <Text style={styles.itemSub}>Sale: {item.sales?.invoice_number || 'N/A'}</Text>
        </View>
        <View style={styles.itemRight}>
          <Text style={styles.itemAmount}>-{formatCurrency(item.total_refund || item.total)}</Text>
          <Text style={styles.itemDate}>{formatDate(item.created_at)}</Text>
        </View>
      </View>
      {item.reason && <Text style={styles.itemReason}>Reason: {item.reason}</Text>}
    </View>
  );

  if (loading) return <LoadingSpinner />;

  return (
    <View style={styles.container}>
      <FlatList
        data={returns}
        renderItem={renderItem}
        keyExtractor={(item) => item.id}
        contentContainerStyle={styles.list}
        ListEmptyComponent={<EmptyState icon="return-down-back" message="No returns found" />}
        onRefresh={fetchReturns}
        refreshing={false}
      />

      <TouchableOpacity style={styles.fab} onPress={openProcessModal}>
        <Ionicons name="add" size={28} color="#ffffff" />
      </TouchableOpacity>

      <Modal visible={showProcessModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>Process Return</Text>
              <TouchableOpacity onPress={() => setShowProcessModal(false)}>
                <Ionicons name="close" size={24} color={COLORS.text} />
              </TouchableOpacity>
            </View>

            <ScrollView contentContainerStyle={styles.modalBody}>
              {!selectedSale ? (
                <>
                  <TextInput
                    style={styles.searchInput}
                    value={searchSale}
                    onChangeText={setSearchSale}
                    placeholder="Search sale by invoice or customer..."
                    placeholderTextColor={COLORS.muted}
                  />
                  {filteredSales.slice(0, 10).map((sale) => (
                    <TouchableOpacity key={sale.id} style={styles.saleOption} onPress={() => selectSale(sale)}>
                      <View>
                        <Text style={styles.saleOptionTitle}>{sale.invoice_number || 'N/A'}</Text>
                        <Text style={styles.saleOptionSub}>{sale.customers?.name || 'Walk-in'} - {formatDate(sale.created_at || sale.date)}</Text>
                      </View>
                      <Text style={styles.saleOptionTotal}>{formatCurrency(sale.total)}</Text>
                    </TouchableOpacity>
                  ))}
                </>
              ) : (
                <>
                  <View style={styles.selectedSale}>
                    <Text style={styles.selectedSaleTitle}>{selectedSale.invoice_number}</Text>
                    <TouchableOpacity onPress={() => { setSelectedSale(null); setSelectedItems([]); }}>
                      <Text style={styles.changeBtn}>Change</Text>
                    </TouchableOpacity>
                  </View>

                  <Text style={styles.itemsTitle}>Select items to return:</Text>
                  {(selectedSale.sale_items || []).map((item) => {
                    const isSelected = selectedItems.find((i) => i.product_id === item.product_id);
                    return (
                      <View key={item.product_id} style={styles.itemOption}>
                        <TouchableOpacity style={styles.itemCheck} onPress={() => toggleItem(item)}>
                          <Ionicons name={isSelected ? 'checkbox' : 'square-outline'} size={22} color={isSelected ? COLORS.primary : COLORS.muted} />
                        </TouchableOpacity>
                        <View style={styles.itemOptionInfo}>
                          <Text style={styles.itemOptionName}>{item.products?.name || item.name}</Text>
                          <Text style={styles.itemOptionDetail}>Qty: {item.qty} at {formatCurrency(item.price)}</Text>
                        </View>
                        {isSelected && (
                          <View style={styles.qtyControl}>
                            <TouchableOpacity onPress={() => updateReturnQty(item.product_id, isSelected.returnQty - 1)}>
                              <Ionicons name="remove-circle" size={24} color={COLORS.danger} />
                            </TouchableOpacity>
                            <Text style={styles.qtyText}>{isSelected.returnQty}</Text>
                            <TouchableOpacity onPress={() => updateReturnQty(item.product_id, isSelected.returnQty + 1)}>
                              <Ionicons name="add-circle" size={24} color={COLORS.success} />
                            </TouchableOpacity>
                          </View>
                        )}
                      </View>
                    );
                  })}

                  <Text style={styles.itemsTitle}>Refund Method</Text>
                  <View style={styles.methodRow}>
                    {REFUND_METHODS.map((m) => (
                      <TouchableOpacity
                        key={m}
                        style={[styles.methodChip, refundMethod === m && styles.methodActive]}
                        onPress={() => setRefundMethod(m)}
                      >
                        <Text style={[styles.methodText, refundMethod === m && { color: '#fff' }]}>{m}</Text>
                      </TouchableOpacity>
                    ))}
                  </View>

                  <TextInput
                    style={styles.reasonInput}
                    value={reason}
                    onChangeText={setReason}
                    placeholder="Reason for return (optional)"
                    placeholderTextColor={COLORS.muted}
                    multiline
                  />

                  <Button
                    title="Process Return"
                    variant="danger"
                    onPress={processReturn}
                    loading={processing}
                    style={styles.processBtn}
                  />
                </>
              )}
            </ScrollView>
          </View>
        </View>
      </Modal>
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: COLORS.background,
  },
  list: {
    paddingVertical: 8,
    paddingBottom: 80,
  },
  item: {
    backgroundColor: COLORS.card,
    marginHorizontal: 16,
    marginVertical: 4,
    padding: 14,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  itemHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
  },
  itemInfo: {
    flex: 1,
  },
  itemTitle: {
    fontSize: 14,
    fontWeight: '700',
    color: COLORS.text,
  },
  itemSub: {
    fontSize: 12,
    color: COLORS.muted,
    marginTop: 2,
  },
  itemRight: {
    alignItems: 'flex-end',
  },
  itemAmount: {
    fontSize: 14,
    fontWeight: '700',
    color: COLORS.danger,
  },
  itemDate: {
    fontSize: 11,
    color: COLORS.muted,
    marginTop: 2,
  },
  itemReason: {
    fontSize: 12,
    color: COLORS.muted,
    marginTop: 8,
    fontStyle: 'italic',
  },
  fab: {
    position: 'absolute',
    right: 20,
    bottom: 20,
    width: 56,
    height: 56,
    borderRadius: 28,
    backgroundColor: COLORS.primary,
    justifyContent: 'center',
    alignItems: 'center',
    elevation: 4,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.25,
    shadowRadius: 4,
  },
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.5)',
    justifyContent: 'flex-end',
  },
  modalContent: {
    backgroundColor: COLORS.card,
    borderTopLeftRadius: 16,
    borderTopRightRadius: 16,
    maxHeight: '80%',
  },
  modalHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    padding: 16,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  modalTitle: {
    fontSize: 18,
    fontWeight: '700',
    color: COLORS.text,
  },
  modalBody: {
    padding: 16,
    paddingBottom: 30,
  },
  searchInput: {
    borderWidth: 1,
    borderColor: COLORS.border,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 14,
    color: COLORS.text,
    backgroundColor: COLORS.background,
    marginBottom: 12,
  },
  saleOption: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  saleOptionTitle: {
    fontSize: 14,
    fontWeight: '600',
    color: COLORS.text,
  },
  saleOptionSub: {
    fontSize: 12,
    color: COLORS.muted,
    marginTop: 2,
  },
  saleOptionTotal: {
    fontSize: 14,
    fontWeight: '600',
    color: COLORS.primary,
  },
  selectedSale: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 10,
    marginBottom: 12,
    backgroundColor: COLORS.primary + '10',
    borderRadius: 8,
    paddingHorizontal: 12,
  },
  selectedSaleTitle: {
    fontSize: 14,
    fontWeight: '700',
    color: COLORS.primary,
  },
  changeBtn: {
    fontSize: 13,
    color: COLORS.danger,
    fontWeight: '500',
  },
  itemsTitle: {
    fontSize: 14,
    fontWeight: '600',
    color: COLORS.text,
    marginBottom: 10,
    marginTop: 8,
  },
  itemOption: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
    gap: 10,
  },
  itemCheck: {
    padding: 2,
  },
  itemOptionInfo: {
    flex: 1,
  },
  itemOptionName: {
    fontSize: 14,
    fontWeight: '500',
    color: COLORS.text,
  },
  itemOptionDetail: {
    fontSize: 12,
    color: COLORS.muted,
    marginTop: 2,
  },
  qtyControl: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  qtyText: {
    fontSize: 14,
    fontWeight: '600',
    color: COLORS.text,
    minWidth: 20,
    textAlign: 'center',
  },
  methodRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    marginBottom: 16,
  },
  methodChip: {
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: 8,
    backgroundColor: COLORS.background,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  methodActive: {
    backgroundColor: COLORS.primary,
    borderColor: COLORS.primary,
  },
  methodText: {
    fontSize: 13,
    color: COLORS.text,
  },
  reasonInput: {
    borderWidth: 1,
    borderColor: COLORS.border,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 14,
    color: COLORS.text,
    backgroundColor: COLORS.background,
    marginBottom: 16,
    height: 60,
    textAlignVertical: 'top',
  },
  processBtn: {
    marginTop: 4,
  },
});

export default ReturnsScreen;
