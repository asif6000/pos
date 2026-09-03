import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { COLORS } from '../utils/helpers';

const EmptyState = ({ icon = 'folder-open-outline', message = 'No data found', subMessage }) => {
  return (
    <View style={styles.container}>
      <Ionicons name={icon} size={64} color={COLORS.border} />
      <Text style={styles.message}>{message}</Text>
      {subMessage && <Text style={styles.subMessage}>{subMessage}</Text>}
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    paddingVertical: 60,
    paddingHorizontal: 32,
  },
  message: {
    fontSize: 16,
    fontWeight: '600',
    color: COLORS.muted,
    marginTop: 16,
    textAlign: 'center',
  },
  subMessage: {
    fontSize: 13,
    color: COLORS.border,
    marginTop: 8,
    textAlign: 'center',
  },
});

export default EmptyState;
