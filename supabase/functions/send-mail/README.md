# send-mail (Supabase Edge Function)

بتبعت إيميلات الموقع (استعادة كلمة السر) عن طريق Resend. الكود في `index.ts`.

## 1) المفاتيح (Secrets) — تتحط في Supabase، مش في Git

من Supabase Dashboard: Project Settings → Edge Functions → Secrets (أو عن طريق CLI):

| الاسم | القيمة |
|---|---|
| `RESEND_API_KEY` | مفتاح Resend |
| `MAIL_FROM` | مثال: `وفرنهالك <no-reply@your-domain.com>` (الدومين لازم يكون متحقق منه في Resend) |
| `MAIL_SHARED_SECRET` | سلسلة عشوائية طويلة (32 حرف على الأقل) |

## 2) النشر

```
supabase functions deploy send-mail --project-ref hbhuxhphlpgwqynstytw
```

## 3) ربط الموقع

في ملف `secrets.php` على الاستضافة (مستبعد من Git ومحجوب من المتصفح):

```php
<?php
define('MAIL_FN_SECRET', 'نفس قيمة MAIL_SHARED_SECRET بالظبط');
```

الرابط نفسه متسجل في `config.php` (`MAIL_FN_URL`).

## اختبار سريع

بعد النشر والإعداد: من صفحة «نسيت كلمة السر» ابعت إيميل مسجل، ولو الإيميل ماوصلش شوف لوج Supabase (Edge Functions → Logs) ولوج السيرفر.
