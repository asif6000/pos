import React from 'react';
import { View, Text, StyleSheet, ScrollView } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { formatCurrency, formatDateTime, COLORS } from '../../utils/helpers';
import Header from '../../components/Header';

const SaleDetailScreen = ({ navigation, route }) => {
  const sale = route.params?.sale;

  if (!sale) {
    return (
      <View style={styles.container}>
        <Header title="Sale Details" onBack={() => navigation.goBack()} />
        <View style={styles.error}>
          <Text style={styles.errorText}>Sale data not found</Text>
        </View>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <Header title="Sale Details" subtitle={sale.invoiceNumber || ''} onBack={() => navigation.goBack()} />
      <ScrollView contentContainerStyle={styles.content}>
        <View style={styles.card}>
          <View style={styles.invoiceHeader}>
            <Ionicons name="receipt" size={24} color={COLORS.primary} />
            <View style={styles.invoiceInfo}>
              <Text style={styles.invoiceNumber}>{sale.invoiceNumber || 'N/A'}</Text>
              <Text style={styles.invoiceDate}>{formatDateTime(sale.createdAt || sale.date)}</Text>
            </View>
          </View>
        </View>

        <View style={styles.card}>
          <Text style={styles.sectionTitle}>Customer</Text>
          <Text style={styles.customerName}>{sale.customer?.name || 'Walk-in Customer'}</Text>
          {sale.customer?.phone && <Text style={styles.customerDetail}>{sale.customer.phone}</Text>}
          {sale.customer?.email && <Text style={styles.customerDetail}>{sale.customer.email}</Text>}
        </View>

        <View style={styles.card}>
          <Text style={styles.sectionTitle}>Items</Text>
          {(sale.items || []).map((item, index) => (
            <View key={index} style={styles.itemRow}>
              <View style={styles.itemInfo}>
                <Text style={styles.itemName}>{item.name || item.product?.name}</Text>
                <Text style={styles.itemQty}>Qty: {item.qty} × {formatCurrency(item.price || item.sellPrice)}</Text>
              </View>
              <Text style={styles.itemTotal}>{formatCurrency(item.qty * (item.price || item.sellPrice))}</Text>
            </View>
          ))}
        </View>

        <View style={styles.card}>
          <Text style={styles.sectionTitle}>Summary</Text>
          <View style={styles.summaryRow}>
            <Text style={styles.summaryLabel}>Subtotal</Text>
            <Text style={styles.summaryValue}>{formatCurrency(sale.subtotal || sale.total)}</Text>
          </View>
          {sale.discount > 0 && (
            <View style={styles.summaryRow}>
              <Text style={styles.summaryLabel}>Discount</Text>
              <Text style={[styles.summaryValue, { color: COLORS.danger }]}>-{formatCurrency(sale.discount)}</Text>
            </View>
          )}
          {sale.vatPercent > 0 && (
            <View style={styles.summaryRow}>
              <Text style={styles.summaryLabel}>VAT ({sale.vatPercent}%)</Text>
              <Text style={styles.summaryValue}>{formatCurrency(((sale.subtotal || sale.total) - (sale.discount || 0)) * sale.vatPercent / 100)}</Text>
            </View>
          )}
          <View style={[styles.summaryRow, styles.totalRow]}>
            <Text style={styles.totalLabel}>Total</Text>
            <Text style={styles.totalValue}>{formatCurrency(sale.total)}</Text>
          </View>
          {sale.paidAmount > 0 && (
            <>
              <View style={styles.summaryRow}>
                <Text style={styles.summaryLabel}>Paid</Text>
                <Text style={styles.summaryValue}>{formatCurrency(sale.paidAmount)}</Text>
              </View>
              {sale.change > 0 && (
                <View style={styles.summaryRow}>
                  <Text style={styles.summaryLabel}>Change</Text>
                  <Text style={[styles.summaryValue, { color: COLORS.success }]}>{formatCurrency(sale.change)}</Text>
                </View>
              )}
            </>
          )}
          <View style={styles.summaryRow}>
            <Text style={styles.summaryLabel}>Payment Method</Text>
            <Text style={styles.summaryValue}>{sale.paymentMethod}</Text>
          </View>
        </View>
      </ScrollView>
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: COLORS.background,
  },
  content: {
    padding: 16,
    paddingBottom: 40,
  },
  card: {
    backgroundColor: COLORS.card,
    borderRadius: 10,
    padding: 16,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  invoiceHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  invoiceInfo: {
    flex: 1,
  },
  invoiceNumber: {
    fontSize: 16,
    fontWeight: '700',
    color: COLORS.text,
  },
  invoiceDate: {
    fontSize: 13,
    color: COLORS.muted,
    marginTop: 2,
  },
  sectionTitle: {
    fontSize: 14,
    fontWeight: '700',
    color: COLORS.text,
    marginBottom: 12,
  },
  customerName: {
    fontSize: 15,
    fontWeight: '600',
    color: COLORS.text,
  },
  customerDetail: {
    fontSize: 13,
    color: COLORS.muted,
    marginTop: 2,
  },
  itemRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  itemInfo: {
    flex: 1,
  },
  itemName: {
    fontSize: 14,
    fontWeight: '500',
    color: COLORS.text,
  },
  itemQty: {
    fontSize: 12,
    color: COLORS.muted,
    marginTop: 2,
  },
  itemTotal: {
    fontSize: 14,
    fontWeight: '600',
    color: COLORS.text,
  },
  summaryRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 6,
  },
  summaryLabel: {
    fontSize: 14,
    color: COLORS.muted,
  },
  summaryValue: {
    fontSize: 14,
    color: COLORS.text,
    fontWeight: '500',
  },
  totalRow: {
    borderTopWidth: 2,
    borderTopColor: COLORS.border,
    paddingTop: 10,
    marginTop: 4,
  },
  totalLabel: {
    fontSize: 16,
    fontWeight: '700',
    color: COLORS.text,
  },
  totalValue: {
    fontSize: 18,
    fontWeight: '800',
    color: COLORS.primary,
  },
  error: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  errorText: {
    fontSize: 16,
    color: COLORS.muted,
  },
});

export default SaleDetailScreen;
