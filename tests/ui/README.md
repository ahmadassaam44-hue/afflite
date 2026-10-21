# UI regression checks

Run from the repository root. Production remains plain PHP, CSS and Vanilla JS; these are optional development checks, **not a hosting build step**.

```bash
# PHP on a normal development machine
find . -maxdepth 1 -name '*.php' -print0 | xargs -0 -n1 php -l
find dashboard -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/ui.js

# Optional test dependencies remain outside Git
npm install --prefix .cache/ui-tools playwright axe-core postcss php-parser
node tests/ui/backend-contracts.cjs
node tests/ui/static-audit.cjs
```

The completed browser checks used isolated current/baseline sites on ports 8080/8081, real PHP 8.3 through php-wasm, and deliberately synthetic users/orders under `.cache/`. No fixtures were written to production data. `matrix.cjs` checks 54 views at six widths; `flows.cjs` checks request fields, CSRF/ref, drawers, modals, cart/checkout and cancel behaviour.

Before using these browser scripts, prepare equivalent local test fixtures and the test-only `_ui_fixture_login.php` in a copied document root, **never in production**. Set the Chromium executable path appropriate to your machine instead of `/tmp/chromium`. External images/analytics were blocked in this sandbox; actual hosting Lighthouse scores require a separate post-deployment audit.

Saved results: `docs/ui/validation-results.json`. Backend comparison base: `5abb787`, configurable with `UI_BASE_REF`. No business/security functions may change during a UI-only update.

## 2026-10-03 cleanup / white-page regressions

With the **local isolated** fixture site ready:

```bash
npm install --prefix .cache/ui-tools playwright axe-core php-parser
UI_BASE_REF=1548bec UI_RUNTIME_FIXES=1 node tests/ui/backend-contracts.cjs
# Set CHROMIUM_PATH / LD_LIBRARY_PATH if your local Chromium needs them.
node tests/ui/stability.cjs
```

`UI_RUNTIME_FIXES=1` explicitly allows only the reviewed `jload` / `find_product_by_id` memory changes; all other non-UI functions, payout logic and monetary constants still require AST equality. Without this flag the original strict UI-only check remains intact.

The stability test refuses a non-local server URL and submits two **synthetic local** checkout orders. Prepare `_ui_fixture_login.php` as above and an isolated `_ui_fatal.php` containing `require __DIR__ . '/config.php';`, an optional `json=1` Content-Type header and `throw new RuntimeException('PRIVATE_DIAGNOSTIC_NOT_FOR_VISITORS');`. Never deploy either fixture file. The store fixture needs `MKT-TEST` with at least two category products (e.g. Vendoor 6029 / Angazny 2955). Keep the copied supplier/shipping JSON current.

Saved summary: `docs/ui/stability-2026-10-03.json`. The report distinguishes the reproduced 64M lookup fix from intentionally insufficient 32M homepage memory and does not claim that an output callback can recover every OOM.

## 2026-10-03 colour/size and portable commission links

The new functional request authorizes colour/size order metadata and correct landing-link arguments. It does **not** authorize changed pricing, commission caps, referral-credit policies, payouts or shipping rules. Compare unchanged config functions directly with the preceding deployment:

```bash
UI_BASE_REF=d921b95 node tests/ui/backend-contracts.cjs
```

A reproducible, synthetic local fixture builder/server is now included. Everything it creates stays in `.cache/`, which is excluded from Git and FTP:

```bash
npm install --prefix .cache/ui-tools @php-wasm/node playwright axe-core php-parser postcss
node tests/ui/local-fixtures.cjs
UI_FIXTURE_ROOT=.cache/variant-baseline UI_FIXTURE_BASE=d921b95 node tests/ui/local-fixtures.cjs
# Run each server in a separate terminal (or the Agent process tools).
node tests/ui/local-runtime.cjs
PORT=8081 UI_FIXTURE_ROOT=.cache/variant-baseline node tests/ui/local-runtime.cjs
# Supply your locally installed browser; in this sandbox Chromium also needed LD_LIBRARY_PATH.
CHROMIUM_PATH=/path/to/chromium node tests/ui/variants.cjs
CHROMIUM_PATH=/path/to/chromium node tests/ui/stability.cjs
```

