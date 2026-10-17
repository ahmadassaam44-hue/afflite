# Affiliate catalogue filters / M5zoon / drawer cleanup — 2026-10-03

## Requested changes

The clarified request is to add **M5zoon (مخزون), the affiliate supplier**, not display a product inventory balance; country filters and newest/featured/most-viewed/best-selling choices should work like the homepage. Remove the quoted sidebar store/Ref/open/copy box and move store management and product sourcing to the bottom of the drawer.

## Implementation

- `dashboard/marketer.php`: a visible **أفلييت مخزون** tab and permanently available localized supplier selector. The all-country affiliate hub mixes M5zoon with the existing four Egyptian suppliers; an explicit supplier or country narrows the complete source feed.
- Country chips reuse the homepage styling and exactly its six labels: all, Egypt, UAE, Saudi Arabia, Jordan and Oman. The source data is filtered **before** slicing 24 cards, not just by hiding cards on the current page.
- Added the four requested choices to the existing sort selector. Physical newest/featured/most-viewed reuse the unchanged homepage `physical_collection()`; best-selling also matches the homepage's `sort=best` order-quantity ranking. Existing price, added-commission and name choices now apply before pagination. Price/commission sorts group different currencies rather than treating them as converted amounts. There is no new price, cap, payout or ledger policy.
- Mode, category, search, country, supplier and sort survive pagination / filter navigation. Changing a filter starts at page one. The filter/search forms remain native GET forms, with a working apply button when JS is disabled; empty/invalid filter URLs keep their controls and a useful recovery message. Digital/social feeds and their existing category semantics remain separate.
- No product/photo/video result totals or speculative inventory numbers were added. Featured/newness follow the existing source-list/helper rules, not invented dates, ratings, sales or feature flags.
- `dashboard/layout.php`: remove `.side-store-box` entirely and remove the duplicate main-list store tab. One **إدارة متجري** link and one **طلب توفير منتج** link now appear after the other sidebar tools, just before logout. Existing URLs, active state, icons, mobile drawer and collapsed-sidebar titles remain usable. Store editing/sharing inside the actual management screen is retained.
- CSS additions are dashboard-scoped; shared cache version is `20261003-catalog1`. Shared JS, tracker, shipping data, Supabase and payout code are unchanged. Existing card save / order / share hooks and POST contracts are retained.

## Actual validation

All transactions/account fixtures are **synthetic and local**, under excluded `.cache/` roots. No production orders, commissions or accounts were modified.

- **44** PHP **8.3.33** syntax lints pass.
- Strict comparison against `c735c65`: **175 existing non-UI config functions unchanged**, payout handler and monetary constants unchanged; no runtime exceptions permitted. JS syntax, static CSS audit and whitespace checks pass.
- New `tests/ui/catalogue.cjs`: **11** passing groups. Checks mixed/dedicated M5zoon, all country feeds, independent homepage-helper sort comparisons, global rather than page-only pagination/filtering, state preservation/reset, price/commission sorting, digital/social separation, empty/invalid URLs, zero POSTs from filtering/navigation, original M5zoon save/order/link hooks and no-JS native form submission.
- Removed-box / bottom-link order / exact single-link counts / active destination are asserted in the new test. Existing stability expectations were updated to the newly requested navigation, not suppressed.
- Existing variants suite: **18** passing groups, including both colour/size persistence and saved commission in fresh, independent browser contexts; prior bug reproductions were already recorded in the preceding release and were not repeated in this run.
- Existing stability suite: **9** passing groups, including **120/95** shipping fees, CSRF, private fatal fallback, Back/pageshow and no-script table visibility.
- Existing functional flows: **13** passing groups, including payout/status-change cancel sending no POST and real local checkout.
- Responsive / Axe WCAG checks: new Egypt/M5zoon/empty/digital catalogue views at **360/390/768/1024/1280/1536**, plus the existing **4 variant views × 6 widths** and **14 stability views × 6 widths**, pass without overflow or JS errors. External images/analytics were blocked.

### Limits

No authenticated production-browser or production-checkout test was performed; no exhaustive every-product-ID, real-network Lighthouse/performance or full OOM recovery claim is made. Previously documented unrelated backend/security/financial issues remain out of scope. The local expected-order helper is generated only inside `.cache/site/`; it and all synthetic data/login helpers must never be deployed.

## Deployment

- Code commit `17b38637eb7d9edd2d024cc644b1b53513a3100e`, pushed only to `arena/01a0fb2f-afflite`; no PR merge.
- Existing FTP workflow [37106458168](https://github.com/hamama44/afflite/actions/runs/37106458168): **success**, job `111155842211`, **2m17s**, completed **2026-10-03T07:30:48Z** (10:30:48 Cairo). The **تشغيل المحدّث + رفع FTP** step succeeded.
- The subsequent bot commit `b4d5346` changed only catalogue-update metadata.
- Public read-only checks of `index.php?plat=m5zoon&country=الإمارات&release=17b3863` and the user's public `store.php?store=MK-DA-86&release=17b3863` rendered successfully. M5zoon UAE product cards retained native AED prices; the public store retained its existing products/landing links.
- These are **not** authenticated production-catalogue/checkout tests or an individual-file FTP log audit. No production accounts, saved commissions or orders were modified.
