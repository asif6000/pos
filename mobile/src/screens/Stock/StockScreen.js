import React, { useState, useCallback } from 'react';
import { View, Text, StyleSheet, FlatList, Alert, TouchableOpacity, Modal, TextInput } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useFocusEffect } from '@react-navigation/native';
import { supabase } from '../../config/supabase';
import { COLORS } from '../../utils/helpers';
import SearchBar from '../../components/SearchBar';
import LoadingSpinner from '../../components/LoadingSpinner';
import EmptyState from '../../components/EmptyState';
import Button from '../../components/Button';

const StockScreen = () => {
  const [products, setProducts] = useState([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [adjustModal, setAdjustModal] = useState(false);
  const [adjustProduct, setAdjustProduct] = useState(null);
  const [adjustQty, setAdjustQty] = useState('');
  const [adjustType, setAdjustType] = useState('add');

  const fetchProducts = async () => {
    try {
      const { data: { user } } = await supabase.auth.getUser();
      if (!user) return;
      const { data } = await supabase.from('products').select('*').eq('owner_id', user.id);
      setProducts(data || []);
    } catch (error) {
      console.error('Fetch stock error:', error);
    } finally {
      setLoading(false);
    }
  };

  useFocusEffect(useCallback(() => { fetchProducts(); }, []));

  const filteredProducts = products.filter((p) =>
    !search || p.name.toLowerCase().includes(search.toLowerCase())
  );

  const openAdjust = (product) => {
    setAdjustProduct(product);
    setAdjustQty('');
    setAdjustType('add');
    setAdjustModal(true);
  };

  const handleAdjust = async () => {
    const qty = parseInt(adjustQty);
    if (!qty || qty <= 0) {
      Alert.alert('Error', 'Enter a valid quantity');
      return;
    }
    try {
      const { data: { user } } = await supabase.auth.getUser();
      if (!user) throw new Error('Not authenticated');

      const newStock = adjustType === 'add' ? adjustProduct.stock + qty : adjustProduct.stock - qty;
      if (newStock < 0) {
        Alert.alert('Error', 'Stock cannot be negative');
        return;
      }
      const { error } = await supabase.from('products').update({ stock: newStock }).eq('id', adjustProduct.id);
      if (error) throw error;

      await supabase.from('stock_history').insert({
        product_id: adjustProduct.id,
        owner_id: user.id,
        type: adjustType,
        quantity: qty,
        previous_stock: adjustProduct.stock,
        new_stock: newStock,
        reason: adjustType === 'add' ? 'Manual addition' : 'Manual removal',
      });

      setProducts(products.map((p) =>
        p.id === adjustProduct.id ? { ...p, stock: newStock } : p
      ));
      setAdjustModal(false);
      Alert.alert('Success', 'Stock adjusted');
    } catch (error) {
      Alert.alert('Error', error.message || 'Failed to adjust stock');
    }
  };

  const renderItem = ({ item }) => {
    const isLow = item.stock <= (item.min_stock || 0);
    return (
      <View style={[styles.item, isLow && styles.lowStockItem]}>
        <View style={styles.itemInfo}>
          <Text style={styles.itemName}>{item.name}</Text>
          <Text style={styles.itemMin}>Min: {item.min_stock || 0}</Text>
        </View>
        <View style={styles.itemRight}>
          <Text style={[styles.stockValue, isLow && { color: COLORS.danger }]}>{item.stock}</Text>
          <Text style={[styles.stockLabel, isLow && { color: COLORS.danger }]}>In Stock</Text>
        </View>
        <TouchableOpacity style={styles.adjustBtn} onPress={() => openAdjust(item)}>
          <Ionicons name="create-outline" size={20} color={COLORS.primary} />
        </TouchableOpacity>
      </View>
    );
  };

  if (loading) return <LoadingSpinner />;

  return (
    <View style={styles.container}>
      <SearchBar value={search} onChangeText={setSearch} placeholder="Search stock..." />
      <FlatList
        data={filteredProducts}
        renderItem={renderItem}
        keyExtractor={(item) => item.id}
        contentContainerStyle={styles.list}
        ListEmptyComponent={<EmptyState icon="cube-outline" message="No products found" />}
        onRefresh={fetchProducts}
        refreshing={false}
      />

      <Modal visible={adjustModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            <Text style={styles.modalTitle}>Adjust Stock</Text>
            <Text style={styles.modalProduct}>{adjustProduct?.name}</Text>
            <Text style={styles.modalCurrent}>Current Stock: {adjustProduct?.stock}</Text>

            <View style={styles.typeRow}>
              <TouchableOpacity
                style={[styles.typeBtn, adjustType === 'add' && styles.typeBtnActive]}
                onPress={() => setAdjustType('add')}
              >
                <Ionicons name="add-circle" size={20} color={adjustType === 'add' ? '#fff' : COLORS.success} />
                <Text style={[styles.typeBtnText, adjustType === 'add' && { color: '#fff' }]}>Add</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.typeBtn, adjustType === 'remove' && { backgroundColor: COLORS.danger }]}
                onPress={() => setAdjustType('remove')}
              >
                <Ionicons name="remove-circle" size={20} color={adjustType === 'remove' ? '#fff' : COLORS.danger} />
                <Text style={[styles.typeBtnText, adjustType === 'remove' && { color: '#fff' }]}>Remove</Text>
              </TouchableOpacity>
            </View>

            <TextInput
              style={styles.modalInput}
              value={adjustQty}
              onChangeText={setAdjustQty}
              keyboardType="numeric"
              placeholder="Quantity"
              placeholderTextColor={COLORS.muted}
            />

            <View style={styles.modalActions}>
              <Button title="Cancel" variant="outline" onPress={() => setAdjustModal(false)} style={styles.modalBtn} />
              <Button title="Adjust" onPress={handleAdjust} style={styles.modalBtn} />
            </View>
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
  },
  item: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: COLORS.card,
    marginHorizontal: 16,
    marginVertical: 4,
    padding: 14,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  lowStockItem: {
    borderColor: COLORS.danger,
    backgroundColor: COLORS.danger + '05',
  },
  itemInfo: {
    flex: 1,
  },
  itemName: {
    fontSize: 14,
    fontWeight: '600',
    color: COLORS.text,
  },
  itemMin: {
    fontSize: 12,
    color: COLORS.muted,
    marginTop: 2,
  },
  itemRight: {
    alignItems: 'center',
    marginRight: 12,
  },
  stockValue: {
    fontSize: 18,
    fontWeight: '700',
    color: COLORS.text,
  },
  stockLabel: {
    fontSize: 10,
    color: COLORS.muted,
  },
  adjustBtn: {
    padding: 8,
  },
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.5)',
    justifyContent: 'center',
    padding: 24,
  },
  modalContent: {
    backgroundColor: COLORS.card,
    borderRadius: 12,
    padding: 24,
  },
  modalTitle: {
    fontSize: 18,
    fontWeight: '700',
    color: COLORS.text,
    marginBottom: 4,
  },
  modalProduct: {
    fontSize: 15,
    color: COLORS.muted,
    marginBottom: 4,
  },
  modalCurrent: {
    fontSize: 14,
    color: COLORS.text,
    marginBottom: 16,
  },
  typeRow: {
    flexDirection: 'row',
    gap: 12,
    marginBottom: 16,
  },
  typeBtn: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    paddingVertical: 12,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.background,
  },
  typeBtnActive: {
    backgroundColor: COLORS.success,
    borderColor: COLORS.success,
  },
  typeBtnText: {
    fontSize: 14,
    fontWeight: '600',
    color: COLORS.text,
  },
  modalInput: {
    borderWidth: 1,
    borderColor: COLORS.border,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 12,
    fontSize: 16,
    color: COLORS.text,
    backgroundColor: COLORS.background,
    textAlign: 'center',
    marginBottom: 16,
  },
  modalActions: {
    flexDirection: 'row',
    gap: 12,
  },
  modalBtn: {
    flex: 1,
  },
});

export default StockScreen;
