# Affiliate UI cleanup and intermittent empty pages — 2026-10-03

## Requested UI changes

- Removed **لوحة التحكم الرئيسية** from affiliate navigation. Existing `/dashboard/` / `index.php` bookmarks now open the affiliate performance page.
- Removed the affiliate governorate shipping tab and its content. Legacy `mtab=shipping` renders performance; the standalone shipping page redirects affiliate accounts there. Administrator shipping configuration remains available.
- Removed displayed catalogue/store/category product totals and the product/photo/video KPI row. Order counts, purchase quantities, transaction amounts and variant choices remain intact. No catalogue records were deleted.
- Unknown or array-shaped affiliate/admin tab parameters now select a real default view instead of leaving the content area empty.
- Updated the shared CSS/JS cache version across standalone pages and the dashboard.

## Shipping verification

`data/shipping.json` is byte-for-byte unchanged from the previously deployed branch.

| Supplier | Existing Egyptian fees |
|---|---|
| Angazny | 80 / **120 EGP** |
| Time Hand | 80 / 90 / **95 EGP** |
| Safqa (`safka` in the shipping data) | 85 / **95** / 110 EGP |
| Vendoor | 80 EGP |

Angazny's 120 EGP regions are البحر الأحمر، الوادي الجديد، جنوب سيناء، مرسى مطروح، شمال سيناء. Time Hand's 95 EGP regions are الفيوم، المنيا، أسوان، أسيوط، بني سويف، الأقصر، قنا، سوهاج. Safqa also retains 95 EGP for several governorates.

The shared quick-order modal previously used `site_ship_map()` without the product's supplier, so it displayed Vendoor's table for other suppliers. It now selects the existing Egyptian supplier table when opening a product, including the `safka` alias. Landing/cart server resolvers, shipping data and order handlers were not rewritten. The existing non-EGP accounting warning/behaviour is unchanged.

Local isolated orders verified **120 EGP** for Angazny / البحر الأحمر and **95 EGP** for Time Hand / أسوان, with quantity 2, the original ref and correct server totals. All four Egyptian modal tables were compared against every region/fee in the unchanged shipping JSON.

## Empty-page diagnosis and repairs

1. **Reproduced server-side empty response:** opening a nonexistent product with `memory_limit=64M` returned HTTP 500 and zero bytes. `find_product_by_id()` had memoized every supplier's full and lite catalogue while searching a missing/stale ID. With 128M, the same lookup peaked at **102 MiB**.
2. The lookup now releases the previous temporary file before decoding the next, and memoizes a newly loaded catalogue only when it contains a match. Existing full/lite ordering, ID/`orig_id` matching, platform resolution, normalization and returned prices are retained. The same request now renders the original “غير موجود” view at 64M, peaking at **47.8 MiB**.
3. `pagehide` and persisted `pageshow` close transient/nested dialogs and the mobile drawer, restoring inert siblings and the previous scroll state. Real Back navigation and a persisted-page transition were tested.
4. Removed the pending-table CSS that hid PHP-rendered rows until JavaScript enhanced them. Rows now remain usable when that script is blocked or delayed.
5. Added a private, navigable HTML / valid JSON error response for fatal errors that PHP can still flush through an output-buffer callback. It uses **no shutdown handler**, leaks no stack paths, never automatically retries a POST, and tells users to check order/financial state before retrying. It does not suppress server error logging.
6. Closed the first shipping table/card correctly and stopped redefining the already-defined `SB_SERVICE` constant (a confirmed PHP warning; no credential behaviour changed).
7. FTP deployment now uploads shared PHP helpers before their consumers. The optional runtime guard is also checked for readability before inclusion, so its first rolling upload cannot create a missing-helper fatal error.

### Scope and limitations

The memory failure was reproduced locally, not inferred from a production PHP log. The exact user's earlier live white-page incident cannot be matched to a private hosting log from this sandbox. The repairs cover the reproduced catalogue issue and tested browser/table states; they are not a claim that every possible hosting failure has been eliminated.

PHP may discard output buffers during severe OOM, so the error-response callback is **not an OOM guarantee**. An intentionally constrained 32M homepage test still exceeded that limit. Avoiding the reproduced OOM is the lookup fix; unusually constrained hosts or substantially larger datasets require additional streaming/pagination or a hosting-memory review. No hosting memory setting was raised silently.

## Validation

All execution used copied catalogues and synthetic users/orders under ignored `.cache/`, never production user/order files.

- PHP 8.3 Zend lint: **44 files; zero syntax failures**. JavaScript syntax check passed.
- **14 targeted views × 6 widths** (360 / 390 / 768 / 1024 / 1280 / 1536): zero horizontal overflow, JavaScript errors or Axe WCAG 2/2.1 A/AA findings.
- Affiliate removals, legacy/invalid tab handling, real Back/persisted transitions, supplier shipping/isolated checkout, blocked-script tables, CSRF 403 JSON, and private HTML/JSON error responses passed.
- AST comparison against `1548bec`: **171 existing non-UI config functions**, payout handler and monetary constants unchanged. Only `jload` and `find_product_by_id` are explicitly reviewed runtime exceptions; their changes are memory/cache handling, not financial rules.
- Static CSS audit: no undefined CSS variables or CSS-less static classes.
- No new Lighthouse or production performance score is claimed. Existing redesign performance limitations remain documented in `redesign-report.md`.

Regression entry point: `tests/ui/stability.cjs`; local results are summarized in `stability-2026-10-03.json`.

## Deployment

Deployed successfully on `arena/01a0fb2f-afflite`.

- Code commit: `1796006455acfbb1df464fa0a0b46d481c0ce3f3`.
- [Automatic deployment run 37099080965](https://github.com/hamama44/afflite/actions/runs/37099080965): **success**, including the FTP step; completed **2026-10-03 05:14:57 UTC** (08:14:57 Cairo), job duration 2m22s.
- Catalog-bot metadata commit: `a29ca8f` (no shipping-price changes).
- Post-deployment read-only checks against the public site confirmed the homepage hero/category/result counts are gone and an unknown product renders “المنتج غير موجود”, not an empty page.
- Live checks used returned public page content, not an authenticated production-browser session or production checkout. Detailed per-file FTP logs were not retrieved; workflow/job/FTP-step status and the public content were verified.


## Requested redeployment — 2026-10-03

The user requested another hosting upload. The worktree first matched the already-deployed branch exactly. Only static asset URL versions were refreshed to `20261003-deploy2` (verified by exact replacement comparison) to force browsers to fetch the latest CSS/JS; no application logic or prices changed.

- Redeploy commit: `7b7bce56ac85826299e4e813bf5a6ddd35902157`.
- [Deployment run 37099660902](https://github.com/hamama44/afflite/actions/runs/37099660902): **success**, including FTP, completed **2026-10-03 05:26:04 UTC** (08:26:04 Cairo), job duration 2m24s.
- JavaScript syntax check and Git diff whitespace check passed.
