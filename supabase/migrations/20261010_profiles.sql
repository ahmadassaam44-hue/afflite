-- ============================================================================
-- جدول بيانات الحسابات (مرتبط بـ Supabase Auth)
-- شغّله مرة واحدة من Supabase Dashboard → SQL Editor
-- ============================================================================

create table if not exists public.profiles (
  id          uuid primary key references auth.users(id) on delete cascade,
  email       text not null unique,
  data        jsonb not null default '{}'::jsonb,
  updated_at  timestamptz not null default now()
);

-- تحديث updated_at تلقائياً مع كل تعديل (بيُستخدم للتحكم في التزامن من السيرفر)
create or replace function public.profiles_touch()
returns trigger
language plpgsql
as $$
begin
  new.updated_at := clock_timestamp();
  return new;
end;
$$;

drop trigger if exists profiles_touch on public.profiles;
create trigger profiles_touch
  before update on public.profiles
  for each row execute function public.profiles_touch();

-- الجدول مقفول: مفيش سياسات، يعني المفتاح العام (anon) والمستخدمين مش بيوصلوه.
-- السيرفر بيوصله بمفتاح الخدمة (service_role) اللي بيتجاوز RLS.
alter table public.profiles enable row level security;
