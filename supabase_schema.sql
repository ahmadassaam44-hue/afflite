-- ============================================================================
-- WAFIRINHALK (وفرنهالك) - SUPABASE POSTGRESQL SCHEMA, TRIGGERS, RPC & RLS
-- ============================================================================

create extension if not exists "pgcrypto";

-- 1. جدول الأحداث (events) - قلب نظام التحليلات
create table if not exists public.events (
    id uuid primary key default gen_random_uuid(),
    type text not null check (type in ('page_view', 'product_view', 'add_to_cart', 'like', 'unlike', 'checkout', 'order', 'aff_click')),
    product_id text,
    marketer_id text,
    visitor_id text not null,
    user_id text,
    session_id text,
    referrer text,
    utm_source text,
    device text,
    browser text,
    ip_hash text,
    country text default 'EG',
    created_at timestamptz not null default now()
);

create index if not exists idx_events_created_at on public.events (created_at desc);
create index if not exists idx_events_type_date on public.events (type, created_at desc);
create index if not exists idx_events_product on public.events (product_id) where product_id is not null;
create index if not exists idx_events_marketer on public.events (marketer_id) where marketer_id is not null;
create index if not exists idx_events_visitor on public.events (visitor_id);

-- 2. جدول المنتجات مع العدادات الجاهزة (Counters) لتجنب COUNT(*) الثقيل
create table if not exists public.products (
    uid text primary key,
    plat text not null,
    id text not null,
    name text not null,
    category text,
    price_num numeric(12,2) default 0,
    orig_suggested numeric(12,2) default 0,
    commission numeric(12,2) default 0,
    stock integer default 100,
    likes_count integer not null default 0,
    views_count integer not null default 0,
    cart_adds_count integer not null default 0,
    orders_count integer not null default 0,
    source_status text default 'active',
    updated_at timestamptz default now()
);

-- 3. جدول اللايكات (likes) مع Unique Constraint لمنع التكرار لنفس الشخص
create table if not exists public.likes (
    id uuid primary key default gen_random_uuid(),
    visitor_id text not null,
    user_id text,
    product_id text not null,
    created_at timestamptz not null default now(),
    constraint uq_likes_visitor_product unique (visitor_id, product_id)
);

-- Trigger لتحديث likes_count في جدول products تلقائياً
create or replace function public.fn_sync_product_likes_count()
returns trigger language plpgsql security definer as $$
begin
    if (TG_OP = 'INSERT') then
        update public.products set likes_count = likes_count + 1 where uid = NEW.product_id or id = NEW.product_id;
        return NEW;
    elsif (TG_OP = 'DELETE') then
        update public.products set likes_count = greatest(0, likes_count - 1) where uid = OLD.product_id or id = OLD.product_id;
        return OLD;
    end if;
    return null;
end;
$$;

drop trigger if exists trg_sync_product_likes on public.likes;
create trigger trg_sync_product_likes
after insert or delete on public.likes
for each row execute function public.fn_sync_product_likes_count();

-- 4. جدول السلات (carts & cart_items)
create table if not exists public.carts (
    id uuid primary key default gen_random_uuid(),
    visitor_id text not null unique,
    user_id text,
    updated_at timestamptz not null default now()
);

create table if not exists public.cart_items (
    id uuid primary key default gen_random_uuid(),
    cart_id uuid not null references public.carts(id) on delete cascade,
    product_id text not null,
    pname text,
    variant text default '',
    qty integer not null default 1,
    unit_price numeric(12,2) not null default 0,
    created_at timestamptz not null default now()
);

-- 5. جدول المسوقين (marketers)
create table if not exists public.marketers (
    id uuid primary key default gen_random_uuid(),
    email text not null unique,
    name text not null,
    phone text,
    ref_code text not null unique,
    referred_by text,
    status text not null default 'approved' check (status in ('pending', 'approved', 'suspended')),
    custom_comm_pct numeric(5,2) default 0,
    clicks_count integer not null default 0,
    orders_count integer not null default 0,
    pending_commission numeric(12,2) not null default 0,
    confirmed_commission numeric(12,2) not null default 0,
    paid_commission numeric(12,2) not null default 0,
    created_at timestamptz not null default now()
);

-- 6. جدول الأوردرات والعمولات وطلبات السحب
create table if not exists public.orders (
    id uuid primary key default gen_random_uuid(),
    order_no serial,
    product_id text not null,
    pname text,
    plat text,
    variant text,
    qty integer not null default 1,
    unit_price numeric(12,2) not null default 0,
    ship_fee numeric(12,2) not null default 0,
    total numeric(12,2) not null default 0,
    customer_name text not null,
    customer_phone text not null,
    city text,
    address text,
    marketer_ref text,
    visitor_id text,
    status text not null default 'جديد',
    source_sync_status text not null default 'pending',
    source_sync_attempts integer not null default 0,
    source_last_error text,
    created_at timestamptz not null default now()
);