The WASM server was tested with `@php-wasm/node` 3.1.54 / PHP 8.3.33. It binds `0.0.0.0`, accepts preview hosts and forwards their actual host/protocol to PHP. `cookieStore:false` prevents separate browser contexts from sharing server-side test cookies. The builder refuses a root outside this repository's `.cache/`; the order tests refuse non-local server URLs. Do **not** upload or use `_ui_fixture_login.php`, `_ui_probe.php`, `_ui_fatal.php`, test accounts or copied JSON on hosting. `variants.cjs` reproduces three bugs against the old local copy, then verifies the repaired flows against the current copy; set `UI_TEST_BASELINE_URL=skip` when a baseline server isn't available.

Results and limitations are summarized in `docs/ui/variants-2026-10-03.md` and `.json`. This is not a production build step or an authenticated production test. The legacy CSV-after-shell issue is not repaired here; coverage checks that the selected values are in export payloads, not that every historical CSV formatting defect is fixed.

## Affiliate catalogue / M5zoon / bottom drawer tools

```bash
# Prepare the copied .cache/site using local-fixtures.cjs, then start local-runtime.cjs.
UI_BASE_REF=c735c65 node tests/ui/backend-contracts.cjs
CHROMIUM_PATH=/path/to/chromium node tests/ui/catalogue.cjs
```

`catalogue.cjs` requires a localhost URL and a copied `.cache/` site. It augments only that copy with synthetic M5zoon products / sales / views and a readonly expected-order helper; it compares filters/sort/pagination against the unchanged homepage collection rules. Filtering/navigation must make zero POSTs; one synthetic saved-commission POST checks the original marketer endpoint. It also checks the removed store box, exact single bottom links, no-JS GET forms, and six-width Axe/overflow coverage. Never upload the generated `_ui_catalog_expected.php` helper or any copied/private test data. Reset with `local-fixtures.cjs` before other suites; run mutating suites serially. Actual results/limits: `docs/ui/catalogue-2026-10-03.md` / `.json`.

## Source media / public navigation / product detail UX

```bash
UI_BASE_REF=0f9ca6d node tests/ui/backend-contracts.cjs
# With fresh local-fixtures.cjs / local-runtime.cjs copied site:
CHROMIUM_PATH=/path/to/chromium node tests/ui/product-experience.cjs
```

This local-only script adds media to synthetic `UI-CHOICES` records, generates only a copied-site media probe, and checks original Drive fields, invalid-host rejection, missing-link/no-JS fallback, public-login-link removal with auth handlers intact, hidden supplier controls with supplier data intact, gallery/focus/selection/quantity controls and guest/marketer radio/standard detail pages at six widths. Presentation interactions must send zero POSTs. Reset the copied site before other mutating suites. The catalogue test's supplier-selector/tab assertions now enforce their removal, as requested, while preserving its country/source/filter/sort/save/order checks. Results and limits: `docs/ui/product-experience-2026-10-03.md` / `.json`. No Drive API/upload endpoint or hosting build dependency was added.

## 2026-10-20 manual products per country / currency

`manual-country-currency.cjs` runs the real PHP 8.3 in WASM (no `php` CLI, no database) against
`config.php` + `manual_products_lib.php` + `ui-components.php`, so the country/currency helpers,
`manual_row_to_product`, `manual_validate_row`, `manual_details_json`, `manual_parse_csv` and the
repository's own `manual_products_import.csv` template are all executed, not just inspected. It adds
source-level checks that manual products stay inside the Gulf views (`physical_collection`,
`categories_by_mode`), that every country tab comes from `wf_country_list()`, that the admin form has
the country select + `mpSyncCurrency`, and it parses the changed PHP files with `php-parser`.
No production data is touched; the summary is written to `.cache/manual-country-currency.json`.

```bash
npm install --prefix .cache/ui-tools @php-wasm/node php-parser
node tests/ui/manual-country-currency.cjs   # 359 PHP assertions + wiring checks
```

