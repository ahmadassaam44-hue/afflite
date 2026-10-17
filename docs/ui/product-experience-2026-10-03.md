# Product media / public navigation / detail UX — 2026-10-03

## Request

Restore Google Drive for products, replace the visible physical/فيزيكال wording with products, remove public sign-in entry points, hide affiliate supplier platforms from marketer UI, and improve product detail UX. This supersedes the earlier request to hide Drive/media and the just-added visible supplier selector; it does not authorize deleting suppliers or weakening authentication.

## Implemented

- Two additive presentation helpers in `ui-components.php` collect **existing** Google Drive / Docs URLs from product files, media fields and descriptions, decode/deduplicate them and reject lookalike or credential-bearing hosts. Shared source-media panels expose Drive links and direct image/video files on product/landing/created-store/marketer surfaces; catalogues link to the product media section. Digital/social quick-order pages also have a source-media panel.
- No Drive API, uploads, folders, endpoints or saved fields were created. Not every source supplies a Drive folder (digital/social records currently have none). These products show their available files and an honest missing-Drive explanation, not an invented folder/link. Existing supplier files and folder access permissions are untouched.
- Public homepage sign-in text, account icon, guest account footer/mobile links and guest dashboard-shell login button are removed. The guest review sign-in prompt is removed. Actual login/register handlers, guards and authenticated account navigation remain intact.
- Visible physical labels are now **منتجات**. The returns wording keeps its original scope by saying products that are shipped. Internal `physical` mode values / URLs are unchanged.
- Supplier selector and dedicated M5zoon branding/tab are no longer visible in marketer UI. M5zoon and all supplier products remain in the catalogue, country filters and requested sorting remain, source IDs and old bookmarked `plat` URLs still work. The `plat` field is now hidden rather than deleted from GET forms.
- Product details have one visible H1, a contained full-size image gallery with keyboard-capable hero/thumbs/lightbox and selected thumbnail state, clearer price/quantity/selection recap, before-shipping subtotal wording, a consistent sticky unit price and better mobile order/cart targets. All old form fields/IDs/CSRF, colour-and-size metadata, modal/cart/save/share hooks and financial calculations are retained.
- Description/reviews remain stacked. Marketer tools can collapse without losing their inputs/actions. Radio choices now have accessible labels. Generic unverified 100% safety/originality claims were removed in favor of actual payment/shipping information and the existing returns-policy link.
- Media styles are component-specific; detail styles are scoped to `.is-product`. Shared asset version: `20261003-product-ui1`.

## Actual verification

All saved commissions/orders/accounts used in tests were synthetic, under excluded `.cache/` copies. No authenticated production browser test or production order/account/commission mutation was performed.

- **44 PHP 8.3.33** syntax lints; JS syntax, static CSS and whitespace checks pass.
- Strict `UI_BASE_REF=0f9ca6d` comparison: **175 existing non-UI config functions unchanged**, payout handler/constants unchanged and no exceptions allowed. Shipping JSON, tracker, Supabase, workflow and business handlers are unchanged.
- New `product-experience.cjs`: **9 passing groups** covering guest sign-in removal with authentication page intact, Drive URL validation/source recovery across five physical suppliers and all relevant media surfaces, missing-link fallback, one heading/keyboard gallery/focus restore, colour/size/qty/sticky-price synchronization, hidden supplier UI, collapsible marketer controls, no-JS media access and zero POSTs for presentation actions.
- Existing variant suite: **18 groups** pass, including actual local colour AND size order persistence and saved commission in fresh browser contexts.
- Existing stability: **9 groups** pass, including supplier **120/95** fees, CSRF, private fatal HTML/JSON, Back/pageshow cleanup and no-script tables.
- Existing functional flows: **13 groups** pass, including real local checkout and payout/admin cancel sending no POST.
- Catalogue suite: **11 groups** pass. Only its visible supplier-selector/tab expectations changed to match this newer request; country/filter/full-catalogue ordering/source/pagination/save/order checks remain.
- Axe WCAG A/AA, JS and overflow checks pass for **4 new guest/marketer standard/radio detail views × 6 widths**, plus the prior **4 variant views**, **14 stability views** and **4 catalogue views** at six widths (360, 390, 768, 1024, 1280, 1536). External analytics/images were blocked in these suites; a separate read-only visual spot check allowed source image requests, without a performance/image-availability guarantee.

### Limits

No new Lighthouse/real-network performance claim or exhaustive all-product-ID/Drive-access test. A valid source link can still require permissions controlled by its Google owner. Previously documented unrelated backend/security/financial defects remain out of scope; historical missing variant choices cannot be reconstructed. Generated local login/oracle/media probes must never be deployed.

## Deployment

- Code commit `78642399c972f83408ec35e09e35657041fb6e0d`, pushed only to `arena/01a0fb2f-afflite`; no PR merge.
- Existing FTP workflow [37109194747](https://github.com/hamama44/afflite/actions/runs/37109194747): **success**, job `111163646245`, **1m33s**, completed **2026-10-03T08:18:54Z** (11:18:54 Cairo). The **تشغيل المحدّث + رفع FTP** step succeeded.
- Bot commit `1eb3467` changed only catalogue-update metadata.
- Public read-only post-deployment checks of the homepage, `dashboard/product.php?id=6056&plat=vendoor` and its landing page rendered successfully. The homepage sample has source-media links and no sign-in link; product/landing pages expose the original Google Drive folder and direct image files. No production save/order/account action was performed.
- Verification is run/job/FTP-step status plus public renders, **not** authenticated owner-UI/checkout coverage or an individual-file FTP log audit.
