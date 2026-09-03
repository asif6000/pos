import React, { useState, useEffect, useCallback } from 'react';
import { View, Text, StyleSheet, ScrollView, RefreshControl, FlatList, TouchableOpacity } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useFocusEffect } from '@react-navigation/native';
import api from '../../config/api';
import { useAuth } from '../../context/AuthContext';
import { formatCurrency, formatDate, COLORS } from '../../utils/helpers';
import LoadingSpinner from '../../components/LoadingSpinner';
import EmptyState from '../../components/EmptyState';

const DashboardScreen = () => {
  const { user } = useAuth();
  const [stats, setStats] = useState({ todaySales: 0, monthlySales: 0, totalProducts: 0, totalCustomers: 0 });
  const [lowStock, setLowStock] = useState([]);
  const [recentSales, setRecentSales] = useState([]);
  const [topProducts, setTopProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const fetchData = async () => {
    try {
      const [statsRes, lowStockRes, salesRes, topRes] = await Promise.allSettled([
        api.get('/dashboard/stats'),
        api.get('/products/low-stock'),
        api.get('/sales?limit=5'),
        api.get('/reports/top-products?limit=5'),
      ]);
      if (statsRes.status === 'fulfilled') setStats(statsRes.value.data);
      if (lowStockRes.status === 'fulfilled') setLowStock(lowStockRes.value.data.products || lowStockRes.value.data || []);
      if (salesRes.status === 'fulfilled') setRecentSales(salesRes.value.data.sales || salesRes.value.data || []);
      if (topRes.status === 'fulfilled') setTopProducts(topRes.value.data.products || topRes.value.data || []);
    } catch (error) {
      console.error('Dashboard fetch error:', error);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useFocusEffect(useCallback(() => {
    fetchData();
  }, []));

  const onRefresh = () => {
    setRefreshing(true);
    fetchData();
  };

  if (loading) return <LoadingSpinner />;

  const statCards = [
    { title: "Today's Sales", value: formatCurrency(stats.todaySales || 0), icon: 'cash-outline', color: COLORS.primary },
    { title: 'Monthly Sales', value: formatCurrency(stats.monthlySales || 0), icon: 'trending-up-outline', color: COLORS.success },
    { title: 'Total Products', value: stats.totalProducts || 0, icon: 'cube-outline', color: COLORS.warning },
    { title: 'Total Customers', value: stats.totalCustomers || 0, icon: 'people-outline', color: '#8b5cf6' },
  ];

  return (
    <ScrollView style={styles.container} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}>
      <View style={styles.header}>
        <Text style={styles.greeting}>Hello, {user?.name || 'User'}!</Text>
        <Text style={styles.date}>{formatDate(new Date().toISOString())}</Text>
      </View>

      <View style={styles.statsGrid}>
        {statCards.map((stat, index) => (
          <View key={index} style={styles.statCard}>
            <View style={[styles.statIcon, { backgroundColor: stat.color + '15' }]}>
              <Ionicons name={stat.icon} size={24} color={stat.color} />
            </View>
            <Text style={styles.statValue}>{stat.value}</Text>
            <Text style={styles.statTitle}>{stat.title}</Text>
          </View>
        ))}
      </View>

      {lowStock.length > 0 && (
        <View style={styles.section}>
          <View style={styles.sectionHeader}>
            <Ionicons name="warning" size={20} color={COLORS.warning} />
            <Text style={styles.sectionTitle}>Low Stock Alerts</Text>
          </View>
          {lowStock.slice(0, 5).map((item) => (
            <View key={item._id || item.id} style={styles.lowStockItem}>
              <Text style={styles.lowStockName}>{item.name}</Text>
              <View style={[styles.lowStockBadge, { backgroundColor: COLORS.danger + '15' }]}>
                <Text style={[styles.lowStockQty, { color: COLORS.danger }]}>{item.stock} left</Text>
              </View>
            </View>
          ))}
        </View>
      )}

      <View style={styles.section}>
        <Text style={styles.sectionTitle}>Recent Sales</Text>
        {recentSales.length === 0 ? (
          <EmptyState icon="receipt-outline" message="No recent sales" />
        ) : (
          recentSales.map((sale) => (
            <View key={sale._id || sale.id} style={styles.saleItem}>
              <View style={styles.saleInfo}>
                <Text style={styles.saleInvoice}>{sale.invoiceNumber || 'N/A'}</Text>
                <Text style={styles.saleCustomer}>{sale.customer?.name || 'Walk-in'}</Text>
              </View>
              <View style={styles.saleRight}>
                <Text style={styles.saleAmount}>{formatCurrency(sale.total)}</Text>
                <Text style={styles.saleDate}>{formatDate(sale.createdAt || sale.date)}</Text>
              </View>
            </View>
          ))
        )}
      </View>

      {topProducts.length > 0 && (
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Top Selling Products</Text>
          {topProducts.map((item, index) => (
            <View key={item._id || item.id || index} style={styles.topItem}>
              <View style={styles.topRank}>
                <Text style={styles.rankNumber}>{index + 1}</Text>
              </View>
              <View style={styles.topInfo}>
                <Text style={styles.topName}>{item.name}</Text>
                <Text style={styles.topSold}>{item.totalSold || item.quantity || 0} sold</Text>
              </View>
            </View>
          ))}
        </View>
      )}
      <View style={{ height: 20 }} />
    </ScrollView>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: COLORS.background,
  },
  header: {
    paddingHorizontal: 16,
    paddingTop: 16,
    paddingBottom: 8,
    backgroundColor: COLORS.card,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  greeting: {
    fontSize: 22,
    fontWeight: '700',
    color: COLORS.text,
  },
  date: {
    fontSize: 13,
    color: COLORS.muted,
    marginTop: 4,
  },
  statsGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    padding: 12,
    gap: 10,
  },
  statCard: {
    width: '48%',
    backgroundColor: COLORS.card,
    borderRadius: 10,
    padding: 16,
    borderWidth: 1,
    borderColor: COLORS.border,
    marginLeft: '1%',
  },
  statIcon: {
    width: 40,
    height: 40,
    borderRadius: 10,
    justifyContent: 'center',
    alignItems: 'center',
    marginBottom: 10,
  },
  statValue: {
    fontSize: 18,
    fontWeight: '700',
    color: COLORS.text,
  },
  statTitle: {
    fontSize: 12,
    color: COLORS.muted,
    marginTop: 4,
  },
  section: {
    backgroundColor: COLORS.card,
    marginHorizontal: 16,
    marginBottom: 12,
    borderRadius: 10,
    padding: 16,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  sectionHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: 12,
  },
  sectionTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: COLORS.text,
    marginBottom: 12,
    marginLeft: 0,
  },
  lowStockItem: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  lowStockName: {
    fontSize: 14,
    color: COLORS.text,
    flex: 1,
  },
  lowStockBadge: {
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 12,
  },
  lowStockQty: {
    fontSize: 12,
    fontWeight: '600',
  },
  saleItem: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  saleInfo: {
    flex: 1,
  },
  saleInvoice: {
    fontSize: 14,
    fontWeight: '600',
    color: COLORS.text,
  },
  saleCustomer: {
    fontSize: 12,
    color: COLORS.muted,
    marginTop: 2,
  },
  saleRight: {
    alignItems: 'flex-end',
  },
  saleAmount: {
    fontSize: 14,
    fontWeight: '700',
    color: COLORS.success,
  },
  saleDate: {
    fontSize: 11,
    color: COLORS.muted,
    marginTop: 2,
  },
  topItem: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  topRank: {
    width: 28,
    height: 28,
    borderRadius: 14,
    backgroundColor: COLORS.primary + '15',
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: 12,
  },
  rankNumber: {
    fontSize: 12,
    fontWeight: '700',
    color: COLORS.primary,
  },
  topInfo: {
    flex: 1,
  },
  topName: {
    fontSize: 14,
    fontWeight: '600',
    color: COLORS.text,
  },
  topSold: {
    fontSize: 12,
    color: COLORS.muted,
    marginTop: 2,
  },
});

export default DashboardScreen;