Behaviour documented in `docs/manual-products-country-currency-2026-10-20.md`: the country is stored
in the existing `details` JSON, the currency is always derived from it (never stored, never converted),
legacy rows default to مصر/ج.م, and an unknown country in a form/CSV row is rejected with an explicit
message so a price can never be saved under the wrong currency.

The same script now also covers the owner decision of 2026-10-20 that the EGP-denominated quantity
discount (`LP_BUNDLE_DISCOUNT`) applies **only** to products whose price is in Egyptian pounds:
`wf_bulk_discount_plat_ok/wf_bulk_discount/wf_bundle_total/wf_bulk_discount_state` take an optional
currency (or product array), every server call site passes it, `wf_bulk_discount_state` ships
`egp_only`/`egp`/`currency` to the browser, and `assets/ui.js` refuses the live discount preview for
non-EGP products so the preview always matches the server-side order total.

Shipping is country-aware on the same script: it loads the repository's real `data/shipping.json`
into the WASM filesystem and asserts that manual products in مصر reuse the TimeHand governorate
table in EGP, while السعودية/الإمارات/الأردن/عُمان get their own region tables (13/7/12/11 regions,
no Egyptian governorates, fees zero until the owner fills them) in ر.س/د.إ/د.أ/ر.ع. It also renders
`render_easyorder_dialog_html('', 'manual', 'ر.س', 'السعودية')` and asserts on the produced HTML
(region options with the right currency, «المنطقة / المدينة», `wfShippingCurrency`, and no
«القاهرة»/ج.م shipping label), plus regressions for cezma→TimeHand, vendoor/safka/shopmart counts
and the unknown-platform fallback. Documented in `docs/manual-shipping-by-country-2026-10-20.md`.

Gulf city lists live in `data/shipping_cities_gulf.json` (major cities per region) and are read by
`wf_ship_cities_map($country)`; `wf_ship_governorates()` stays Egypt-only because `register.php` and
`marketer_complete.php` validate the account address against it, while the order dialog uses the new
merged `wf_ship_cities_map_all()`. The test asserts that every manual Gulf region has cities, that no
Gulf region name collides with an Egyptian governorate, and that the merged map keeps Cairo's 54 cities.

Stock (m5zoon) orders now resolve the **product's own country** on the same script: it asserts that
`site_ship_country_plats()` is `['manual','m5zoon']`, that `m5zoon` in مصر (or with no country, or with
an unsupported one) reuses the TimeHand governorate table, and that each Gulf country has its own
`m5zoon` region table — 13/7/12/11 regions, no Egyptian governorates, «المنطقة / المدينة» as the field
label, ر.س/د.إ/د.أ/ر.ع as the currency, region names identical to the manual tables, cities present for
every region, and every fee seeded with the country's unified price (25/25/3/2.5). It also parses the
rendered dialog HTML and checks that `wfShippingState`/`wfShippingCurrency` ship a
`manual:<country>`/`m5zoon:<country>` key per country, and on the data side that no country-less
`m5zoon` table survives, that row `5549` is الإمارات in both `data/m5zoon.json` and
`data/lite_m5zoon.json`, and that no stock row contradicts its own id suffix/currency.

The per-product shipping fee of manual products is covered too: `wf_product_shipping()` returns `null`
for missing/blank/text/negative input, `0.0` for an explicit zero (free shipping), rounds decimals and
clamps at 2000; the full cycle `manual_validate_row` → `manual_details_json` → stored row →
`manual_row_to_product` → `wf_product_shipping` keeps the value, and legacy rows stay `null` so they
fall back to the country/region table. Wiring checks require `ship_fee` in the four product payloads,
`$prod_ship`/`$ship_fixed`/`$lp_ship_fixed` on the server order paths, `eoSetShippingPlatform(platform,
country, productShip)` plus `e.ship_fee` in `assets/ui.js`, the `ship_fee` form field and CSV column,
and `_m5zoon_country()` (with no hardcoded `'country': 'مصر'` left inside `update_m5zoon()`).
Documented in `docs/stock-shipping-by-country-and-product-fee-2026-10-20.md`.

