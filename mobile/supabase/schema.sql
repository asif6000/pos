-- ============================================================================
-- SMART POS - Supabase PostgreSQL Schema
-- ============================================================================
-- Run this file in the Supabase SQL Editor to set up the complete database.
-- ============================================================================

-- Enable UUID generation
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ============================================================================
-- 1. TABLES
-- ============================================================================

-- ----------------------------
-- roles
-- ----------------------------
CREATE TABLE public.roles (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ----------------------------
-- profiles (linked to auth.users)
-- ----------------------------
CREATE TABLE public.profiles (
    id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
    full_name TEXT,
    email TEXT,
    phone TEXT,
    role_slug TEXT NOT NULL DEFAULT 'cashier',
    owner_id UUID,
    status TEXT NOT NULL DEFAULT 'active',
    avatar_url TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_profiles_owner_id ON public.profiles(owner_id);
CREATE INDEX idx_profiles_role_slug ON public.profiles(role_slug);

-- ----------------------------
-- stores
-- ----------------------------
CREATE TABLE public.stores (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name TEXT NOT NULL,
    address TEXT,
    phone TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    owner_id UUID,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_stores_owner_id ON public.stores(owner_id);

-- ----------------------------
-- categories
-- ----------------------------
CREATE TABLE public.categories (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name TEXT NOT NULL,
    description TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    owner_id UUID,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_categories_owner_id ON public.categories(owner_id);

-- ----------------------------
-- products
-- ----------------------------
CREATE TABLE public.products (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name TEXT NOT NULL,
    barcode TEXT UNIQUE,
    category_id UUID REFERENCES public.categories(id) ON DELETE SET NULL,
    buy_price NUMERIC(12,2) NOT NULL DEFAULT 0,
    sell_price NUMERIC(12,2) NOT NULL DEFAULT 0,
    stock NUMERIC(12,2) NOT NULL DEFAULT 0,
    min_stock NUMERIC(12,2) NOT NULL DEFAULT 0,
    unit TEXT NOT NULL DEFAULT 'pcs',
    description TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    owner_id UUID,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_products_category_id ON public.products(category_id);
CREATE INDEX idx_products_owner_id ON public.products(owner_id);
CREATE INDEX idx_products_barcode ON public.products(barcode);

-- ----------------------------
-- store_stocks
-- ----------------------------
CREATE TABLE public.store_stocks (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    store_id UUID NOT NULL REFERENCES public.stores(id) ON DELETE CASCADE,
    product_id UUID NOT NULL REFERENCES public.products(id) ON DELETE CASCADE,
    quantity NUMERIC(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE(store_id, product_id)
);

CREATE INDEX idx_store_stocks_store_id ON public.store_stocks(store_id);
CREATE INDEX idx_store_stocks_product_id ON public.store_stocks(product_id);

-- ----------------------------
-- transfers
-- ----------------------------
CREATE TABLE public.transfers (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    reference_no TEXT NOT NULL UNIQUE,
    from_store_id UUID NOT NULL REFERENCES public.stores(id) ON DELETE RESTRICT,
    to_store_id UUID NOT NULL REFERENCES public.stores(id) ON DELETE RESTRICT,
    status TEXT NOT NULL DEFAULT 'pending',
    note TEXT,
    created_by UUID REFERENCES public.profiles(id) ON DELETE SET NULL,
    owner_id UUID,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_transfers_from_store_id ON public.transfers(from_store_id);
CREATE INDEX idx_transfers_to_store_id ON public.transfers(to_store_id);
CREATE INDEX idx_transfers_owner_id ON public.transfers(owner_id);

-- ----------------------------
-- transfer_items
-- ----------------------------
CREATE TABLE public.transfer_items (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    transfer_id UUID NOT NULL REFERENCES public.transfers(id) ON DELETE CASCADE,
    product_id UUID NOT NULL REFERENCES public.products(id) ON DELETE RESTRICT,
    quantity NUMERIC(12,2) NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_transfer_items_transfer_id ON public.transfer_items(transfer_id);
CREATE INDEX idx_transfer_items_product_id ON public.transfer_items(product_id);

-- ----------------------------
-- customers
-- ----------------------------
CREATE TABLE public.customers (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name TEXT NOT NULL,
    phone TEXT,
    email TEXT,
    address TEXT,
    owner_id UUID,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_customers_owner_id ON public.customers(owner_id);

-- ----------------------------
-- sales
-- ----------------------------
CREATE TABLE public.sales (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    invoice_number TEXT NOT NULL UNIQUE,
    customer_id UUID REFERENCES public.customers(id) ON DELETE SET NULL,
    user_id UUID REFERENCES public.profiles(id) ON DELETE SET NULL,
    subtotal NUMERIC(12,2) NOT NULL DEFAULT 0,
    discount_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
    discount_percent NUMERIC(5,2) NOT NULL DEFAULT 0,
    vat_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
    vat_percent NUMERIC(5,2) NOT NULL DEFAULT 0,
    total NUMERIC(12,2) NOT NULL DEFAULT 0,
    paid_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
    change_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
    payment_method TEXT NOT NULL DEFAULT 'cash',
    payment_status TEXT NOT NULL DEFAULT 'paid',
    note TEXT,
    owner_id UUID,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_sales_customer_id ON public.sales(customer_id);
CREATE INDEX idx_sales_user_id ON public.sales(user_id);
CREATE INDEX idx_sales_owner_id ON public.sales(owner_id);
CREATE INDEX idx_sales_created_at ON public.sales(created_at);
CREATE INDEX idx_sales_payment_status ON public.sales(payment_status);

-- ----------------------------
-- sale_items
-- ----------------------------
CREATE TABLE public.sale_items (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    sale_id UUID NOT NULL REFERENCES public.sales(id) ON DELETE CASCADE,
    product_id UUID NOT NULL REFERENCES public.products(id) ON DELETE RESTRICT,
    product_name TEXT NOT NULL,
    quantity NUMERIC(12,2) NOT NULL DEFAULT 1,
    unit_price NUMERIC(12,2) NOT NULL DEFAULT 0,
    total_price NUMERIC(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_sale_items_sale_id ON public.sale_items(sale_id);
CREATE INDEX idx_sale_items_product_id ON public.sale_items(product_id);

-- ----------------------------
-- settings
-- ----------------------------
CREATE TABLE public.settings (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    setting_key TEXT NOT NULL,
    setting_value TEXT,
    owner_id UUID,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE(owner_id, setting_key)
);

CREATE INDEX idx_settings_owner_id ON public.settings(owner_id);

-- ----------------------------
-- stock_history
-- ----------------------------
CREATE TABLE public.stock_history (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    product_id UUID NOT NULL REFERENCES public.products(id) ON DELETE RESTRICT,
    quantity_change NUMERIC(12,2) NOT NULL,
    type TEXT NOT NULL,
    reference_id UUID,
    note TEXT,
    user_id UUID REFERENCES public.profiles(id) ON DELETE SET NULL,
    owner_id UUID,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_stock_history_product_id ON public.stock_history(product_id);
CREATE INDEX idx_stock_history_user_id ON public.stock_history(user_id);
CREATE INDEX idx_stock_history_owner_id ON public.stock_history(owner_id);
CREATE INDEX idx_stock_history_created_at ON public.stock_history(created_at);

-- ----------------------------
-- returns
-- ----------------------------
CREATE TABLE public.returns (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    return_number TEXT NOT NULL UNIQUE,
    sale_id UUID NOT NULL REFERENCES public.sales(id) ON DELETE RESTRICT,
    user_id UUID REFERENCES public.profiles(id) ON DELETE SET NULL,
    total_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
    refund_method TEXT NOT NULL DEFAULT 'cash',
    reason TEXT,
    status TEXT NOT NULL DEFAULT 'completed',
    owner_id UUID,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_returns_sale_id ON public.returns(sale_id);
CREATE INDEX idx_returns_user_id ON public.returns(user_id);
CREATE INDEX idx_returns_owner_id ON public.returns(owner_id);

-- ----------------------------
-- return_items
-- ----------------------------
CREATE TABLE public.return_items (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    return_id UUID NOT NULL REFERENCES public.returns(id) ON DELETE CASCADE,
    product_id UUID NOT NULL REFERENCES public.products(id) ON DELETE RESTRICT,
    product_name TEXT NOT NULL,
    quantity NUMERIC(12,2) NOT NULL DEFAULT 1,
    unit_price NUMERIC(12,2) NOT NULL DEFAULT 0,
    total_price NUMERIC(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_return_items_return_id ON public.return_items(return_id);
CREATE INDEX idx_return_items_product_id ON public.return_items(product_id);

-- ----------------------------
-- role_permissions
-- ----------------------------
CREATE TABLE public.role_permissions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    role_slug TEXT NOT NULL,
    permission TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE(role_slug, permission)
);

CREATE INDEX idx_role_permissions_role_slug ON public.role_permissions(role_slug);

-- ----------------------------
-- system_settings
-- ----------------------------
CREATE TABLE public.system_settings (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    setting_key TEXT NOT NULL UNIQUE,
    setting_value TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- 2. HELPER FUNCTIONS
-- ============================================================================

-- Function to check if current user is super admin (admin role with no owner_id)
CREATE OR REPLACE FUNCTION public.is_super_admin()
RETURNS BOOLEAN AS $$
DECLARE
    v_role TEXT;
    v_owner_id UUID;
BEGIN
    SELECT p.role_slug, p.owner_id INTO v_role, v_owner_id
    FROM public.profiles p
    WHERE p.id = auth.uid();

    RETURN v_role = 'admin' AND v_owner_id IS NULL;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

-- Function to get current user's owner_id
CREATE OR REPLACE FUNCTION public.get_owner_id()
RETURNS UUID AS $$
DECLARE
    v_owner_id UUID;
BEGIN
    SELECT p.owner_id INTO v_owner_id
    FROM public.profiles p
    WHERE p.id = auth.uid();

    RETURN v_owner_id;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

-- Function to get current user's profile
CREATE OR REPLACE FUNCTION public.get_profile()
RETURNS public.profiles AS $$
DECLARE
    v_profile public.profiles%ROWTYPE;
BEGIN
    SELECT * INTO v_profile
    FROM public.profiles
    WHERE id = auth.uid();

    RETURN v_profile;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

-- ============================================================================
-- 3. ROW LEVEL SECURITY (RLS)
-- ============================================================================

-- Enable RLS on all tables
ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.stores ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.products ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.store_stocks ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.transfers ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.transfer_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.customers ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.sales ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.sale_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.stock_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.returns ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.return_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.role_permissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.system_settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.roles ENABLE ROW LEVEL SECURITY;

-- ----------------------------
-- profiles policies
-- ----------------------------
CREATE POLICY "Users can view own profile"
    ON public.profiles FOR SELECT
    USING (id = auth.uid());

CREATE POLICY "Super admin can view all profiles"
    ON public.profiles FOR SELECT
    USING (public.is_super_admin());

CREATE POLICY "Users can update own profile"
    ON public.profiles FOR UPDATE
    USING (id = auth.uid())
    WITH CHECK (id = auth.uid());

CREATE POLICY "Super admin can update all profiles"
    ON public.profiles FOR UPDATE
    USING (public.is_super_admin())
    WITH CHECK (public.is_super_admin());

CREATE POLICY "Super admin can insert profiles"
    ON public.profiles FOR INSERT
    WITH CHECK (public.is_super_admin());

CREATE POLICY "Super admin can delete profiles"
    ON public.profiles FOR DELETE
    USING (public.is_super_admin());

-- ----------------------------
-- roles policies
-- ----------------------------
CREATE POLICY "Anyone authenticated can view roles"
    ON public.roles FOR SELECT
    USING (auth.role() = 'authenticated');

CREATE POLICY "Super admin can manage roles"
    ON public.roles FOR ALL
    USING (public.is_super_admin());

-- ----------------------------
-- stores policies
-- ----------------------------
CREATE POLICY "Users can view stores in their owner scope"
    ON public.stores FOR SELECT
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
        OR owner_id IS NULL
    );

CREATE POLICY "Users can insert stores in their owner scope"
    ON public.stores FOR INSERT
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can update stores in their owner scope"
    ON public.stores FOR UPDATE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    )
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can delete stores in their owner scope"
    ON public.stores FOR DELETE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

-- ----------------------------
-- categories policies
-- ----------------------------
CREATE POLICY "Users can view categories in their owner scope"
    ON public.categories FOR SELECT
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
        OR owner_id IS NULL
    );

CREATE POLICY "Users can insert categories in their owner scope"
    ON public.categories FOR INSERT
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can update categories in their owner scope"
    ON public.categories FOR UPDATE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    )
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can delete categories in their owner scope"
    ON public.categories FOR DELETE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

-- ----------------------------
-- products policies
-- ----------------------------
CREATE POLICY "Users can view products in their owner scope"
    ON public.products FOR SELECT
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
        OR owner_id IS NULL
    );

CREATE POLICY "Users can insert products in their owner scope"
    ON public.products FOR INSERT
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can update products in their owner scope"
    ON public.products FOR UPDATE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    )
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can delete products in their owner scope"
    ON public.products FOR DELETE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

-- ----------------------------
-- store_stocks policies
-- ----------------------------
CREATE POLICY "Users can view store_stocks in their owner scope"
    ON public.store_stocks FOR SELECT
    USING (
        public.is_super_admin()
        OR store_id IN (
            SELECT s.id FROM public.stores s
            WHERE s.owner_id = public.get_owner_id()
        )
    );

CREATE POLICY "Users can insert store_stocks in their owner scope"
    ON public.store_stocks FOR INSERT
    WITH CHECK (
        public.is_super_admin()
        OR store_id IN (
            SELECT s.id FROM public.stores s
            WHERE s.owner_id = public.get_owner_id()
        )
    );

CREATE POLICY "Users can update store_stocks in their owner scope"
    ON public.store_stocks FOR UPDATE
    USING (
        public.is_super_admin()
        OR store_id IN (
            SELECT s.id FROM public.stores s
            WHERE s.owner_id = public.get_owner_id()
        )
    )
    WITH CHECK (
        public.is_super_admin()
        OR store_id IN (
            SELECT s.id FROM public.stores s
            WHERE s.owner_id = public.get_owner_id()
        )
    );

CREATE POLICY "Users can delete store_stocks in their owner scope"
    ON public.store_stocks FOR DELETE
    USING (
        public.is_super_admin()
        OR store_id IN (
            SELECT s.id FROM public.stores s
            WHERE s.owner_id = public.get_owner_id()
        )
    );

-- ----------------------------
-- transfers policies
-- ----------------------------
CREATE POLICY "Users can view transfers in their owner scope"
    ON public.transfers FOR SELECT
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can insert transfers in their owner scope"
    ON public.transfers FOR INSERT
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can update transfers in their owner scope"
    ON public.transfers FOR UPDATE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    )
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can delete transfers in their owner scope"
    ON public.transfers FOR DELETE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

-- ----------------------------
-- transfer_items policies
-- ----------------------------
CREATE POLICY "Users can view transfer_items via parent transfer"
    ON public.transfer_items FOR SELECT
    USING (
        public.is_super_admin()
        OR transfer_id IN (
            SELECT t.id FROM public.transfers t
            WHERE t.owner_id = public.get_owner_id()
        )
    );

CREATE POLICY "Users can insert transfer_items via parent transfer"
    ON public.transfer_items FOR INSERT
    WITH CHECK (
        public.is_super_admin()
        OR transfer_id IN (
            SELECT t.id FROM public.transfers t
            WHERE t.owner_id = public.get_owner_id()
        )
    );

CREATE POLICY "Users can update transfer_items via parent transfer"
    ON public.transfer_items FOR UPDATE
    USING (
        public.is_super_admin()
        OR transfer_id IN (
            SELECT t.id FROM public.transfers t
            WHERE t.owner_id = public.get_owner_id()
        )
    )
    WITH CHECK (
        public.is_super_admin()
        OR transfer_id IN (
            SELECT t.id FROM public.transfers t
            WHERE t.owner_id = public.get_owner_id()
        )
    );

CREATE POLICY "Users can delete transfer_items via parent transfer"
    ON public.transfer_items FOR DELETE
    USING (
        public.is_super_admin()
        OR transfer_id IN (
            SELECT t.id FROM public.transfers t
            WHERE t.owner_id = public.get_owner_id()
        )
    );

-- ----------------------------
-- customers policies
-- ----------------------------
CREATE POLICY "Users can view customers in their owner scope"
    ON public.customers FOR SELECT
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
        OR owner_id IS NULL
    );

CREATE POLICY "Users can insert customers in their owner scope"
    ON public.customers FOR INSERT
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can update customers in their owner scope"
    ON public.customers FOR UPDATE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    )
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can delete customers in their owner scope"
    ON public.customers FOR DELETE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

-- ----------------------------
-- sales policies
-- ----------------------------
CREATE POLICY "Users can view sales in their owner scope"
    ON public.sales FOR SELECT
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can insert sales in their owner scope"
    ON public.sales FOR INSERT
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can update sales in their owner scope"
    ON public.sales FOR UPDATE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    )
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can delete sales in their owner scope"
    ON public.sales FOR DELETE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

-- ----------------------------
-- sale_items policies
-- ----------------------------
CREATE POLICY "Users can view sale_items via parent sale"
    ON public.sale_items FOR SELECT
    USING (
        public.is_super_admin()
        OR sale_id IN (
            SELECT s.id FROM public.sales s
            WHERE s.owner_id = public.get_owner_id()
        )
    );

CREATE POLICY "Users can insert sale_items via parent sale"
    ON public.sale_items FOR INSERT
    WITH CHECK (
        public.is_super_admin()
        OR sale_id IN (
            SELECT s.id FROM public.sales s
            WHERE s.owner_id = public.get_owner_id()
        )
    );

CREATE POLICY "Users can update sale_items via parent sale"
    ON public.sale_items FOR UPDATE
    USING (
        public.is_super_admin()
        OR sale_id IN (
            SELECT s.id FROM public.sales s
            WHERE s.owner_id = public.get_owner_id()
        )
    )
    WITH CHECK (
        public.is_super_admin()
        OR sale_id IN (
            SELECT s.id FROM public.sales s
            WHERE s.owner_id = public.get_owner_id()
        )
    );

CREATE POLICY "Users can delete sale_items via parent sale"
    ON public.sale_items FOR DELETE
    USING (
        public.is_super_admin()
        OR sale_id IN (
            SELECT s.id FROM public.sales s
            WHERE s.owner_id = public.get_owner_id()
        )
    );

-- ----------------------------
-- settings policies
-- ----------------------------
CREATE POLICY "Users can view settings in their owner scope"
    ON public.settings FOR SELECT
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can insert settings in their owner scope"
    ON public.settings FOR INSERT
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can update settings in their owner scope"
    ON public.settings FOR UPDATE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    )
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can delete settings in their owner scope"
    ON public.settings FOR DELETE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

-- ----------------------------
-- stock_history policies
-- ----------------------------
CREATE POLICY "Users can view stock_history in their owner scope"
    ON public.stock_history FOR SELECT
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can insert stock_history in their owner scope"
    ON public.stock_history FOR INSERT
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

-- ----------------------------
-- returns policies
-- ----------------------------
CREATE POLICY "Users can view returns in their owner scope"
    ON public.returns FOR SELECT
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can insert returns in their owner scope"
    ON public.returns FOR INSERT
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can update returns in their owner scope"
    ON public.returns FOR UPDATE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    )
    WITH CHECK (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

CREATE POLICY "Users can delete returns in their owner scope"
    ON public.returns FOR DELETE
    USING (
        public.is_super_admin()
        OR owner_id = public.get_owner_id()
    );

-- ----------------------------
-- return_items policies
-- ----------------------------
CREATE POLICY "Users can view return_items via parent return"
    ON public.return_items FOR SELECT
    USING (
        public.is_super_admin()
        OR return_id IN (
            SELECT r.id FROM public.returns r
            WHERE r.owner_id = public.get_owner_id()
        )
    );

CREATE POLICY "Users can insert return_items via parent return"
    ON public.return_items FOR INSERT
    WITH CHECK (
        public.is_super_admin()
        OR return_id IN (
            SELECT r.id FROM public.returns r
            WHERE r.owner_id = public.get_owner_id()
        )
    );

CREATE POLICY "Users can update return_items via parent return"
    ON public.return_items FOR UPDATE
    USING (
        public.is_super_admin()
        OR return_id IN (
            SELECT r.id FROM public.returns r
            WHERE r.owner_id = public.get_owner_id()
        )
    )
    WITH CHECK (
        public.is_super_admin()
        OR return_id IN (
            SELECT r.id FROM public.returns r
            WHERE r.owner_id = public.get_owner_id()
        )
    );

CREATE POLICY "Users can delete return_items via parent return"
    ON public.return_items FOR DELETE
    USING (
        public.is_super_admin()
        OR return_id IN (
            SELECT r.id FROM public.returns r
            WHERE r.owner_id = public.get_owner_id()
        )
    );

-- ----------------------------
-- role_permissions policies
-- ----------------------------
CREATE POLICY "Anyone authenticated can view role_permissions"
    ON public.role_permissions FOR SELECT
    USING (auth.role() = 'authenticated');

CREATE POLICY "Super admin can manage role_permissions"
    ON public.role_permissions FOR ALL
    USING (public.is_super_admin());

-- ----------------------------
-- system_settings policies
-- ----------------------------
CREATE POLICY "Anyone authenticated can view system_settings"
    ON public.system_settings FOR SELECT
    USING (auth.role() = 'authenticated');

CREATE POLICY "Super admin can manage system_settings"
    ON public.system_settings FOR ALL
    USING (public.is_super_admin());

-- ============================================================================
-- 4. TRIGGER: Auto-create profile on user signup
-- ============================================================================

CREATE OR REPLACE FUNCTION public.handle_new_user()
RETURNS TRIGGER AS $$
BEGIN
    INSERT INTO public.profiles (id, full_name, email, role_slug, owner_id, status)
    VALUES (
        NEW.id,
        COALESCE(NEW.raw_user_meta_data ->> 'full_name', NEW.raw_user_meta_data ->> 'name', ''),
        COALESCE(NEW.email, ''),
        COALESCE(NEW.raw_user_meta_data ->> 'role_slug', 'cashier'),
        CASE
            WHEN (NEW.raw_user_meta_data ->> 'owner_id') IS NOT NULL
            THEN (NEW.raw_user_meta_data ->> 'owner_id')::UUID
            ELSE NULL
        END,
        'active'
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE TRIGGER on_auth_user_created
    AFTER INSERT ON auth.users
    FOR EACH ROW
    EXECUTE FUNCTION public.handle_new_user();

-- ============================================================================
-- 5. TRIGGER: Auto-update updated_at timestamp
-- ============================================================================

CREATE OR REPLACE FUNCTION public.update_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_profiles_updated_at BEFORE UPDATE ON public.profiles
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();

CREATE TRIGGER update_stores_updated_at BEFORE UPDATE ON public.stores
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();

CREATE TRIGGER update_categories_updated_at BEFORE UPDATE ON public.categories
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();

CREATE TRIGGER update_products_updated_at BEFORE UPDATE ON public.products
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();

CREATE TRIGGER update_store_stocks_updated_at BEFORE UPDATE ON public.store_stocks
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();

CREATE TRIGGER update_transfers_updated_at BEFORE UPDATE ON public.transfers
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();

CREATE TRIGGER update_customers_updated_at BEFORE UPDATE ON public.customers
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();

CREATE TRIGGER update_sales_updated_at BEFORE UPDATE ON public.sales
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();

CREATE TRIGGER update_settings_updated_at BEFORE UPDATE ON public.settings
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();

CREATE TRIGGER update_returns_updated_at BEFORE UPDATE ON public.returns
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();

CREATE TRIGGER update_system_settings_updated_at BEFORE UPDATE ON public.system_settings
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();

-- ============================================================================
-- 6. DATABASE FUNCTIONS: Business Logic
-- ============================================================================

-- ----------------------------
-- generate_invoice_number()
-- ----------------------------
CREATE OR REPLACE FUNCTION public.generate_invoice_number()
RETURNS TEXT AS $$
DECLARE
    v_date_part TEXT;
    v_seq BIGINT;
    v_invoice TEXT;
BEGIN
    v_date_part := to_char(now(), 'YYYYMMDD');

    SELECT COUNT(*) + 1 INTO v_seq
    FROM public.sales
    WHERE to_char(created_at, 'YYYYMMDD') = v_date_part;

    v_invoice := 'INV-' || v_date_part || '-' || LPAD(v_seq::TEXT, 5, '0');

    RETURN v_invoice;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ----------------------------
-- generate_return_number()
-- ----------------------------
CREATE OR REPLACE FUNCTION public.generate_return_number()
RETURNS TEXT AS $$
DECLARE
    v_date_part TEXT;
    v_seq BIGINT;
    v_return_no TEXT;
BEGIN
    v_date_part := to_char(now(), 'YYYYMMDD');

    SELECT COUNT(*) + 1 INTO v_seq
    FROM public.returns
    WHERE to_char(created_at, 'YYYYMMDD') = v_date_part;

    v_return_no := 'RET-' || v_date_part || '-' || LPAD(v_seq::TEXT, 5, '0');

    RETURN v_return_no;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ----------------------------
-- generate_transfer_reference()
-- ----------------------------
CREATE OR REPLACE FUNCTION public.generate_transfer_reference()
RETURNS TEXT AS $$
DECLARE
    v_date_part TEXT;
    v_seq BIGINT;
    v_ref TEXT;
BEGIN
    v_date_part := to_char(now(), 'YYYYMMDD');

    SELECT COUNT(*) + 1 INTO v_seq
    FROM public.transfers
    WHERE to_char(created_at, 'YYYYMMDD') = v_date_part;

    v_ref := 'TRF-' || v_date_part || '-' || LPAD(v_seq::TEXT, 5, '0');

    RETURN v_ref;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ----------------------------
-- create_sale()
-- ----------------------------
-- p_items JSON format: [{"product_id": "uuid", "product_name": "name", "quantity": 2, "unit_price": 10.00, "total_price": 20.00}, ...]
CREATE OR REPLACE FUNCTION public.create_sale(
    p_customer_id UUID,
    p_items JSON,
    p_subtotal NUMERIC,
    p_discount_amount NUMERIC,
    p_discount_percent NUMERIC,
    p_vat_amount NUMERIC,
    p_vat_percent NUMERIC,
    p_total NUMERIC,
    p_paid_amount NUMERIC,
    p_change_amount NUMERIC,
    p_payment_method TEXT,
    p_note TEXT
)
RETURNS JSON AS $$
DECLARE
    v_sale_id UUID;
    v_invoice_number TEXT;
    v_owner_id UUID;
    v_user_id UUID;
    v_item JSON;
    v_new_sale JSON;
BEGIN
    v_owner_id := public.get_owner_id();
    v_user_id := auth.uid();
    v_invoice_number := public.generate_invoice_number();

    INSERT INTO public.sales (
        invoice_number, customer_id, user_id, subtotal,
        discount_amount, discount_percent, vat_amount, vat_percent,
        total, paid_amount, change_amount,
        payment_method, payment_status, note, owner_id
    ) VALUES (
        v_invoice_number, p_customer_id, v_user_id, p_subtotal,
        p_discount_amount, p_discount_percent, p_vat_amount, p_vat_percent,
        p_total, p_paid_amount, p_change_amount,
        p_payment_method,
        CASE WHEN p_paid_amount >= p_total THEN 'paid' ELSE 'partial' END,
        p_note, v_owner_id
    ) RETURNING id INTO v_sale_id;

    FOR v_item IN SELECT * FROM json_array_elements(p_items)
    LOOP
        INSERT INTO public.sale_items (
            sale_id, product_id, product_name, quantity, unit_price, total_price
        ) VALUES (
            v_sale_id,
            (v_item ->> 'product_id')::UUID,
            v_item ->> 'product_name',
            (v_item ->> 'quantity')::NUMERIC,
            (v_item ->> 'unit_price')::NUMERIC,
            (v_item ->> 'total_price')::NUMERIC
        );

        UPDATE public.products
        SET stock = stock - (v_item ->> 'quantity')::NUMERIC
        WHERE id = (v_item ->> 'product_id')::UUID;

        INSERT INTO public.stock_history (
            product_id, quantity_change, type, reference_id, note, user_id, owner_id
        ) VALUES (
            (v_item ->> 'product_id')::UUID,
            -((v_item ->> 'quantity')::NUMERIC),
            'sale',
            v_sale_id,
            'Sale ' || v_invoice_number,
            v_user_id,
            v_owner_id
        );
    END LOOP;

    SELECT row_to_json(s.*) INTO v_new_sale
    FROM public.sales s
    WHERE s.id = v_sale_id;

    RETURN v_new_sale;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ----------------------------
-- process_return()
-- ----------------------------
-- p_items JSON format: [{"product_id": "uuid", "product_name": "name", "quantity": 1, "unit_price": 10.00, "total_price": 10.00}, ...]
CREATE OR REPLACE FUNCTION public.process_return(
    p_sale_id UUID,
    p_items JSON,
    p_total_amount NUMERIC,
    p_refund_method TEXT,
    p_reason TEXT
)
RETURNS JSON AS $$
DECLARE
    v_return_id UUID;
    v_return_number TEXT;
    v_owner_id UUID;
    v_user_id UUID;
    v_item JSON;
    v_new_return JSON;
    v_original_sale public.sales%ROWTYPE;
BEGIN
    v_owner_id := public.get_owner_id();
    v_user_id := auth.uid();
    v_return_number := public.generate_return_number();

    SELECT * INTO v_original_sale
    FROM public.sales
    WHERE id = p_sale_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Original sale not found: %', p_sale_id;
    END IF;

    INSERT INTO public.returns (
        return_number, sale_id, user_id, total_amount,
        refund_method, reason, status, owner_id
    ) VALUES (
        v_return_number, p_sale_id, v_user_id, p_total_amount,
        p_refund_method, p_reason, 'completed', v_owner_id
    ) RETURNING id INTO v_return_id;

    FOR v_item IN SELECT * FROM json_array_elements(p_items)
    LOOP
        INSERT INTO public.return_items (
            return_id, product_id, product_name, quantity, unit_price, total_price
        ) VALUES (
            v_return_id,
            (v_item ->> 'product_id')::UUID,
            v_item ->> 'product_name',
            (v_item ->> 'quantity')::NUMERIC,
            (v_item ->> 'unit_price')::NUMERIC,
            (v_item ->> 'total_price')::NUMERIC
        );

        UPDATE public.products
        SET stock = stock + (v_item ->> 'quantity')::NUMERIC
        WHERE id = (v_item ->> 'product_id')::UUID;

        INSERT INTO public.stock_history (
            product_id, quantity_change, type, reference_id, note, user_id, owner_id
        ) VALUES (
            (v_item ->> 'product_id')::UUID,
            (v_item ->> 'quantity')::NUMERIC,
            'return',
            v_return_id,
            'Return ' || v_return_number || ' from sale ' || v_original_sale.invoice_number,
            v_user_id,
            v_owner_id
        );
    END LOOP;

    SELECT row_to_json(r.*) INTO v_new_return
    FROM public.returns r
    WHERE r.id = v_return_id;

    RETURN v_new_return;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ----------------------------
-- get_dashboard_stats()
-- ----------------------------
CREATE OR REPLACE FUNCTION public.get_dashboard_stats(p_owner_id UUID)
RETURNS JSON AS $$
DECLARE
    v_result JSON;
    v_today_sales NUMERIC;
    v_monthly_sales NUMERIC;
    v_total_products BIGINT;
    v_total_customers BIGINT;
    v_low_stock_count BIGINT;
BEGIN
    SELECT COALESCE(SUM(s.total), 0) INTO v_today_sales
    FROM public.sales s
    WHERE s.owner_id = p_owner_id
      AND s.created_at::date = CURRENT_DATE
      AND s.payment_status != 'cancelled';

    SELECT COALESCE(SUM(s.total), 0) INTO v_monthly_sales
    FROM public.sales s
    WHERE s.owner_id = p_owner_id
      AND date_trunc('month', s.created_at) = date_trunc('month', CURRENT_DATE)
      AND s.payment_status != 'cancelled';

    SELECT COUNT(*) INTO v_total_products
    FROM public.products p
    WHERE p.owner_id = p_owner_id
      AND p.status = 'active';

    SELECT COUNT(*) INTO v_total_customers
    FROM public.customers c
    WHERE c.owner_id = p_owner_id;

    SELECT COUNT(*) INTO v_low_stock_count
    FROM public.products p
    WHERE p.owner_id = p_owner_id
      AND p.status = 'active'
      AND p.stock <= p.min_stock;

    v_result := json_build_object(
        'today_sales', v_today_sales,
        'monthly_sales', v_monthly_sales,
        'total_products', v_total_products,
        'total_customers', v_total_customers,
        'low_stock_count', v_low_stock_count
    );

    RETURN v_result;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ----------------------------
-- get_daily_report()
-- ----------------------------
CREATE OR REPLACE FUNCTION public.get_daily_report(p_owner_id UUID, p_date DATE)
RETURNS JSON AS $$
DECLARE
    v_result JSON;
    v_summary JSON;
    v_hourly JSON;
BEGIN
    SELECT json_build_object(
        'total_sales', COALESCE(SUM(s.total), 0),
        'total_transactions', COUNT(*),
        'total_items', (
            SELECT COALESCE(SUM(si.quantity), 0)
            FROM public.sale_items si
            JOIN public.sales s2 ON si.sale_id = s2.id
            WHERE s2.owner_id = p_owner_id
              AND s2.created_at::date = p_date
              AND s2.payment_status != 'cancelled'
        ),
        'avg_transaction', CASE WHEN COUNT(*) > 0 THEN ROUND(SUM(s.total) / COUNT(*), 2) ELSE 0 END,
        'cash_sales', COALESCE(SUM(CASE WHEN s.payment_method = 'cash' THEN s.total ELSE 0 END), 0),
        'card_sales', COALESCE(SUM(CASE WHEN s.payment_method = 'card' THEN s.total ELSE 0 END), 0),
        'transfer_sales', COALESCE(SUM(CASE WHEN s.payment_method = 'transfer' THEN s.total ELSE 0 END), 0),
        'qris_sales', COALESCE(SUM(CASE WHEN s.payment_method = 'qris' THEN s.total ELSE 0 END), 0),
        'total_discount', COALESCE(SUM(s.discount_amount), 0),
        'total_vat', COALESCE(SUM(s.vat_amount), 0)
    ) INTO v_summary
    FROM public.sales s
    WHERE s.owner_id = p_owner_id
      AND s.created_at::date = p_date
      AND s.payment_status != 'cancelled';

    SELECT COALESCE(json_agg(row_to_json(h)), '[]'::JSON) INTO v_hourly
    FROM (
        SELECT
            EXTRACT(HOUR FROM s.created_at)::INT AS hour,
            COALESCE(SUM(s.total), 0) AS sales,
            COUNT(*) AS transactions
        FROM public.sales s
        WHERE s.owner_id = p_owner_id
          AND s.created_at::date = p_date
          AND s.payment_status != 'cancelled'
        GROUP BY EXTRACT(HOUR FROM s.created_at)
        ORDER BY EXTRACT(HOUR FROM s.created_at)
    ) h;

    v_result := json_build_object(
        'summary', v_summary,
        'hourly', v_hourly,
        'report_date', p_date
    );

    RETURN v_result;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ----------------------------
-- get_monthly_report()
-- ----------------------------
CREATE OR REPLACE FUNCTION public.get_monthly_report(p_owner_id UUID, p_year INT, p_month INT)
RETURNS JSON AS $$
DECLARE
    v_result JSON;
    v_summary JSON;
    v_daily JSON;
    v_top_products JSON;
    v_month_start DATE;
    v_month_end DATE;
BEGIN
    v_month_start := make_date(p_year, p_month, 1);
    v_month_end := (v_month_start + INTERVAL '1 month - 1 day')::DATE;

    SELECT json_build_object(
        'total_sales', COALESCE(SUM(s.total), 0),
        'total_transactions', COUNT(*),
        'total_items', (
            SELECT COALESCE(SUM(si.quantity), 0)
            FROM public.sale_items si
            JOIN public.sales s2 ON si.sale_id = s2.id
            WHERE s2.owner_id = p_owner_id
              AND s2.created_at::date BETWEEN v_month_start AND v_month_end
              AND s2.payment_status != 'cancelled'
        ),
        'avg_transaction', CASE WHEN COUNT(*) > 0 THEN ROUND(SUM(s.total) / COUNT(*), 2) ELSE 0 END,
        'total_discount', COALESCE(SUM(s.discount_amount), 0),
        'total_vat', COALESCE(SUM(s.vat_amount), 0),
        'total_returns', (
            SELECT COALESCE(SUM(r.total_amount), 0)
            FROM public.returns r
            WHERE r.owner_id = p_owner_id
              AND r.created_at::date BETWEEN v_month_start AND v_month_end
              AND r.status = 'completed'
        )
    ) INTO v_summary
    FROM public.sales s
    WHERE s.owner_id = p_owner_id
      AND s.created_at::date BETWEEN v_month_start AND v_month_end
      AND s.payment_status != 'cancelled';

    SELECT COALESCE(json_agg(row_to_json(d)), '[]'::JSON) INTO v_daily
    FROM (
        SELECT
            s.created_at::DATE AS day,
            COALESCE(SUM(s.total), 0) AS sales,
            COUNT(*) AS transactions
        FROM public.sales s
        WHERE s.owner_id = p_owner_id
          AND s.created_at::date BETWEEN v_month_start AND v_month_end
          AND s.payment_status != 'cancelled'
        GROUP BY s.created_at::DATE
        ORDER BY s.created_at::DATE
    ) d;

    SELECT COALESCE(json_agg(row_to_json(tp)), '[]'::JSON) INTO v_top_products
    FROM (
        SELECT
            si.product_id,
            si.product_name,
            SUM(si.quantity) AS total_quantity,
            SUM(si.total_price) AS total_revenue
        FROM public.sale_items si
        JOIN public.sales s ON si.sale_id = s.id
        WHERE s.owner_id = p_owner_id
          AND s.created_at::date BETWEEN v_month_start AND v_month_end
          AND s.payment_status != 'cancelled'
        GROUP BY si.product_id, si.product_name
        ORDER BY total_revenue DESC
        LIMIT 10
    ) tp;

    v_result := json_build_object(
        'summary', v_summary,
        'daily', v_daily,
        'top_products', v_top_products,
        'year', p_year,
        'month', p_month
    );

    RETURN v_result;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ----------------------------
-- get_top_products()
-- ----------------------------
CREATE OR REPLACE FUNCTION public.get_top_products(p_owner_id UUID, p_limit INT DEFAULT 10)
RETURNS JSON AS $$
DECLARE
    v_result JSON;
BEGIN
    SELECT COALESCE(json_agg(row_to_json(tp)), '[]'::JSON) INTO v_result
    FROM (
        SELECT
            si.product_id,
            si.product_name,
            SUM(si.quantity) AS total_quantity_sold,
            SUM(si.total_price) AS total_revenue,
            COUNT(DISTINCT si.sale_id) AS total_transactions,
            ROUND(AVG(si.unit_price), 2) AS avg_selling_price
        FROM public.sale_items si
        JOIN public.sales s ON si.sale_id = s.id
        JOIN public.products p ON si.product_id = p.id
        WHERE s.owner_id = p_owner_id
          AND s.payment_status != 'cancelled'
        GROUP BY si.product_id, si.product_name
        ORDER BY total_revenue DESC
        LIMIT p_limit
    ) tp;

    RETURN v_result;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ----------------------------
-- get_payment_methods_report()
-- ----------------------------
CREATE OR REPLACE FUNCTION public.get_payment_methods_report(p_owner_id UUID)
RETURNS JSON AS $$
DECLARE
    v_result JSON;
    v_methods JSON;
    v_total NUMERIC;
BEGIN
    SELECT COALESCE(SUM(s.total), 0) INTO v_total
    FROM public.sales s
    WHERE s.owner_id = p_owner_id
      AND s.payment_status != 'cancelled';

    SELECT COALESCE(json_agg(row_to_json(pm)), '[]'::JSON) INTO v_methods
    FROM (
        SELECT
            s.payment_method,
            COALESCE(SUM(s.total), 0) AS total_amount,
            COUNT(*) AS transaction_count,
            CASE
                WHEN v_total > 0
                THEN ROUND((SUM(s.total) / v_total) * 100, 2)
                ELSE 0
            END AS percentage
        FROM public.sales s
        WHERE s.owner_id = p_owner_id
          AND s.payment_status != 'cancelled'
        GROUP BY s.payment_method
        ORDER BY total_amount DESC
    ) pm;

    v_result := json_build_object(
        'methods', v_methods,
        'total', v_total
    );

    RETURN v_result;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ----------------------------
-- get_category_report()
-- ----------------------------
CREATE OR REPLACE FUNCTION public.get_category_report(p_owner_id UUID)
RETURNS JSON AS $$
DECLARE
    v_result JSON;
    v_categories JSON;
    v_total NUMERIC;
BEGIN
    SELECT COALESCE(SUM(si.total_price), 0) INTO v_total
    FROM public.sale_items si
    JOIN public.sales s ON si.sale_id = s.id
    JOIN public.products p ON si.product_id = p.id
    WHERE s.owner_id = p_owner_id
      AND s.payment_status != 'cancelled';

    SELECT COALESCE(json_agg(row_to_json(cr)), '[]'::JSON) INTO v_categories
    FROM (
        SELECT
            COALESCE(c.id, 'uncategorized'::UUID) AS category_id,
            COALESCE(c.name, 'Uncategorized') AS category_name,
            SUM(si.quantity) AS total_quantity,
            SUM(si.total_price) AS total_revenue,
            CASE
                WHEN v_total > 0
                THEN ROUND((SUM(si.total_price) / v_total) * 100, 2)
                ELSE 0
            END AS percentage
        FROM public.sale_items si
        JOIN public.sales s ON si.sale_id = s.id
        JOIN public.products p ON si.product_id = p.id
        LEFT JOIN public.categories c ON p.category_id = c.id
        WHERE s.owner_id = p_owner_id
          AND s.payment_status != 'cancelled'
        GROUP BY c.id, c.name
        ORDER BY total_revenue DESC
    ) cr;

    v_result := json_build_object(
        'categories', v_categories,
        'total', v_total
    );

    RETURN v_result;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ----------------------------
-- get_monthly_sales_chart()
-- ----------------------------
CREATE OR REPLACE FUNCTION public.get_monthly_sales_chart(p_owner_id UUID, p_year INT)
RETURNS JSON AS $$
DECLARE
    v_result JSON;
BEGIN
    SELECT COALESCE(json_agg(row_to_json(ms)), '[]'::JSON) INTO v_result
    FROM (
        SELECT
            EXTRACT(MONTH FROM s.created_at)::INT AS month,
            TO_CHAR(s.created_at, 'Mon') AS month_name,
            COALESCE(SUM(s.total), 0) AS total_sales,
            COUNT(*) AS transactions
        FROM public.sales s
        WHERE s.owner_id = p_owner_id
          AND EXTRACT(YEAR FROM s.created_at) = p_year
          AND s.payment_status != 'cancelled'
        GROUP BY EXTRACT(MONTH FROM s.created_at), TO_CHAR(s.created_at, 'Mon')
        ORDER BY EXTRACT(MONTH FROM s.created_at)
    ) ms;

    RETURN v_result;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ----------------------------
-- adjust_stock()
-- ----------------------------
CREATE OR REPLACE FUNCTION public.adjust_stock(
    p_product_id UUID,
    p_quantity NUMERIC,
    p_type TEXT,
    p_note TEXT DEFAULT NULL
)
RETURNS BOOLEAN AS $$
DECLARE
    v_owner_id UUID;
    v_user_id UUID;
BEGIN
    v_owner_id := public.get_owner_id();
    v_user_id := auth.uid();

    UPDATE public.products
    SET stock = GREATEST(0, stock + p_quantity)
    WHERE id = p_product_id;

    INSERT INTO public.stock_history (
        product_id, quantity_change, type, note, user_id, owner_id
    ) VALUES (
        p_product_id, p_quantity, p_type,
        COALESCE(p_note, 'Manual adjustment'),
        v_user_id, v_owner_id
    );

    RETURN TRUE;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ============================================================================
-- 7. SEED DATA: Initial roles, permissions, categories, settings
-- ============================================================================

-- ----------------------------
-- Roles
-- ----------------------------
INSERT INTO public.roles (name, slug, description, status) VALUES
    ('Super Admin', 'admin', 'Full system access with no restrictions', 'active'),
    ('Owner', 'owner', 'Business owner with full access to their data', 'active'),
    ('Manager', 'manager', 'Store manager with most permissions', 'active'),
    ('Cashier', 'cashier', 'Point of sale operator', 'active'),
    ('Warehouse', 'warehouse', 'Warehouse/stock management staff', 'active')
ON CONFLICT (slug) DO NOTHING;

-- ----------------------------
-- Role Permissions
-- ----------------------------
-- Admin (super admin - bypasses RLS via is_super_admin())
INSERT INTO public.role_permissions (role_slug, permission) VALUES
    ('admin', 'dashboard.view'),
    ('admin', 'products.view'),
    ('admin', 'products.create'),
    ('admin', 'products.edit'),
    ('admin', 'products.delete'),
    ('admin', 'categories.view'),
    ('admin', 'categories.create'),
    ('admin', 'categories.edit'),
    ('admin', 'categories.delete'),
    ('admin', 'sales.view'),
    ('admin', 'sales.create'),
    ('admin', 'sales.void'),
    ('admin', 'returns.view'),
    ('admin', 'returns.create'),
    ('admin', 'customers.view'),
    ('admin', 'customers.create'),
    ('admin', 'customers.edit'),
    ('admin', 'customers.delete'),
    ('admin', 'stores.view'),
    ('admin', 'stores.create'),
    ('admin', 'stores.edit'),
    ('admin', 'stores.delete'),
    ('admin', 'transfers.view'),
    ('admin', 'transfers.create'),
    ('admin', 'transfers.approve'),
    ('admin', 'reports.view'),
    ('admin', 'settings.view'),
    ('admin', 'settings.edit'),
    ('admin', 'users.view'),
    ('admin', 'users.create'),
    ('admin', 'users.edit'),
    ('admin', 'users.delete'),
    ('admin', 'stocks.view'),
    ('admin', 'stocks.adjust'),
    ('admin', 'stocks.history')
ON CONFLICT (role_slug, permission) DO NOTHING;

-- Owner
INSERT INTO public.role_permissions (role_slug, permission) VALUES
    ('owner', 'dashboard.view'),
    ('owner', 'products.view'),
    ('owner', 'products.create'),
    ('owner', 'products.edit'),
    ('owner', 'products.delete'),
    ('owner', 'categories.view'),
    ('owner', 'categories.create'),
    ('owner', 'categories.edit'),
    ('owner', 'categories.delete'),
    ('owner', 'sales.view'),
    ('owner', 'sales.create'),
    ('owner', 'sales.void'),
    ('owner', 'returns.view'),
    ('owner', 'returns.create'),
    ('owner', 'customers.view'),
    ('owner', 'customers.create'),
    ('owner', 'customers.edit'),
    ('owner', 'customers.delete'),
    ('owner', 'stores.view'),
    ('owner', 'stores.create'),
    ('owner', 'stores.edit'),
    ('owner', 'stores.delete'),
    ('owner', 'transfers.view'),
    ('owner', 'transfers.create'),
    ('owner', 'transfers.approve'),
    ('owner', 'reports.view'),
    ('owner', 'settings.view'),
    ('owner', 'settings.edit'),
    ('owner', 'users.view'),
    ('owner', 'users.create'),
    ('owner', 'users.edit'),
    ('owner', 'users.delete'),
    ('owner', 'stocks.view'),
    ('owner', 'stocks.adjust'),
    ('owner', 'stocks.history')
ON CONFLICT (role_slug, permission) DO NOTHING;

-- Manager
INSERT INTO public.role_permissions (role_slug, permission) VALUES
    ('manager', 'dashboard.view'),
    ('manager', 'products.view'),
    ('manager', 'products.create'),
    ('manager', 'products.edit'),
    ('manager', 'categories.view'),
    ('manager', 'sales.view'),
    ('manager', 'sales.create'),
    ('manager', 'sales.void'),
    ('manager', 'returns.view'),
    ('manager', 'returns.create'),
    ('manager', 'customers.view'),
    ('manager', 'customers.create'),
    ('manager', 'customers.edit'),
    ('manager', 'stores.view'),
    ('manager', 'transfers.view'),
    ('manager', 'transfers.create'),
    ('manager', 'reports.view'),
    ('manager', 'stocks.view'),
    ('manager', 'stocks.adjust'),
    ('manager', 'stocks.history')
ON CONFLICT (role_slug, permission) DO NOTHING;

-- Cashier
INSERT INTO public.role_permissions (role_slug, permission) VALUES
    ('cashier', 'dashboard.view'),
    ('cashier', 'products.view'),
    ('cashier', 'sales.view'),
    ('cashier', 'sales.create'),
    ('cashier', 'returns.view'),
    ('cashier', 'returns.create'),
    ('cashier', 'customers.view'),
    ('cashier', 'customers.create')
ON CONFLICT (role_slug, permission) DO NOTHING;

-- Warehouse
INSERT INTO public.role_permissions (role_slug, permission) VALUES
    ('warehouse', 'dashboard.view'),
    ('warehouse', 'products.view'),
    ('warehouse', 'products.create'),
    ('warehouse', 'products.edit'),
    ('warehouse', 'categories.view'),
    ('warehouse', 'stores.view'),
    ('warehouse', 'transfers.view'),
    ('warehouse', 'transfers.create'),
    ('warehouse', 'transfers.approve'),
    ('warehouse', 'stocks.view'),
    ('warehouse', 'stocks.adjust'),
    ('warehouse', 'stocks.history')
ON CONFLICT (role_slug, permission) DO NOTHING;

-- ----------------------------
-- Default Categories
-- ----------------------------
INSERT INTO public.categories (name, description, status) VALUES
    ('General', 'General purpose products', 'active'),
    ('Food & Beverages', 'Food and beverage items', 'active'),
    ('Electronics', 'Electronic devices and accessories', 'active'),
    ('Clothing', 'Apparel and fashion items', 'active'),
    ('Health & Beauty', 'Health and beauty products', 'active'),
    ('Household', 'Household and cleaning supplies', 'active'),
    ('Stationery', 'Office and school supplies', 'active'),
    ('Sports & Outdoor', 'Sports and outdoor equipment', 'active'),
    ('Automotive', 'Automotive parts and accessories', 'active'),
    ('Others', 'Other miscellaneous products', 'active')
ON CONFLICT DO NOTHING;

-- ----------------------------
-- Default Customer (Walk-in)
-- ----------------------------
INSERT INTO public.customers (name, phone, email, address) VALUES
    ('Walk-in Customer', '', '', '')
ON CONFLICT DO NOTHING;

-- ----------------------------
-- Default System Settings
-- ----------------------------
INSERT INTO public.system_settings (setting_key, setting_value) VALUES
    ('store_name', 'SMART POS'),
    ('store_address', ''),
    ('store_phone', ''),
    ('currency', 'IDR'),
    ('currency_symbol', 'Rp'),
    ('tax_enabled', 'true'),
    ('tax_percent', '11'),
    ('receipt_header', 'Thank you for your purchase!'),
    ('receipt_footer', 'Returns accepted within 7 days with receipt.'),
    ('low_stock_threshold', '10'),
    ('invoice_prefix', 'INV'),
    ('return_prefix', 'RET'),
    ('transfer_prefix', 'TRF'),
    ('date_format', 'YYYY-MM-DD'),
    ('time_format', 'HH:mm:ss')
ON CONFLICT (setting_key) DO NOTHING;

-- ============================================================================
-- SCHEMA COMPLETE
-- ============================================================================
