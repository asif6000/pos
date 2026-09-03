import React, { useState } from 'react';
import { View, Text, TextInput, StyleSheet, ScrollView, Alert, TouchableOpacity, Modal, FlatList, KeyboardAvoidingView, Platform } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { supabase } from '../../config/supabase';
import { COLORS } from '../../utils/helpers';
import Header from '../../components/Header';
import Button from '../../components/Button';

const ROLES = ['cashier', 'manager', 'admin'];
const STATUSES = ['active', 'inactive'];

const UserFormScreen = ({ navigation, route }) => {
  const user = route.params?.user;
  const isEdit = !!user;

  const [name, setName] = useState(user?.name || '');
  const [email, setEmail] = useState(user?.email || '');
  const [password, setPassword] = useState('');
  const [role, setRole] = useState(user?.role || 'cashier');
  const [status, setStatus] = useState(user?.status || 'active');
  const [showRolePicker, setShowRolePicker] = useState(false);
  const [showStatusPicker, setShowStatusPicker] = useState(false);
  const [loading, setLoading] = useState(false);

  const handleSave = async () => {
    if (!name.trim()) {
      Alert.alert('Error', 'Name is required');
      return;
    }
    if (!email.trim()) {
      Alert.alert('Error', 'Email is required');
      return;
    }
    if (!isEdit && !password.trim()) {
      Alert.alert('Error', 'Password is required');
      return;
    }
    setLoading(true);
    try {
      if (isEdit) {
        const profileData = { name: name.trim(), email: email.trim(), role, status };
        const { error: profileError } = await supabase.from('profiles').update(profileData).eq('id', user.id);
        if (profileError) throw profileError;
        if (password.trim()) {
          await supabase.auth.admin.updateUserById(user.id, { password: password });
        }
        Alert.alert('Success', 'User updated', [{ text: 'OK', onPress: () => navigation.goBack() }]);
      } else {
        const { data, error } = await supabase.auth.signUp({
          email: email.trim(),
          password,
          options: { data: { name: name.trim(), role } },
        });
        if (error) throw error;
        if (data.user) {
          const { error: profileError } = await supabase.from('profiles').insert({
            id: data.user.id,
            name: name.trim(),
            email: email.trim(),
            role,
            status,
          });
          if (profileError) throw profileError;
        }
        Alert.alert('Success', 'User created', [{ text: 'OK', onPress: () => navigation.goBack() }]);
      }
    } catch (error) {
      Alert.alert('Error', error.message || 'Failed to save user');
    } finally {
      setLoading(false);
    }
  };

  return (
    <KeyboardAvoidingView style={styles.container} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Header title={isEdit ? 'Edit User' : 'New User'} onBack={() => navigation.goBack()} />
      <ScrollView contentContainerStyle={styles.form} keyboardShouldPersistTaps="handled">
        <Text style={styles.label}>Full Name *</Text>
        <TextInput style={styles.input} value={name} onChangeText={setName} placeholder="Enter name" placeholderTextColor={COLORS.muted} />

        <Text style={styles.label}>Email *</Text>
        <TextInput style={styles.input} value={email} onChangeText={setEmail} placeholder="Enter email" placeholderTextColor={COLORS.muted} keyboardType="email-address" autoCapitalize="none" />

        <Text style={styles.label}>{isEdit ? 'New Password (leave blank to keep)' : 'Password *'}</Text>
        <TextInput style={styles.input} value={password} onChangeText={setPassword} placeholder={isEdit ? '••••••••' : 'Enter password'} placeholderTextColor={COLORS.muted} secureTextEntry />

        <Text style={styles.label}>Role</Text>
        <TouchableOpacity style={styles.picker} onPress={() => setShowRolePicker(true)}>
          <Text style={styles.pickerText}>{role.charAt(0).toUpperCase() + role.slice(1)}</Text>
          <Ionicons name="chevron-down" size={20} color={COLORS.muted} />
        </TouchableOpacity>

        <Text style={styles.label}>Status</Text>
        <TouchableOpacity style={styles.picker} onPress={() => setShowStatusPicker(true)}>
          <Text style={styles.pickerText}>{status.charAt(0).toUpperCase() + status.slice(1)}</Text>
          <Ionicons name="chevron-down" size={20} color={COLORS.muted} />
        </TouchableOpacity>

        <Button title={isEdit ? 'Update User' : 'Create User'} onPress={handleSave} loading={loading} style={styles.saveBtn} />
      </ScrollView>

      <Modal visible={showRolePicker} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>Select Role</Text>
              <TouchableOpacity onPress={() => setShowRolePicker(false)}>
                <Ionicons name="close" size={24} color={COLORS.text} />
              </TouchableOpacity>
            </View>
            {ROLES.map((r) => (
              <TouchableOpacity key={r} style={styles.option} onPress={() => { setRole(r); setShowRolePicker(false); }}>
                <Text style={styles.optionText}>{r.charAt(0).toUpperCase() + r.slice(1)}</Text>
                {role === r && <Ionicons name="checkmark" size={20} color={COLORS.primary} />}
              </TouchableOpacity>
            ))}
          </View>
        </View>
      </Modal>

      <Modal visible={showStatusPicker} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>Select Status</Text>
              <TouchableOpacity onPress={() => setShowStatusPicker(false)}>
                <Ionicons name="close" size={24} color={COLORS.text} />
              </TouchableOpacity>
            </View>
            {STATUSES.map((s) => (
              <TouchableOpacity key={s} style={styles.option} onPress={() => { setStatus(s); setShowStatusPicker(false); }}>
                <Text style={styles.optionText}>{s.charAt(0).toUpperCase() + s.slice(1)}</Text>
                {status === s && <Ionicons name="checkmark" size={20} color={COLORS.primary} />}
              </TouchableOpacity>
            ))}
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
  option: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 14,
    paddingHorizontal: 16,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  optionText: {
    fontSize: 15,
    color: COLORS.text,
  },
});

export default UserFormScreen;
