import React, { useState, useEffect } from 'react';
import {
  View, Text, TextInput, StyleSheet, ScrollView, Alert, TouchableOpacity, Modal, FlatList, KeyboardAvoidingView, Platform,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { supabase } from '../../config/supabase';
import { COLORS } from '../../utils/helpers';
import Header from '../../components/Header';
import Button from '../../components/Button';

const ProductFormScreen = ({ navigation, route }) => {
  const product = route.params?.product;
  const isEdit = !!product;

  const [name, setName] = useState(product?.name || '');
  const [barcode, setBarcode] = useState(product?.barcode || '');
  const [categoryId, setCategoryId] = useState(product?.category_id || '');
  const [buyPrice, setBuyPrice] = useState(String(product?.buy_price || ''));
  const [sellPrice, setSellPrice] = useState(String(product?.sell_price || ''));
  const [stock, setStock] = useState(String(product?.stock || ''));
  const [minStock, setMinStock] = useState(String(product?.min_stock || ''));
  const [unit, setUnit] = useState(product?.unit || 'piece');
  const [description, setDescription] = useState(product?.description || '');
  const [categories, setCategories] = useState([]);
  const [showCategoryPicker, setShowCategoryPicker] = useState(false);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    fetchCategories();
  }, []);

  const fetchCategories = async () => {
    try {
      const { data: { user } } = await supabase.auth.getUser();
      if (!user) return;
      const { data } = await supabase.from('categories').select('*').eq('owner_id', user.id);
      setCategories(data || []);
    } catch (error) {
      console.error('Fetch categories error:', error);
    }
  };

  const selectedCategoryName = categories.find((c) => c.id === categoryId)?.name || 'Select Category';

  const handleSave = async () => {
    if (!name.trim()) {
      Alert.alert('Error', 'Product name is required');
      return;
    }
    if (!sellPrice || parseFloat(sellPrice) <= 0) {
      Alert.alert('Error', 'Sell price must be greater than 0');
      return;
    }
    setLoading(true);
    try {
      const { data: { user } } = await supabase.auth.getUser();
      if (!user) throw new Error('Not authenticated');

      const data = {
        name: name.trim(),
        barcode: barcode.trim() || null,
        category_id: categoryId || null,
        buy_price: parseFloat(buyPrice) || 0,
        sell_price: parseFloat(sellPrice),
        stock: parseInt(stock) || 0,
        min_stock: parseInt(minStock) || 0,
        unit,
        description: description.trim(),
      };

      if (isEdit) {
        const { error } = await supabase.from('products').update(data).eq('id', product.id);
        if (error) throw error;
        Alert.alert('Success', 'Product updated', [{ text: 'OK', onPress: () => navigation.goBack() }]);
      } else {
        data.owner_id = user.id;
        data.status = 'active';
        const { error } = await supabase.from('products').insert(data);
        if (error) throw error;
        Alert.alert('Success', 'Product created', [{ text: 'OK', onPress: () => navigation.goBack() }]);
      }
    } catch (error) {
      Alert.alert('Error', error.message || 'Failed to save product');
    } finally {
      setLoading(false);
    }
  };

  return (
    <KeyboardAvoidingView style={styles.container} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Header title={isEdit ? 'Edit Product' : 'New Product'} onBack={() => navigation.goBack()} />
      <ScrollView contentContainerStyle={styles.form} keyboardShouldPersistTaps="handled">
        <Text style={styles.label}>Product Name *</Text>
        <TextInput style={styles.input} value={name} onChangeText={setName} placeholder="Enter product name" placeholderTextColor={COLORS.muted} />

        <Text style={styles.label}>Barcode</Text>
        <TextInput style={styles.input} value={barcode} onChangeText={setBarcode} placeholder="Enter barcode" placeholderTextColor={COLORS.muted} />

        <Text style={styles.label}>Category</Text>
        <TouchableOpacity style={styles.picker} onPress={() => setShowCategoryPicker(true)}>
          <Text style={categoryId ? styles.pickerText : styles.pickerPlaceholder}>{selectedCategoryName}</Text>
          <Ionicons name="chevron-down" size={20} color={COLORS.muted} />
        </TouchableOpacity>

        <View style={styles.row}>
          <View style={styles.halfInput}>
            <Text style={styles.label}>Buy Price</Text>
            <TextInput style={styles.input} value={buyPrice} onChangeText={setBuyPrice} keyboardType="numeric" placeholder="0.00" placeholderTextColor={COLORS.muted} />
          </View>
          <View style={styles.halfInput}>
            <Text style={styles.label}>Sell Price *</Text>
            <TextInput style={styles.input} value={sellPrice} onChangeText={setSellPrice} keyboardType="numeric" placeholder="0.00" placeholderTextColor={COLORS.muted} />
          </View>
        </View>

        <View style={styles.row}>
          <View style={styles.halfInput}>
            <Text style={styles.label}>Stock</Text>
            <TextInput style={styles.input} value={stock} onChangeText={setStock} keyboardType="numeric" placeholder="0" placeholderTextColor={COLORS.muted} />
          </View>
          <View style={styles.halfInput}>
            <Text style={styles.label}>Min Stock</Text>
            <TextInput style={styles.input} value={minStock} onChangeText={setMinStock} keyboardType="numeric" placeholder="0" placeholderTextColor={COLORS.muted} />
          </View>
        </View>

        <Text style={styles.label}>Unit</Text>
        <TextInput style={styles.input} value={unit} onChangeText={setUnit} placeholder="piece, kg, litre..." placeholderTextColor={COLORS.muted} />

        <Text style={styles.label}>Description</Text>
        <TextInput
          style={[styles.input, styles.textArea]}
          value={description}
          onChangeText={setDescription}
          placeholder="Product description"
          placeholderTextColor={COLORS.muted}
          multiline
          numberOfLines={3}
        />

        <Button title={isEdit ? 'Update Product' : 'Create Product'} onPress={handleSave} loading={loading} style={styles.saveBtn} />
      </ScrollView>

      <Modal visible={showCategoryPicker} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>Select Category</Text>
              <TouchableOpacity onPress={() => setShowCategoryPicker(false)}>
                <Ionicons name="close" size={24} color={COLORS.text} />
              </TouchableOpacity>
            </View>
            <TouchableOpacity
              style={styles.categoryOption}
              onPress={() => { setCategoryId(''); setShowCategoryPicker(false); }}
            >
              <Text style={styles.categoryOptionText}>No Category</Text>
              {!categoryId && <Ionicons name="checkmark" size={20} color={COLORS.primary} />}
            </TouchableOpacity>
            <FlatList
              data={categories}
              keyExtractor={(item) => item.id}
              renderItem={({ item }) => (
                <TouchableOpacity
                  style={styles.categoryOption}
                  onPress={() => { setCategoryId(item.id); setShowCategoryPicker(false); }}
                >
                  <Text style={styles.categoryOptionText}>{item.name}</Text>
                  {categoryId === item.id && <Ionicons name="checkmark" size={20} color={COLORS.primary} />}
                </TouchableOpacity>
              )}
            />
          </View>
        </View>
      </Modal>
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
    backgroundColor: COLORS.card,
  },
  textArea: {
    height: 80,
    textAlignVertical: 'top',
  },
  row: {
    flexDirection: 'row',
    gap: 12,
  },
  halfInput: {
    flex: 1,
  },
  picker: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    borderWidth: 1,
    borderColor: COLORS.border,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 12,
    backgroundColor: COLORS.card,
  },
  pickerText: {
    fontSize: 15,
    color: COLORS.text,
  },
  pickerPlaceholder: {
    fontSize: 15,
    color: COLORS.muted,
  },
  saveBtn: {
    marginTop: 24,
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
    maxHeight: '60%',
    paddingBottom: 20,
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
    fontSize: 16,
    fontWeight: '700',
    color: COLORS.text,
  },
  categoryOption: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 14,
    paddingHorizontal: 16,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  categoryOptionText: {
    fontSize: 15,
    color: COLORS.text,
  },
});

export default ProductFormScreen;