create table if not exists public.commissions (
    id uuid primary key default gen_random_uuid(),
    order_id uuid references public.orders(id) on delete cascade,
    marketer_ref text not null,
    amount numeric(12,2) not null default 0,
    type text not null default 'direct' check (type in ('direct', 'team_referral', 'bonus')),
    status text not null default 'pending' check (status in ('pending', 'confirmed', 'paid', 'cancelled')),
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);

create table if not exists public.payouts (
    id uuid primary key default gen_random_uuid(),
    marketer_email text not null,
    marketer_ref text,
    amount numeric(12,2) not null,
    method text not null,
    account_number text not null,
    status text not null default 'قيد المراجعة' check (status in ('قيد المراجعة', 'موافق عليه', 'مرفوض')),
    transfer_ref text,
    processed_by text,
    created_at timestamptz not null default now(),
    processed_at timestamptz
);

-- 7. جدول الإحصائيات اليومية المجمعة (daily_stats) لتسريع الداشبورد
create table if not exists public.daily_stats (
    day date primary key,
    unique_visitors integer not null default 0,
    page_views integer not null default 0,
    product_views integer not null default 0,
    likes_count integer not null default 0,
    cart_adds integer not null default 0,
    checkouts integer not null default 0,
    orders_count integer not null default 0,
    revenue numeric(14,2) not null default 0,
    commissions numeric(14,2) not null default 0,
    updated_at timestamptz not null default now()
);

-- 8. جدول الحماية من الغش (fraud_alerts) وسجل النشاط الحساس (audit_logs)
create table if not exists public.fraud_alerts (
    id uuid primary key default gen_random_uuid(),
    type text not null,
    severity text not null default 'medium' check (severity in ('low', 'medium', 'high', 'critical')),
    marketer_ref text,
    visitor_id text,
    ip_address text,
    details text,
    resolved boolean not null default false,
    created_at timestamptz not null default now()
);

create table if not exists public.audit_logs (
    id uuid primary key default gen_random_uuid(),
    actor_email text not null,
    actor_role text not null default 'super_admin',
    action text not null,
    target text,
    details jsonb,
    ip_address text,
    created_at timestamptz not null default now()
);

-- 9. RPC Function لتسجيل الأحداث وزيادة العدادات بأمان (منع التلاعب من الكلاينت)
create or replace function public.track_event_rpc(
    p_type text,
    p_visitor_id text,
    p_product_id text default null,
    p_marketer_id text default null,
    p_user_id text default null,
    p_session_id text default null,
    p_referrer text default null,
    p_utm_source text default null,
    p_device text default null
) returns jsonb language plpgsql security definer as $$
declare
    v_today date := current_date;
begin
    insert into public.events (type, product_id, marketer_id, visitor_id, user_id, session_id, referrer, utm_source, device)
    values (p_type, p_product_id, p_marketer_id, p_visitor_id, p_user_id, p_session_id, p_referrer, p_utm_source, p_device);

    insert into public.daily_stats (day, page_views, product_views, cart_adds, checkouts, orders_count)
    values (
        v_today,
        case when p_type = 'page_view' then 1 else 0 end,
        case when p_type = 'product_view' then 1 else 0 end,
        case when p_type = 'add_to_cart' then 1 else 0 end,
        case when p_type = 'checkout' then 1 else 0 end,
        case when p_type = 'order' then 1 else 0 end
    )
    on conflict (day) do update set
        page_views = public.daily_stats.page_views + (case when p_type = 'page_view' then 1 else 0 end),
        product_views = public.daily_stats.product_views + (case when p_type = 'product_view' then 1 else 0 end),
        cart_adds = public.daily_stats.cart_adds + (case when p_type = 'add_to_cart' then 1 else 0 end),
        checkouts = public.daily_stats.checkouts + (case when p_type = 'checkout' then 1 else 0 end),
        orders_count = public.daily_stats.orders_count + (case when p_type = 'order' then 1 else 0 end),
        updated_at = now();

    if p_product_id is not null then
        if p_type = 'product_view' then
            update public.products set views_count = views_count + 1 where uid = p_product_id or id = p_product_id;
        elsif p_type = 'add_to_cart' then
            update public.products set cart_adds_count = cart_adds_count + 1 where uid = p_product_id or id = p_product_id;
        elsif p_type = 'order' then
            update public.products set orders_count = orders_count + 1 where uid = p_product_id or id = p_product_id;
        end if;
    end if;

    return jsonb_build_object('ok', true);
end;
$$;
