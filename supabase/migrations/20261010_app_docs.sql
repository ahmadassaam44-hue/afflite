-- ============================================================================
-- جدول ملفات البيانات (document store): كل ملف JSON اتنقل من data/ بيبقى صف هنا
-- الاسم = اسم الملف (مثلاً orders.json)، والبيانات في عمود jsonb
-- شغّله بعد 20261010_profiles.sql (الـ workflow بيشغل كل الـ migrations بالترتيب)
-- ============================================================================

create table if not exists public.app_docs (
  name        text primary key,
  data        jsonb not null default 'null'::jsonb,
  updated_at  timestamptz not null default now()
);

-- تحديث updated_at تلقائياً مع كل تعديل (بيُستخدم للتحكم في التزامن من السيرفر)
create or replace function public.app_docs_touch()
returns trigger
language plpgsql
as $$
begin
  new.updated_at := clock_timestamp();
  return new;
end;
$$;

drop trigger if exists app_docs_touch on public.app_docs;
create trigger app_docs_touch
  before update on public.app_docs
  for each row execute function public.app_docs_touch();

-- مقفول: مفيش سياسات، والوصول بالـ service_role من السيرفر بس
alter table public.app_docs enable row level security;
