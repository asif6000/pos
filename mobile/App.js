import React from 'react';
import { StatusBar } from 'expo-status-bar';
import { ActivityIndicator, View, StyleSheet } from 'react-native';
import { NavigationContainer } from '@react-navigation/native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createStackNavigator } from '@react-navigation/stack';
import { Ionicons } from '@expo/vector-icons';
import { AuthProvider, useAuth } from './src/context/AuthContext';
import { COLORS } from './src/utils/helpers';

import LoginScreen from './src/screens/auth/LoginScreen';
import RegisterScreen from './src/screens/auth/RegisterScreen';
import DashboardScreen from './src/screens/Dashboard/DashboardScreen';
import POSScreen from './src/screens/POS/POSScreen';
import ProductsScreen from './src/screens/Products/ProductsScreen';
import ProductFormScreen from './src/screens/Products/ProductFormScreen';
import CategoriesScreen from './src/screens/Categories/CategoriesScreen';
import CustomersScreen from './src/screens/Customers/CustomersScreen';
import CustomerFormScreen from './src/screens/Customers/CustomerFormScreen';
import SalesScreen from './src/screens/Sales/SalesScreen';
import SaleDetailScreen from './src/screens/Sales/SaleDetailScreen';
import StockScreen from './src/screens/Stock/StockScreen';
import ReportsScreen from './src/screens/Reports/ReportsScreen';
import UsersScreen from './src/screens/Users/UsersScreen';
import UserFormScreen from './src/screens/Users/UserFormScreen';
import SettingsScreen from './src/screens/Settings/SettingsScreen';
import ReturnsScreen from './src/screens/Returns/ReturnsScreen';

const Stack = createStackNavigator();
const Tab = createBottomTabNavigator();

const screenOptions = {
  headerShown: false,
  cardStyle: { backgroundColor: COLORS.background },
};

const AuthStack = () => (
  <Stack.Navigator screenOptions={screenOptions}>
    <Stack.Screen name="Login" component={LoginScreen} />
    <Stack.Screen name="Register" component={RegisterScreen} />
  </Stack.Navigator>
);

const MoreStack = () => (
  <Stack.Navigator screenOptions={screenOptions}>
    <Stack.Screen name="MoreMenu" component={MoreMenuScreen} />
    <Stack.Screen name="Customers" component={CustomersScreen} />
    <Stack.Screen name="CustomerForm" component={CustomerFormScreen} />
    <Stack.Screen name="Stock" component={StockScreen} />
    <Stack.Screen name="Reports" component={ReportsScreen} />
    <Stack.Screen name="Users" component={UsersScreen} />
    <Stack.Screen name="UserForm" component={UserFormScreen} />
    <Stack.Screen name="Settings" component={SettingsScreen} />
    <Stack.Screen name="Returns" component={ReturnsScreen} />
    <Stack.Screen name="Categories" component={CategoriesScreen} />
  </Stack.Navigator>
);

import { TouchableOpacity, Text, ScrollView } from 'react-native';

const MoreMenuScreen = ({ navigation }) => {
  const menuItems = [
    { name: 'Customers', icon: 'people-outline', screen: 'Customers', color: '#8b5cf6' },
    { name: 'Categories', icon: 'folder-outline', screen: 'Categories', color: '#06b6d4' },
    { name: 'Stock', icon: 'cube-outline', screen: 'Stock', color: '#f59e0b' },
    { name: 'Returns', icon: 'return-down-back', screen: 'Returns', color: '#ef4444' },
    { name: 'Reports', icon: 'bar-chart-outline', screen: 'Reports', color: '#10b981' },
    { name: 'Users', icon: 'people-circle-outline', screen: 'Users', color: '#3b82f6' },
    { name: 'Settings', icon: 'settings-outline', screen: 'Settings', color: '#6b7280' },
  ];

  return (
    <ScrollView style={styles.moreContainer}>
      <View style={styles.moreHeader}>
        <Text style={styles.moreTitle}>More</Text>
      </View>
      {menuItems.map((item) => (
        <TouchableOpacity
          key={item.name}
          style={styles.moreItem}
          onPress={() => navigation.navigate(item.screen)}
        >
          <View style={[styles.moreIcon, { backgroundColor: item.color + '15' }]}>
            <Ionicons name={item.icon} size={24} color={item.color} />
          </View>
          <Text style={styles.moreItemText}>{item.name}</Text>
          <Ionicons name="chevron-forward" size={20} color={COLORS.muted} />
        </TouchableOpacity>
      ))}
    </ScrollView>
  );
};

