# إعداد الحسابات على Supabase

الحسابات بتتسجل في Supabase Auth، وبيانات الحساب (الدور، الرصيد، الموبايل ...) في جدول `public.profiles`.

## 1) الجدول
شغّل `migrations/20261010_profiles.sql` من Supabase Dashboard → SQL Editor.

## 2) مفتاح الخدمة على الاستضافة
في `secrets.php` (مستبعد من Git، ومحجوب من المتصفح):

```php
define('SB_SERVICE', '...service_role key...');   // مفتاح service_role من Project Settings → API
```

ماتحطوش المفتاح ده في الشات ولا في الكود، ولا في أي ملف ممكن يتعمله commit.

## 3) قفل التسجيل العام
من Supabase Dashboard → Authentication → Sign In / Providers → Email:
- **Allow new users to sign up: OFF**

ده بيمنع أي حد يعمل حساب Auth من غير ما يمر على الموقع (الموقع بيعمل الحسابات بمفتاح الخدمة).

## 4) تشغيل الإيميلات
رسائل استعادة كلمة السر بتتبعت من دالة `send-mail` (انظر `functions/send-mail/README.md`).
