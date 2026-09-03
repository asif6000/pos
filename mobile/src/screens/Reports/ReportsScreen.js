import React, { useState, useCallback } from 'react';
import { View, Text, StyleSheet, ScrollView, TouchableOpacity, RefreshControl } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useFocusEffect } from '@react-navigation/native';
import api from '../../config/api';
import { formatCurrency, COLORS } from '../../utils/helpers';
import LoadingSpinner from '../../components/LoadingSpinner';

const TABS = ['Daily', 'Monthly', 'Top Products', 'Payment Methods', 'Category-wise'];

const ReportsScreen = () => {
  const [activeTab, setActiveTab] = useState('Daily');
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const fetchReport = async () => {
    try {
      let endpoint = '/reports/';
      if (activeTab === 'Daily') endpoint += 'daily';
      else if (activeTab === 'Monthly') endpoint += 'monthly';
      else if (activeTab === 'Top Products') endpoint += 'top-products';
      else if (activeTab === 'Payment Methods') endpoint += 'payment-methods';
      else if (activeTab === 'Category-wise') endpoint += 'category-wise';

      const res = await api.get(endpoint);
      setData(res.data);
    } catch (error) {
      console.error('Report fetch error:', error);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useFocusEffect(useCallback(() => {
    setLoading(true);
    fetchReport();
  }, [activeTab]));

  const onRefresh = () => {
    setRefreshing(true);
    fetchReport();
  };

  const renderDaily = () => (
    <View style={styles.reportCard}>
      <Text style={styles.reportTitle}>Today's Summary</Text>
      <StatRow label="Total Sales" value={formatCurrency(data?.totalSales || 0)} />
      <StatRow label="Transactions" value={data?.totalTransactions || 0} />
      <StatRow label="Average Sale" value={formatCurrency(data?.averageSale || 0)} />
      <StatRow label="Products Sold" value={data?.productsSold || 0} />
    </View>
  );

  const renderMonthly = () => (
    <View style={styles.reportCard}>
      <Text style={styles.reportTitle}>Monthly Summary</Text>
      <StatRow label="Total Sales" value={formatCurrency(data?.totalSales || 0)} />
      <StatRow label="Transactions" value={data?.totalTransactions || 0} />
      <StatRow label="Average Sale" value={formatCurrency(data?.averageSale || 0)} />
      <StatRow label="Revenue Growth" value={`${data?.growth || 0}%`} />
    </View>
  );

  const renderTopProducts = () => (
    <View style={styles.reportCard}>
      <Text style={styles.reportTitle}>Top Selling Products</Text>
      {(data?.products || data || []).slice(0, 10).map((item, index) => (
        <View key={item._id || item.id || index} style={styles.reportItem}>
          <View style={styles.rankBadge}>
            <Text style={styles.rankText}>{index + 1}</Text>
          </View>
          <View style={styles.reportItemInfo}>
            <Text style={styles.reportItemName}>{item.name}</Text>
            <Text style={styles.reportItemDetail}>{item.totalSold || item.quantity || 0} sold</Text>
          </View>
          <Text style={styles.reportItemValue}>{formatCurrency(item.revenue || (item.totalSold || 0) * (item.sellPrice || 0))}</Text>
        </View>
      ))}
    </View>
  );

  const renderPaymentMethods = () => (
    <View style={styles.reportCard}>
      <Text style={styles.reportTitle}>Sales by Payment Method</Text>
      {(data?.methods || data || []).map((item, index) => (
        <View key={item._id || item.method || index} style={styles.reportItem}>
          <View style={[styles.methodDot, { backgroundColor: COLORS.primary }]} />
          <View style={styles.reportItemInfo}>
            <Text style={styles.reportItemName}>{item.method || item._id}</Text>
            <Text style={styles.reportItemDetail}>{item.count || item.transactions || 0} transactions</Text>
          </View>
          <Text style={styles.reportItemValue}>{formatCurrency(item.total || item.amount || 0)}</Text>
        </View>
      ))}
    </View>
  );

  const renderCategoryWise = () => (
    <View style={styles.reportCard}>
      <Text style={styles.reportTitle}>Sales by Category</Text>
      {(data?.categories || data || []).map((item, index) => (
        <View key={item._id || item.category || index} style={styles.reportItem}>
          <Ionicons name="folder" size={20} color={COLORS.primary} />
          <View style={styles.reportItemInfo}>
            <Text style={styles.reportItemName}>{item.name || item._id || 'Unknown'}</Text>
            <Text style={styles.reportItemDetail}>{item.productCount || 0} products</Text>
          </View>
          <Text style={styles.reportItemValue}>{formatCurrency(item.totalSales || item.total || 0)}</Text>
        </View>
      ))}
    </View>
  );

  const renderContent = () => {
    if (loading) return <LoadingSpinner />;
    if (!data) return <Text style={styles.noData}>No data available</Text>;
    switch (activeTab) {
      case 'Daily': return renderDaily();
      case 'Monthly': return renderMonthly();
      case 'Top Products': return renderTopProducts();
      case 'Payment Methods': return renderPaymentMethods();
      case 'Category-wise': return renderCategoryWise();
      default: return null;
    }
  };

  return (
    <View style={styles.container}>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.tabBar} contentContainerStyle={styles.tabContent}>
        {TABS.map((tab) => (
          <TouchableOpacity
            key={tab}
            style={[styles.tab, activeTab === tab && styles.tabActive]}
            onPress={() => setActiveTab(tab)}
          >
            <Text style={[styles.tabText, activeTab === tab && styles.tabTextActive]}>{tab}</Text>
          </TouchableOpacity>
        ))}
      </ScrollView>

      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
      >
        {renderContent()}
      </ScrollView>
    </View>
  );
};

const StatRow = ({ label, value }) => (
  <View style={styles.statRow}>
    <Text style={styles.statLabel}>{label}</Text>
    <Text style={styles.statValue}>{value}</Text>
  </View>
);

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: COLORS.background,
  },
  tabBar: {
    backgroundColor: COLORS.card,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  tabContent: {
    paddingHorizontal: 12,
    paddingVertical: 10,
    gap: 8,
  },
  tab: {
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderRadius: 20,
    backgroundColor: COLORS.background,
    marginRight: 8,
  },
  tabActive: {
    backgroundColor: COLORS.primary,
  },
  tabText: {
    fontSize: 13,
    color: COLORS.text,
    fontWeight: '500',
  },
  tabTextActive: {
    color: '#ffffff',
  },
  content: {
    padding: 16,
    paddingBottom: 40,
  },
  reportCard: {
    backgroundColor: COLORS.card,
    borderRadius: 10,
    padding: 16,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  reportTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: COLORS.text,
    marginBottom: 16,
  },
  statRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  statLabel: {
    fontSize: 14,
    color: COLORS.muted,
  },
  statValue: {
    fontSize: 14,
    fontWeight: '600',
    color: COLORS.text,
  },
  reportItem: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
    gap: 10,
  },
  rankBadge: {
    width: 24,
    height: 24,
    borderRadius: 12,
    backgroundColor: COLORS.primary + '15',
    justifyContent: 'center',
    alignItems: 'center',
  },
  rankText: {
    fontSize: 11,
    fontWeight: '700',
    color: COLORS.primary,
  },
  methodDot: {
    width: 12,
    height: 12,
    borderRadius: 6,
  },
  reportItemInfo: {
    flex: 1,
  },
  reportItemName: {
    fontSize: 14,
    fontWeight: '600',
    color: COLORS.text,
  },
  reportItemDetail: {
    fontSize: 12,
    color: COLORS.muted,
    marginTop: 2,
  },
  reportItemValue: {
    fontSize: 14,
    fontWeight: '600',
    color: COLORS.text,
  },
  noData: {
    textAlign: 'center',
    color: COLORS.muted,
    marginTop: 40,
    fontSize: 15,
  },
});

export default ReportsScreen;