const ProductStack = () => (
  <Stack.Navigator screenOptions={screenOptions}>
    <Stack.Screen name="ProductsList" component={ProductsScreen} />
    <Stack.Screen name="ProductForm" component={ProductFormScreen} />
  </Stack.Navigator>
);

const SalesStack = () => (
  <Stack.Navigator screenOptions={screenOptions}>
    <Stack.Screen name="SalesList" component={SalesScreen} />
    <Stack.Screen name="SaleDetail" component={SaleDetailScreen} />
  </Stack.Navigator>
);

const MainTabs = () => (
  <Tab.Navigator
    screenOptions={({ route }) => ({
      headerShown: false,
      tabBarActiveTintColor: COLORS.primary,
      tabBarInactiveTintColor: COLORS.muted,
      tabBarStyle: {
        backgroundColor: COLORS.card,
        borderTopColor: COLORS.border,
        paddingBottom: 6,
        paddingTop: 6,
        height: 60,
      },
      tabBarLabelStyle: {
        fontSize: 11,
        fontWeight: '500',
      },
      tabBarIcon: ({ focused, color, size }) => {
        let iconName;
        if (route.name === 'Dashboard') iconName = focused ? 'grid' : 'grid-outline';
        else if (route.name === 'POS') iconName = focused ? 'cart' : 'cart-outline';
        else if (route.name === 'Products') iconName = focused ? 'cube' : 'cube-outline';
        else if (route.name === 'Sales') iconName = focused ? 'receipt' : 'receipt-outline';
        else if (route.name === 'More') iconName = focused ? 'menu' : 'menu-outline';
        return <Ionicons name={iconName} size={size} color={color} />;
      },
    })}
  >
    <Tab.Screen name="Dashboard" component={DashboardScreen} />
    <Tab.Screen name="POS" component={POSScreen} options={{ tabBarLabel: 'POS' }} />
    <Tab.Screen name="Products" component={ProductStack} />
    <Tab.Screen name="Sales" component={SalesStack} />
    <Tab.Screen name="More" component={MoreStack} />
  </Tab.Navigator>
);

const AppNavigator = () => {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <View style={styles.loadingContainer}>
        <ActivityIndicator size="large" color={COLORS.primary} />
      </View>
    );
  }

  return (
    <NavigationContainer>
      {user ? <MainTabs /> : <AuthStack />}
      <StatusBar style="auto" />
    </NavigationContainer>
  );
};

export default function App() {
  return (
    <AuthProvider>
      <AppNavigator />
    </AuthProvider>
  );
}

const styles = StyleSheet.create({
  loadingContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: COLORS.background,
  },
  moreContainer: {
    flex: 1,
    backgroundColor: COLORS.background,
  },
  moreHeader: {
    paddingHorizontal: 16,
    paddingTop: 16,
    paddingBottom: 12,
    backgroundColor: COLORS.card,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  moreTitle: {
    fontSize: 22,
    fontWeight: '700',
    color: COLORS.text,
  },
  moreItem: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: COLORS.card,
    marginHorizontal: 16,
    marginVertical: 4,
    padding: 16,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  moreIcon: {
    width: 40,
    height: 40,
    borderRadius: 10,
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: 14,
  },
  moreItemText: {
    flex: 1,
    fontSize: 16,
    fontWeight: '500',
    color: COLORS.text,
  },
});
