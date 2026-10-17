# Sidebar store tools placement — 2026-10-03

## Requested correction

Place **أدوات المتجر** beneath **التسويق والمتجر**. Keep **أفلييت مخزون** and the visible **منصة الأفلييت** selector removed; do not delete supplier products or source IDs.

## Change

`dashboard/layout.php` now renders marketer sections in this order:

1. التسويق والمتجر — existing marketing links.
2. أدوات المتجر — one إدارة متجري link and one طلب توفير منتج link.
3. الحساب والأدوات — calculator/support and existing account tools.
4. Logout.

The store links are moved, not duplicated or retargeted. Active-page state, mobile drawer and desktop collapse remain. The requested supplier texts/selector were already absent in the preceding deployed version and remain absent; country/source/filter/shipping functionality is retained. No CSS, shared JS, pricing/authentication/persistence handler, data or workflow change.

## Checks actually run

- Changed `dashboard/layout.php` syntax lint: PHP 8.3.33 pass.
- Strict backend comparison against `3a60eaa`: **175 existing non-UI config functions unchanged**, monetary constants/payout handler unchanged; no exceptions.
- JS/CJS syntax, static CSS and whitespace checks pass.
- Updated `catalogue.cjs`: **11 groups pass**, explicitly asserting section/link order, one link per destination, active destinations and supplier UI absence, plus unchanged countries/global sorting/source/pagination/save/order/no-JS checks and 4 views × 6-width Axe/overflow coverage.
- Existing `flows.cjs`: **13 groups pass**, including mobile focus/scroll/Escape, desktop collapse, payout/admin cancel without POST, and actual local cart/order flows.

All accounts/orders/commission saves were synthetic, local copies under excluded `.cache/`. No production account/order/save test was performed. This is not an authenticated production UI or performance claim.

## Deployment

Code commit `09faae2e8c88560ff3cfe8b1dd1f3df92ebb2a17` pushed only to `arena/01a0fb2f-afflite`; no PR merge. Existing FTP workflow [37113680364](https://github.com/hamama44/afflite/actions/runs/37113680364), job `111176261524`, completed **2026-10-03T09:40:01Z** with run/job/FTP-step **success**. No authenticated production UI/checkout or individual-file FTP log audit was performed.