Every opener of the order dialog must carry the product country and its stored shipping fee, or the
modal silently falls back to the Egyptian governorate table: the product-page checkout button
(`openProductCheckoutDialog`), the related-product cards, the marketer store row button and the
marketer catalogue cards now pass `country`/`ship_fee` like the homepage/store/landing payloads do,
and the city-list placeholder («المنطقة» vs «المحافظة») follows the country in the dialog, the landing
inline form and `wfFillCityList`. The test also guards the deployment path: `site_deploy_files()` in
`updater/update_catalog.py` must list `data/shipping_cities_gulf.json`, otherwise the Gulf city
dropdowns can never reach the hosting even with correct code. Documented in
`docs/order-dialogs-per-country-and-deploy-sync-2026-10-21.md`.

The mixed-currency warnings (`wf_ui_currency_notice` / `wf_ui_shipping_currency_notice`) were removed
from every page by an owner decision on 2026-10-21 (after the dialog warning went in PR #5); the test
asserts they stay gone from `ui-components.php` and all former call sites, while the arithmetic that
refuses to sum different currencies (`wf_ui_currencies` / `data-checkout-mixed`) remains. Documented in
`docs/remove-currency-mix-notices-2026-10-21.md`.

Marketer notifications & order control (2026-10-21): `my_notifications($email, $role)` now also
returns entries addressed to the `marketers` group (`wf_notify_marketers()` broadcasts one entry for
every marketer — used by manual-product inserts and the catalogue updater's end-of-run new-product
diff). `wf_marketer_can_edit_order` / `wf_marketer_apply_order_edit` / `wf_marketer_cancel_order`
(let a marketer fix customer data/qty on his own pre-shipment orders, with the server recomputing
qty discount and total) and a soft cancel that keeps the order for the admin with status «ملغي» and
reverses its commission. `wf_admin_pending_counts()` feeds red sidebar badges (pending orders,
marketers registered in the last 7 days, payouts قيد المراجعة, pending product requests, support
threads whose last message is from the user). Documented in
`docs/marketer-notifications-order-edit-admin-counters-2026-10-21.md`.

Physical price cut & true latest products (2026-10-21): owner decision — every physical sale price
drops **12%** (`PRICE_GLOBAL_DISCOUNT = 0.12`), applied inside `wf_price_uplift()` (the single
funnel every `store_price()`/`display_price()`/order path ends in) after the 2% margin, so source
files stay untouched and hourly cron updates pick the cut up automatically. Manual products and
digital platforms (`g2g`/`social`/`smm`/`hevo`) are outside `wf_is_physical_plat()` and keep their
prices. «أحدث المنتجات» no longer trusts file order: the catalogue branch, the home window,
`cezma_recent_products()` and the marketer «الأحدث» sort now order by the `added_at` stamp
(desc; unstamped legacy rows keep reversed file order via PHP 8's stable usort). The updater gained
`stamp_new_products()`: it snapshots ids (including cezma shards) before the run, stamps `added_at`
on anything new, and feeds new cezma rows into the marketer notification broadcast. Documented in
`docs/physical-price-cut-and-true-latest-2026-10-21.md`.

Product-edit save failures & admin price semantics (2026-10-21): «تعذر الحفظ. راجع الاسم والسعر
والقسم وروابط الصور.» in the admin catalogue's "edit all details" form was rejecting the form's own
prefill — local detail images (`assets/detail/…`, merged from `data/detail_index.json` and embedded
in `vendoor.json`) fail the old `^(https?://|/)` regex, CDN image URLs containing commas were split
into invalid fragments by the line splitter, and a product's own non-canonical category (offered by
the dropdown) was rejected server-side. Fixes: `wf_image_url_allowed()` (http(s), `/…`, `assets/…`)
used by all three image validations; image lines split on newlines only; the current category from
`find_product_by_id()` stays acceptable; and the message now carries the specific rejection reason.
Money-safety fix alongside: an admin price override is now the **final** price
(`_price_override` + early return in `store_price()`) — previously the display price (margin + 12%
cut included) was prefilled and re-saved as the raw stored price, so every save re-applied uplift
and discount (~12% drop per save since the price-cut PR). Documented in
`docs/product-edit-save-fix-and-admin-price-2026-10-21.md`.
