# حذف تنبيهات خلط العملات من الواجهة — 2026-10-21

قرار الإدارة (بعد حذف تحذير نافذة الطلب في `PR #5`): تنبيهات العملات التانية في الواجهة
مش مطلوبة خالص، فاتشالت كلها:

## اللي اتشال

1. **`wf_ui_currency_notice($rows)`** — «توجد أكثر من عملة (…). المبالغ معروضة بعملة كل منتج؛
   لا يتم تحويل العملات أو اعتبار مجموعها إجمالياً مالياً موحّداً.»
2. **`wf_ui_shipping_currency_notice($rows)`** — «عملة أحد المنتجات ليست الجنيه المصري، بينما
   رسوم الشحن والمحفظة مسجلة بالجنيه. لا تحوّل الواجهة العملات؛ راجع الإدارة قبل الاعتماد
   المالي لهذا الطلب.»

### أماكن الاستخدام اللي اتنضفت

| الملف | السطر القديم |
| --- | --- |
| `dashboard/admin.php` | `wf_ui_currency_notice($rev_orders)` |
| `dashboard/cart.php` | `wf_ui_currency_notice($cart)` |
| `dashboard/checkout_cart.php` | الاتنين معاً فوق فورم إتمام الطلب |
| `dashboard/marketer.php` | 3 أماكن (طلبات المسوق + عناصر متجره) |
| `dashboard/orders.php` | الاتنين معاً فوق جدول الطلبات |
| `ui-components.php` | تعريف الدالتين نفسه |

## اللي مترشش

- `wf_ui_currencies()` — لسه بتتحسب منها إجماليات السلة/الطلبات («عملات متعددة — راجع السطور»)
  وعلامة `data-checkout-mixed` اللي بتوقف جمع عملات مختلفة في رقم واحد. ده سلوك حسابي مش تنبيه.
- قاعدة «مفيش تحويل عملات في أي مكان» زي ما هي — اللي اتشال هو **التنبيهات** بس.
- ليبلات العملة جنب كل مبلغ (`wf_ui_currency` / `wf_ui_money`) شغالة عادي.

## الرجعة

`tests/ui/manual-country-currency.cjs` بيأكد إن `wf_ui_currency_notice` و`wf_ui_shipping_currency_notice`
مش موجودين في `ui-components.php` ولا في أي صفحة كانت بتندهم (فحص على المصدر)، فوق فحص
`eoCurrencyNotice` الموجود من `PR #5`.

```bash
npm install --prefix .cache/ui-tools @php-wasm/node php-parser
node tests/ui/manual-country-currency.cjs   # 308 assertion — 0 فشل
```
