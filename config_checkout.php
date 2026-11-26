<?php
// ============================================================================
// config_checkout.php — جزء من config.php (السطور 5003–6005 قبل التقسيم).
// يُحمَّل من config.php فقط، وبالترتيب الأصلي. لا تستدعِه مباشرة من الويب ولا
// تغيّر ترتيب التحميل: كود التشغيل (الجلسة/CSRF/تتبع الإحالة) يعتمد عليه.
// ============================================================================
// ===== نافذة إتمام الطلب الفوري للعميل =====
// خانة «سعر الاتفاق مع العميل» اتشالت من الموقع كله: سعر العميل دايمًا هو سعر الرابط المحسوب على السيرفر.
function render_easyorder_dialog_html($default_ref = '', $default_plat = '', $default_currency = null, $default_country = '') {
    require_once __DIR__ . '/ui-components.php';
    static $rendered = false;
    if ($rendered) return;
    $rendered = true;
    $in_dash = (strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/dashboard/') !== false);
    $orders_url = $in_dash ? 'orders.php' : 'dashboard/orders.php';
    // Display the existing Egyptian supplier rates, not one shared Vendoor table.
    // Non-EGP shipping/accounting behaviour is unchanged and still shows the currency warning.
    $ship_by_platform = [];
    foreach (['vendoor', 'angazny', 'timehand', 'safqa', 'shopmart', 'mnfean'] as $ship_platform) {
        $ship_by_platform[$ship_platform] = site_ship_map($ship_platform);
    }
    $ship_by_platform['safka'] = $ship_by_platform['safqa'];
    // المنصات اللي ليها جدول لكل دولة (المنتجات اليدوية + مخزون): مصر بتستخدم جدول تايم هاند
    // بالجنيه، وكل دولة خليجية ليها جدول مناطقها بعملة الدولة (الرسوم بيدخلها المالك من
    // dashboard/admin_shipping.php) — مفيش تحويل عملات.
    $ship_currency_by_key = [];
    $ship_country_plats = function_exists('site_ship_country_plats') ? site_ship_country_plats() : ['manual'];
    foreach ($ship_country_plats as $ship_cplat) {
        $ship_by_platform[$ship_cplat] = site_ship_map($ship_cplat, 'مصر');
        $ship_currency_by_key[$ship_cplat] = wf_ship_currency($ship_cplat, 'مصر');
        foreach (wf_gulf_countries() as $ship_country) {
            $ship_key = $ship_cplat . ':' . $ship_country;
            $ship_by_platform[$ship_key] = site_ship_map($ship_cplat, $ship_country);
            $ship_currency_by_key[$ship_key] = wf_ship_currency($ship_cplat, $ship_country);
        }
    }
    foreach (array_keys($ship_by_platform) as $ship_key) {
        if (!isset($ship_currency_by_key[$ship_key])) $ship_currency_by_key[$ship_key] = 'ج.م';
    }
    // أول ما يفتح المتصفح الصفحة، قائمة المناطق تظهر بأسعار المنصة/الدولة نفسها (بدل جدول عام)
    $default_plat = trim((string)$default_plat);
    $default_country = wf_match_country($default_country, '');
    $default_key = (in_array($default_plat, $ship_country_plats, true) && $default_country !== '' && $default_country !== 'مصر')
        ? $default_plat . ':' . $default_country
        : $default_plat;
    $ship_rates = ($default_key !== '' && !empty($ship_by_platform[$default_key]))
        ? $ship_by_platform[$default_key]
        : site_ship_map();
    $ship_currency = $ship_currency_by_key[$default_key] ?? 'ج.م';
    $region_label = wf_ship_region_label($default_country);
    $ship_by_platform = ['default' => $ship_rates] + $ship_by_platform;
    $ship_currency_by_key = ['default' => $ship_currency] + $ship_currency_by_key;
    $ref_val = active_ref($default_ref);
    $csrf_val = csrf_token();
    // خانة العمولة الإضافية في ديالوج الطلب: لأي حساب مسوق مسجّل دخول — من أي صفحة
    // (الرئيسية / تفاصيل المنتج / اللوحة). لو الديالوج اتفتح من غير ref، الطلب بيتسجّل
    // بكود المسوق نفسه عشان العمولة تضاف لرصيده.
    $eo_mk_code = '';
    if (MARKETER_EXTRA_COMM_ON && function_exists('wf_is_marketer_account') && ($eo_u = current_user()) && wf_is_marketer_account($eo_u)) {
        $eo_aff = function_exists('ensure_user_affiliate') ? ensure_user_affiliate((string)($eo_u['email'] ?? '')) : null;
        $eo_mk_code = (string)($eo_aff['ref_code'] ?? '');
        if ($eo_mk_code !== '' && $ref_val === '') $ref_val = $eo_mk_code;
    }
    ?>
    
    <?php wf_ui_sprite(); ?>
    <script type="application/json" id="wfShippingState"><?php echo json_encode($ship_by_platform, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?></script>
    <script type="application/json" id="wfShippingCurrency"><?php echo json_encode($ship_currency_by_key, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?></script>
    <?php // الخصم بالجنيه بس: لو الصفحة عندها منتج بعملة تانية (ر.س/د.إ/…) الـ state بيقول للواجهة متطبّقوش
    $bulk_state = function_exists('wf_bulk_discount_state') ? wf_bulk_discount_state($default_plat, $default_currency) : ['per_piece' => 0, 'max_qty' => 99]; ?>
    <script type="application/json" id="wfCityState"><?php echo json_encode(wf_ship_cities_map_all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?></script>
    <script type="application/json" id="wfBulkState"><?php echo json_encode($bulk_state, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?></script>
    <div id="eoCheckoutDialog" class="eo-modal-backdrop" onclick="if(event.target===this)closeEasyOrderModal()">
      <div id="wfLeadModal" class="eo-modal-card" role="dialog" aria-modal="true" aria-labelledby="eoDialogTitle">
        <div class="eo-modal-head">
          <h3 id="eoDialogTitle">إتمام الطلب السريع — الدفع عند الاستلام</h3>
          <button type="button" class="eo-modal-close" onclick="closeEasyOrderModal()" aria-label="إغلاق نافذة الطلب"></button>
        </div>
        <div class="eo-modal-body">
          <div id="eoFormView">
            <div class="eo-prod-summary">
              <img id="eoProdImg" src="" alt="صورة المنتج" width="68" height="68">
              <div class="eo-prod-info">
                <div id="eoProdTitle" class="eo-prod-title shop-lead-name"></div>
                <div data-ui="u-91247d92 ue1 ue7 ue2 ues uet ueu">
                  <div class="eo-prod-price"><span id="eoUnitPriceTxt">0</span> <span data-eo-currency>ج.م</span></div>
                  <div class="eo-qty-box">
                    <button type="button" onclick="eoStepQty(-1)" aria-label="تقليل الكمية">−</button>
                    <input type="number" id="eoQtyInput" value="1" min="1" max="99" aria-label="الكمية" onchange="eoRecalc()" oninput="eoRecalc()">
                    <button type="button" onclick="eoStepQty(1)" aria-label="زيادة الكمية">+</button>
                  </div>
                </div>
              </div>
            </div>

            <form id="eoOrderForm" method="post" action="<?php echo esc($orders_url); ?>" onsubmit="return submitEasyOrderForm(event)">
              <input type="hidden" name="csrf_token" value="<?php echo esc($csrf_val); ?>">
              <input type="hidden" name="ajax_order" value="1">
              <input type="hidden" name="id" id="eoInputId" value="">
              <input type="hidden" name="plat" id="eoInputPlat" value="">
              <input type="hidden" name="pname" id="eoInputPname" value="">
              <input type="hidden" name="ref" id="eoInputRef" value="<?php echo esc($ref_val); ?>">
                            <input type="hidden" name="extra_comm" id="eoInputExtraComm" value="0">
              <input type="hidden" name="sale_price" id="eoInputSalePrice" value="0">
              <input type="hidden" name="unit_price" id="eoInputUnitPrice" value="0">
              <input type="hidden" name="qty" id="eoInputQty" value="1">
              <input type="hidden" name="bundle_offer" id="eoInputBundle" value="">
              <input type="hidden" name="variant" id="eoInputVariant" value="">

              <div id="eoVariantFields" class="eo-field-grid" data-ui="u-9489ae20 uev uew">
                <div class="eo-field" id="eoColorWrap" data-ui="u-5590912d uev">
                  <label><svg class=wf-ico aria-hidden=true><use href=#icon-image></use></svg> اختر اللون</label>
                  <select id="eoColorSelect" name="color" onchange="eoSyncVariant()"></select>
                </div>
                <div class="eo-field" id="eoSizeWrap" data-ui="u-5590912d uev">
                  <label><svg class=wf-ico aria-hidden=true><use href=#icon-info></use></svg> اختر المقاس</label>
                  <select id="eoSizeSelect" name="size" onchange="eoSyncVariant()"></select>
                </div>
              </div>

              <div class="eo-field">
                <label><svg class=wf-ico aria-hidden=true><use href=#icon-user></use></svg> الاسم بالكامل <span data-ui="u-ce5bc718 uex">*</span></label>
                <input type="text" name="name" id="eoBuyerName" required placeholder="اكتب اسمك الثلاثي لاستلام الشحنة">
              </div>

              <div class="eo-field-grid">
                <div class="eo-field">
                  <label><svg class=wf-ico aria-hidden=true><use href=#icon-phone></use></svg> رقم الموبايل الأساسي <span data-ui="u-ce5bc718 uex">*</span></label>
                  <input type="tel" name="phone" id="eoBuyerPhone" required pattern="01[0125][0-9]{8}" maxlength="11" inputmode="numeric" placeholder="01xxxxxxxxx">
                </div>
                <div class="eo-field">
                  <label><svg class=wf-ico aria-hidden=true><use href=#icon-phone></use></svg> رقم بديل / واتساب (اختياري)</label>
                  <input type="tel" name="phone2" id="eoBuyerPhone2" maxlength="15" inputmode="numeric" placeholder="رقم آخر للتأكيد">
                </div>
              </div>

              <div class="eo-field-grid">
                <div class="eo-field">
                  <label id="eoBuyerCityLbl" for="eoBuyerCity"><svg class=wf-ico aria-hidden=true><use href=#icon-truck></use></svg> <span class="eo-region-word"><?php echo esc($region_label); ?></span> <span data-ui="u-ce5bc718 uex">*</span></label>
                  <select name="city" id="eoBuyerCity" required onchange="eoRecalc();eoFillDistricts()" data-ship-currency="<?php echo esc($ship_currency); ?>">
                    <option value="" data-ship="0">-- اختر <?php echo esc($region_label); ?> --</option>
                    <?php foreach ($ship_rates as $gov => $cost): ?>
                      <option value="<?php echo esc($gov); ?>" data-ship="<?php echo (float)$cost; ?>"><?php echo esc($gov); ?> (الشحن: <?php echo (float)$cost; ?> <?php echo esc($ship_currency); ?>)</option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="eo-field">
                  <label id="eoBuyerDistrictLbl" for="eoBuyerDistrictSel"><svg class=wf-ico aria-hidden=true><use href=#icon-map></use></svg> المدينة / المركز <span data-ui="u-ce5bc718 uex">*</span></label>
                  <select name="district" id="eoBuyerDistrictSel" data-ui-field="1" disabled><option value="">-- اختر <?php echo ($default_country !== '' && $default_country !== 'مصر') ? 'المنطقة' : 'المحافظة'; ?> أولاً --</option></select>
                  <input type="text" name="district" id="eoBuyerDistrict" data-ui-field="1" aria-labelledby="eoBuyerDistrictLbl" placeholder="اكتب اسم المدينة أو المركز أو الحي" hidden disabled>
                </div>
              </div>

              <div class="eo-field">
                <label><svg class=wf-ico aria-hidden=true><use href=#icon-map></use></svg> العنوان بالتفصيل <span data-ui="u-ce5bc718 uex">*</span></label>
                <input type="text" name="address" id="eoBuyerAddress" required placeholder="اسم الشارع - رقم العقار - علامة مميزة">
              </div>

              <div class="eo-field">
                <label><svg class=wf-ico aria-hidden=true><use href=#icon-edit></use></svg> ملاحظات إضافية للمندوب (اختياري)</label>
                <input type="text" name="notes" id="eoBuyerNotes" placeholder="مثال: الاتصال قبل الوصول بنصف ساعة">
              </div>

              <?php if ($eo_mk_code !== ''): ?>
              <div class="eo-field" id="eoMkExtraWrap">
                <label for="eoMkExtraInput"><svg class=wf-ico aria-hidden=true focusable="false"><use href=#icon-wallet></use></svg> عمولتك الإضافية على كل قطعة (ج.م) — اختيارية</label>
                <input type="number" id="eoMkExtraInput" name="mk_extra" min="0" max="<?php echo (float)MAX_MARKUP_AMOUNT; ?>" step="1" value="0" inputmode="decimal" placeholder="0" oninput="eoRecalc()" onchange="eoRecalc()">
                <p class="field-hint" style="margin:4px 0 0;font-size:12px">بتتحسب فوق سعر القطعة — العميل بيدفعها ضمن الإجمالي، وانت بتكسبها عمولة على كودك <b dir="ltr"><?php echo esc($eo_mk_code); ?></b> (الحد الأقصى <?php echo (float)MAX_MARKUP_AMOUNT; ?> ج.م).</p>
              </div>
              <?php endif; ?>

              <div class="eo-invoice">
                <div class="eo-inv-row"><span>قيمة المنتجات (<span id="eoInvQty">1</span> قطعة):</span><span><b id="eoInvSub">0</b> <span data-eo-currency>ج.م</span></span></div>
                <div class="eo-inv-row"><span>مصاريف الشحن والتوصيل:</span><span id="eoInvShip">اختر المحافظة</span></div>
                <div class="eo-inv-row" id="eoInvDiscRow" hidden><span>خصم الكمية (<?php echo (int)LP_BUNDLE_DISCOUNT; ?> ج.م لكل قطعة إضافية):</span><span><b id="eoInvDisc">0</b>- <span data-eo-currency>ج.م</span></span></div>
                <div class="eo-inv-row total"><span>الإجمالي المطلوب عند الاستلام:</span><span><b id="eoInvTotal">0</b> <span data-eo-total-currency>ج.م</span></span></div>
              </div>

              <div id="eoErrorMsg" data-ui="u-39395ec1 uev uey ud7 uez uf0 ue9 uf1 ue6 ue5"></div>

              <button type="submit" id="eoSubmitBtn" class="eo-submit-btn">
                <span><svg class=wf-ico aria-hidden=true><use href=#icon-check></use></svg> تأكيد الطلب الآن — الدفع عند الاستلام</span>
              </button>

              <div class="eo-trust-bar">
                <span><svg class=wf-ico aria-hidden=true><use href=#icon-truck></use></svg> شحن سريع لباب البيت</span>
                <span><svg class=wf-ico aria-hidden=true><use href=#icon-shield></use></svg> معاينة قبل الاستلام</span>
                <span><svg class=wf-ico aria-hidden=true><use href=#icon-wallet></use></svg> الدفع كاش عند الاستلام</span>
              </div>
            </form>
          </div>

          <div id="eoSuccessView" data-ui="u-8119510f uev udn uf2">
            <div data-ui="u-c3dd74c3 uf3 uf4 uf5 uf6 uf7 uf8 ue1 ue7 uf9 ufa"></div>
            <h3 data-ui="u-b4c137c4 ufb udu uej ufc">تم تسجيل طلبك بنجاح! </h3>
            <p data-ui="u-aa59976d ufd uek ufe">سيتواصل معك فريق خدمة العملاء قريباً لتأكيد الطلب وموعد التوصيل.</p>
            <div id="eoSuccessSummary" data-ui="u-9898669a udd uff ufg udc ufh udg udh ufi"></div>
            <div data-ui="u-9c9ee36b ue1 ufj uf9 uet">
              <button type="button" class="eo-submit-btn" data-ui="u-44db8333 ufk ufl" onclick="closeEasyOrderModal()">متابعة التسوق</button>
              <a href="<?php echo esc($orders_url); ?>" data-ui="u-6bd08c30 ufm udf uep uej ue6 udg ufn ufo ue7"><svg class=wf-ico aria-hidden=true><use href=#icon-box></use></svg> متابعة طلباتي</a>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <?php
}

// أنجزني: تنظيف عرض اللون ("ابيض * اسود" ← "ابيض + اسود")
function ag_color_show($v) {
    $v = trim((string)$v);
    $v = preg_replace('/\s+/u', ' ', $v);
    $v = preg_replace('/\s*\*\s*/u', ' + ', $v);
    return trim((string)preg_replace('/^[\s\-–—:*]+|[\s\-–—:*]+$/u', '', $v));
}
// أنجزني: فصل "الموديل / اللون" من التسميات المركبة ("ساده ابيض" ← ساده | ابيض)
function ag_split($label) {
    $label = trim((string)$label);
    if ($label === '') return ['', ''];
    static $models = ['أندر ارمور', 'اندر ارمور', 'أندرارمور', 'أندر', 'اندر', 'ساده', 'أديداس', 'اديداس', 'ايديز',
        'نايكي', 'نايك', 'جوردن', 'نقش', 'عــــربي', 'عربي', 'Liner', 'FF', 'LV', 'Versace', 'X'];
    foreach ($models as $m) {
        if (mb_stripos($label, $m) === 0) {
            $rest = preg_replace('/^[\s\-–—:،]+/u', '', mb_substr($label, mb_strlen($m)));
            $rest = trim($rest);
            if ($rest !== '') return [$m, ag_color_show($rest)];
        }
    }
    return ['', ag_color_show($label)];
}
// أنجزني: استخراج نص "وصف الإعلان" من الوصف (بدون الجدول المسطح المكرر وبلا footer "نوع العرض")
function ag_ad_text($desc) {
    $s = trim(str_replace(["\xEF\xBB\xBF", "\xC2\xA0", '&nbsp;'], ['', ' ', ' '], (string)$desc));
    if ($s === '') return '';
    $i = mb_strpos($s, 'نوع العرض');
    if ($i !== false) $s = trim(mb_substr($s, 0, $i));
    if (preg_match('/^:\s*bulk\b/iu', $s)) return '';
    $s = trim((string)preg_replace('/^تفاصيل المنتج\s*وصف الإعلان\s*(?:عروض\s*)?/u', '', $s));
    if (preg_match('/^(?:اللون\s+المقاس\s+%\s+المخزون|المقاس\s+اللون\s+%\s+المخزون|اللون\s+%\s+المخزون|المقاس\s+%\s+المخزون|Model\s+%\s+المخزون|اللون\s+المقاس\s+المخزون|المقاس\s+المخزون|اللون\s+المخزون|Model\s+المخزون|default\s+المخزون)\s+/iu', $s, $m_hdr)) {
        $hdr = $m_hdr[0];
        $s = trim((string)substr($s, strlen($hdr)));
        if (strpos($hdr, '%') !== false) {
            if (preg_match_all('/-?\d+%\s+-?\d+\b/u', $s, $mm, PREG_OFFSET_CAPTURE)) {
                $last = end($mm[0]);
                $byteEnd = $last[1] + strlen($last[0]);
                $s = trim((string)substr($s, $byteEnd));
            }
        } else {
            if (preg_match_all('/(?:متاح|مخزون\s+منخفض|غير\s+متاح)\b/u', $s, $mm, PREG_OFFSET_CAPTURE)) {
                $last = end($mm[0]);
                $byteEnd = $last[1] + strlen($last[0]);
                $s = trim((string)substr($s, $byteEnd));
            }
        }
    }
    $s = trim((string)preg_replace('/^[\s.\-–—:،]+/u', '', $s));
    if (mb_strtolower($s) === 'null') return '';
    $s = trim((string)preg_replace('/\s+\d+(?:\s+\d+){3,}\s*$/u', '', $s));
    $s = trim((string)preg_replace('/[ \t]+/u', ' ', $s));
    return $s;
}
// تفكيك وصف صفقة إلى كتل مبنية: عنوان / عنوان فرعي / نقطة / بند / سطر (تسمية:قيمة) / فقرة / دعوة للطلب / فاصل
function sf_blocks($desc, $plat = 'safqa') {
    $desc = trim(html_entity_decode(str_replace('&nbsp;', ' ', (string)$desc), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($desc === '') return [];
    $parts = preg_split('/\r?\n+| {2,}/u', $desc);
    $blocks = [];
    foreach ($parts as $b) {
        $b = trim(preg_replace('/[ \t]+/u', ' ', $b));
        if ($b !== '') $blocks[] = $b;
    }
    $out = []; $state = 0; $first = true; $titleText = null;
    $itemMax = $plat === 'vendoor' ? 130 : 45;
    foreach ($blocks as $b) {
        $b_clean = trim(preg_replace('/^[\p{Extended_Pictographic}\x{2B50}\x{2705}\x{2728}\s•\-–—:：]+|[\s:：.،,]+$/u', '', $b));
        if (preg_match('/^(?:ال\s*|المقاس\s*)?size\s*(?:chart|chqrt|shart|sharp)(?:\s+ال\S+)?$/iu', $b_clean)) continue;
        if (preg_match('/^(?:ال)?(?:مقاس(?:ات)?|تلبيس)\s+بالوزن(?:\s+تقريبي)?$/u', $b_clean)) continue;
        if (preg_match('/^(?:ل{0,2}لينك\s+(?:ال)?(?:ميديا|وسائط)|رابط\s+الوسائط|ميديا\s+إضافية|الميديا\s+الرئيسي[ةه]|link\s*media)/iu', $b_clean)) continue;
        if (preg_match('/(?:01[0125]\d{8}|wa\.me|للمتابعة\s+مع|للتواصل\s+مع|مسؤل\s+التعاقدات|مسئول\s+التعاقدات|مسؤل\s+المتابع|مسئول\s+المتابع|مسؤول\s+المتابع|مسؤول\s+متابع|مسؤول\s+القسم|مدير\s+الحساب\s+المسؤول|التواصل\s+واتس|التواصل\s+عن\s+طريق\s+واتس|رابط\s+واتساب|أرسل\s+رسالة\s+الآن\s+عبر\s+واتساب|عبد\s*الرحمن\s*(?:فوزي|قرطام)|أ?حمد\s*حلمي|مصنع\s+عمران)/iu', $b)) continue;
        if (preg_match('/(?:نصيح[ةه]\s+للمسوق|نصائح\s+.*للمسوق|ملحوظ[ةه]\s+للمسوق|ملاحظ[ةه]\s+للمسوق|تنبيه\s+للمسوق|تنبيه\s+للمبيعات|مواصفات\s+الطلب\s+للمسوق|سلاح\s+الإقناع\s+للمسوق|زاوية\s+البيع\s+السرية|زوايا\s+تسويقية|writing\s*angles|closing\s*tips|sales\s*tips|handling\s*objections|targeting\s*angles|نقطة\s+قوة\s+بيعية|المسوق\s+الناجح|ركز(?:وا)?\s+(?:جداً\s+)?في\s+الإعلان|خاص\s+بالمسوقين|اوردرات\s+العيد\s+للمسوقين|الزاوية\s+الأولى|الزاوية\s+الثانية)/iu', $b)) continue;
        $l = mb_strlen($b);
        if (preg_match('/^[-–—._]{1,6}$/u', $b)) { $out[] = ['t' => 'sep']; $state = 0; $first = false; continue; }
        if ($plat === 'vendoor') {
            if ($l <= 30 && preg_match('/^(?:ل{0,2}لينك\s+ال(?:ميديا|وسائط)|رابط\s+الوسائط)$/u', $b)) continue;
            if (preg_match('/^ضمان\s+/u', $b)) { $out[] = ['t' => 'warranty', 'b' => $b]; continue; }
            if (mb_strpos($b, 'للمتابعة مع') !== false || preg_match('/01\d{9}/', $b)) continue;
            if ($titleText !== null && $b === $titleText) { $out[] = ['t' => 'para', 'b' => $b]; $state = 0; continue; }
        }
        if ($first) {
            $first = false;
            if ($l <= 120 && !preg_match('/\.$/u', $b) && ($l <= 65 || preg_match('/^[\p{Extended_Pictographic}\x{2B50}\x{2705}\x{2728}]/u', $b))) {
                $out[] = ['t' => 'title', 'b' => $b];
                $titleText = $b;
                continue;
            }
        }
        if (preg_match('/^👇/u', $b)) { $out[] = ['t' => 'cta', 'b' => $b]; $state = 0; continue; }
        if ($l <= 110 && preg_match('/^[\p{Extended_Pictographic}\x{2B50}\x{2705}\x{2728}]/u', $b) && preg_match('/[.!؟?،:]$/u', $b)) {
            $out[] = ['t' => 'bullet', 'b' => $b];
            $state = 0;
            continue;
        }
        if ($l <= 90 && preg_match('/^(.{2,30}?)\s*[:：]\s*(\S.*)$/u', $b, $mm)) {
            $out[] = ['t' => 'kv', 'a' => trim($mm[1]), 'b' => trim($mm[2])];
            $state = 0;
            continue;
        }
        if ($state === 1 && $l <= $itemMax) { $out[] = ['t' => 'item', 'b' => $b]; continue; }
        if ($l <= 25 && !preg_match('/[.،,]$/u', $b)) { $out[] = ['t' => 'head', 'b' => $b]; $state = 1; continue; }
        $out[] = ['t' => 'para', 'b' => $b];
        $state = 0;
    }
    return $out;
}
// تقسيم النص إلى فقرات قصيرة (جمل متجمعة حتى ~130 حرف + حدود الإيموجي)
function th_paras($txt) {
    $txt = trim(preg_replace('/\s+/u', ' ', (string)$txt));
    if ($txt === '') return [];
    $chunks = preg_split('/(?<=[\s.])(?=\p{Extended_Pictographic})/u', $txt, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chunks) $chunks = [$txt];
    $paras = []; $cur = '';
    foreach ($chunks as $ch) {
        foreach (preg_split('/(?<=[.!؟])\s+/u', trim($ch), -1, PREG_SPLIT_NO_EMPTY) as $s) {
            $s = trim($s);
            if ($s === '') continue;
            if ($cur === '') $cur = $s;
            elseif (mb_strlen($cur) + 1 + mb_strlen($s) <= 130) $cur .= ' ' . $s;
            else { $paras[] = $cur; $cur = $s; }
        }
    }
    if ($cur !== '') $paras[] = $cur;
    if (count($paras) > 1 && mb_strlen($paras[count($paras) - 1]) < 45) {
        $last = array_pop($paras);
        $paras[count($paras) - 1] = trim($paras[count($paras) - 1] . ' ' . $last);
    }
    return $paras;
}
// تقطيع قيمة الألوان إلى شرائح (تتوقف عند أول كلمة غير لون)
function th_color_chips($v) {
    $v = (string)$v;
    $v = preg_replace('/^\s*(?:لون|لونان|لونين|الون)\s+(?:واحد|واحده|واحدة)?\s*(?:فقط)?\s*/u', '', $v);
    $v = preg_replace('/^\s*[\d]+\s*(?:قطعة|قطعتين|ألوان|الوان|لون|لونان)?\s*\(?/u', '', $v);
    $v = preg_replace('/^\s*(?:\p{Extended_Pictographic}|\x{FE0F}|\x{200D}|\s)+/u', '', $v);
    $x = preg_match('/\p{Extended_Pictographic}/u', $v, $m, PREG_OFFSET_CAPTURE);
    if ($x && $m[0][1] > 0) $v = substr($v, 0, $m[0][1]);
    $v = preg_replace('/\s*الوصف\s+التسويق.*$/u', '', $v);
    $v = str_replace(['*', '/', '،', '+', '(', ')'], ' ', $v);
    $stop = ['جدول', 'المقاسات', 'مقاسات', 'مقاس', 'المقاس', 'المتوفره', 'المتوفرة', 'المتاحه', 'المتاح', 'المتاحة', 'المتوفر', 'متوفره', 'متوفرة', 'متوفر', 'متوفرن', 'علي', 'على', 'حسب', 'الوزن', 'وزن', 'كجم', 'كغ', 'سم', 'بوكس', 'قطعة', 'قطع', 'شامل', 'سلسلة', 'انسيال', 'خاتم', 'حلق', 'مميزات', 'مميز', 'فوائد', 'يضيف', 'لمسة', 'أنيقة', 'انيقة', 'وفخمة', 'فخمة', 'تصميم', 'مناسب', 'مناسبة', 'سهل', 'سهلة', 'للاستخدام', 'اليومي', 'اليومية', 'المناسبات', 'اطلالة', 'إطلالة', 'يناسب', 'ناعمة', 'عملية', 'راحة', 'شياكة', 'عالية', 'ممتاز', 'ممتازة', 'ساعة', 'سوار', 'طقم', 'لون', 'ألوان', 'الوان', 'الالوان', 'اللون', 'لونين', 'الوانين', 'من', 'إلى', 'الي', 'الى', 'و', 'في', 'مع', 'أو', 'او'];
    $out = [];
    foreach ((array)preg_split('/\s+/u', trim($v), -1, PREG_SPLIT_NO_EMPTY) as $t) {
        $t = preg_replace('/^[\s.:\-–—“”‘’«»"\'،؛ـ：]+|[\s.:\-–—“”‘’«»"\'،؛ـ：]+$/u', '', (string)$t);
        if ($t === '' || preg_match('/^[\p{S}\p{C}]+$/u', $t)) continue;
        if (in_array($t, $stop, true)) break;
        if (preg_match('/\d/u', $t)) break;
        if (mb_strlen($t) < 2 || mb_strlen($t) > 14) break;
        if (in_array($t, $out, true)) continue;
        $out[] = $t;
        if (count($out) >= 10) break;
    }
    return $out;
}
// مصدر كلمات اللون: ما بعد "الألوان ..." في التسمية + القيمة
function th_color_src($lab, $val) {
    $rest = '';
    if (preg_match('/لوان/u', (string)$lab)) $rest = (string)preg_replace('/^.*?لوان\s*(?:المتوفرة|المتوفره|المتاحه|المتاح|المتاحة|المتوفر)?\s*/u', '', (string)$lab);
    return trim($rest . ' ' . (string)$val);
}
function th_clean_val($v) {
    $v = (string)$v;
    $v = preg_replace('/\s*و\s*بعد\s+الفحص.*$/u', '', $v);
    $v = preg_replace('/\s*(?:⚠️\s*|\x{FE0F})?تنويه\s+(?:هام|هم|هما).*$/u', '', $v);
    $v = preg_replace('/\s*و?\s*بمجرد\s+اعتماد.*$/u', '', $v);
    $v = preg_split('/•/u', $v)[0];
    return trim(preg_replace('/\s+/u', ' ', (string)$v));
}
function th_colons($s) {
    $out = [];
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        if ($s[$i] === ':') $out[] = $i;
        elseif (substr($s, $i, 3) === "\xEF\xBC\x9A") $out[] = $i;
    }
    return $out;
}
function th_colon($s, $from, $maxlen = 8) {
    $end = min(strlen($s), $from + $maxlen);
    for ($i = $from; $i < $end; $i++) {
        if ($s[$i] === ':') return $i;
        if (substr($s, $i, 3) === "\xEF\xBC\x9A") return $i;
    }
    return false;
}
function th_junk_re() { return '/^[\s\d.\-*:"“”\'’«»،,▪■◆●·•\x{25AA}\x{25A0}\x{25C6}\x{25CF}\x{2022}\x{FE0F}\x{200D}\x{26A0}]*$/u'; }
// تقطيع نص إلى صفوف [تسمية, قيمة] + مقاطع نص حر (يبدأ من التجزئة على • وأسطر جديدة)
function th_pairs($txt) {
    $rows = [];
    $txt = (string)$txt;
    $junk = th_junk_re();
    $known = ['تصنيف المنتج', 'التغليف', 'الخامه', 'الخامة', 'الماتريال', 'التقفيل', 'جودة المنتج بشكل عام', 'جوده المنتج بشكل عام', 'اسم المنتج', 'الألوان المتوفرة', 'الألوان المتوفره', 'الوان المتوفره', 'الوان المتوفرة', 'الالوان المتوفره', 'المقاسات المتوفرة', 'مقاسات متوفره', 'المقاسات المتاحه', 'المقاسات المتاحة', 'خامة المنتج', 'خامه المنتج', 'المقاسات'];
    foreach ((array)preg_split('/[•\n]+/u', $txt) as $raw) {
        $seg = trim(preg_replace('/\s+/u', ' ', (string)$raw));
        if ($seg === '' || preg_match($junk, $seg)) continue;
        $cols = th_colons($seg);
        if (count($cols) === 0) { $rows[] = ['', $seg]; continue; }
        if (count($cols) === 1) {
            $lab = trim(substr($seg, 0, $cols[0]));
            $val = trim(substr($seg, $cols[0] + 1));
            if ($lab !== '' && mb_strlen($lab) <= 45 && !preg_match($junk, $lab)) $rows[] = [$lab, $val];
            else $rows[] = ['', $seg];
            continue;
        }
        $anch = [];
        foreach ($known as $k) {
            $off = 0; $kl = strlen($k);
            while (($x = strpos($seg, $k, $off)) !== false) {
                $off = $x + 1;
                if ($x > 0 && !preg_match('/[\s"“”\'’«»]$/u', substr($seg, 0, $x))) continue;
                $c = th_colon($seg, $x + $kl, 8);
                if ($c === false) continue;
                $anch[] = [$x, $c];
            }
        }
        $c0 = $cols[0];
        if ($c0 <= 90) {
            $covered = false;
            foreach ($anch as $a) if ($a[0] <= 25 && $a[1] <= $c0) $covered = true;
            $lab0 = trim(substr($seg, 0, $c0));
            if (!$covered && $lab0 !== '' && mb_strlen($lab0) <= 45 && !preg_match($junk, $lab0)) $anch[] = [0, $c0];
        }
        if (!$anch) { $rows[] = ['', $seg]; continue; }
        usort($anch, function ($a, $b) { return $a[0] - $b[0]; });
        $clean = [];
        foreach ($anch as $a) { if ($clean && $a[0] <= $clean[count($clean) - 1][1]) continue; $clean[] = $a; }
        $cursor = 0; $n = count($clean);
        for ($i = 0; $i < $n; $i++) {
            $aStart = $clean[$i][0]; $aCol = $clean[$i][1];
            $before = trim(substr($seg, $cursor, $aStart - $cursor));
            if ($before !== '' && !preg_match($junk, $before)) $rows[] = ['', $before];
            $vEnd = ($i + 1 < $n) ? $clean[$i + 1][0] : strlen($seg);
            $lab = trim(substr($seg, $aStart, $aCol - $aStart));
            $val = trim(substr($seg, $aCol + 1, $vEnd - $aCol - 1));
            if ($lab !== '') $rows[] = [$lab, $val];
            $cursor = $vEnd;
        }
        $tail = trim(substr($seg, $cursor));
        if ($tail !== '' && !preg_match($junk, $tail)) $rows[] = ['', $tail];
    }
    return $rows;
}
function th_section($desc, $texts, $emoji) {
    foreach ($texts as $t) { $x = strpos($desc, $t); if ($x !== false) return $x; }
    return strpos($desc, $emoji);
}
function th_trim_end($s) {
    $s = (string)$s;
    $s = preg_replace('/[\s\x{2500}-\x{257F}\-–—]*\d(?:\x{FE0F})?\x{20E3}\s*$/u', '', $s);
    $s = preg_replace('/[\s،,\x{2500}-\x{257F}\-–—]+$/u', '', $s);
    return trim($s);
}
function th_qr_rows($ex) {
    $qr = is_array($ex['quality_report'] ?? null) ? $ex['quality_report'] : [];
    $rows = [];
    if (is_numeric($qr['rating'] ?? null) && (float)$qr['rating'] > 0) $rows[] = ['⭐ التقييم', (string)$qr['rating']];
    foreach (['category' => 'تصنيف المنتج', 'packaging' => 'التغليف', 'material' => 'الخامة', 'finishing' => 'التقفيل', 'overall' => 'جودة المنتج بشكل عام'] as $k => $lab) {
        $v = th_clean_val((string)($qr[$k] ?? ''));
        if ($v !== '') $rows[] = [$lab, mb_substr($v, 0, 90)];
    }
    return $rows;
}
// استخراج نسبة التسليم من نص وإزالته
function th_deliv_from($txt, &$out) {
    if ($out !== '' || !is_string($txt) || $txt === '') return $txt;
    $pats = [
        '/(?:نسبة|نسبه)\s+التسليم[^\d]{0,70}(\d+)(?!\d)\s*%?\s*[^0-9]{0,15}(\d+)(?!\d)\s*%?/u',
        '/(?:نسبة|نسبه)\s+التسليم[^\d]{0,70}(\d+)(?!\d)\s*%?/u',
    ];
    foreach ($pats as $i => $pat) {
        if (preg_match($pat, $txt, $dm, PREG_OFFSET_CAPTURE)) {
            $out = ($i === 0) ? ('%' . $dm[1][0] . ' إلى %' . $dm[2][0]) : ($dm[1][0] . '%');
            $start = $dm[0][1];
            $w = strpos($txt, 'بعد الفحص');
            if ($w !== false && $w < $start) $start = $w;
            if (preg_match('/(?:[\s\p{Extended_Pictographic}\x{FE0F}\x{200D}])+$/u', substr($txt, 0, $start), $mm)) $start = max(0, $start - strlen($mm[0]));
            return substr($txt, 0, $start) . substr($txt, $dm[0][1] + strlen($dm[0][0]));
        }
    }
    return $txt;
}
// استخراج التنويه (آخر النص) وإزالته
function th_warn_from($txt, &$out) {
    if ($out !== '' || !is_string($txt) || $txt === '') return $txt;
    $pats = [
        '/(?:⚠\s?\x{FE0F}?\s*)?تنويه(?:\s+(?:هام|هم|هما))?\s*[:：]?\s*(.+)$/su',
        '/⚠\s?\x{FE0F}?\s*ملاحظة(?:\s+(?:هامة|هم))?\s*[:：]?\s*(.+)$/su',
        '/\s*بمجرد\s+اعتماد.+$/su',
    ];
    foreach ($pats as $pat) {
        if (preg_match($pat, $txt, $wm, PREG_OFFSET_CAPTURE)) {
            $out = preg_replace('/^[\x{FE0F}\x{200D}]+/u', '', trim($wm[count($wm) - 1][0]));
            $start = $wm[0][1];
            if (preg_match('/(?:[\s\p{Extended_Pictographic}\x{FE0F}\x{200D}])+$/u', substr($txt, 0, $start), $mm)) $start = max(0, $start - strlen($mm[0]));
            return substr($txt, 0, $start);
        }
    }
    return $txt;
}
// تحليل وصف تايم هاند إلى: مقدمة / جودة / تسليم / تنويه / تفاصيل / تسويقي
function th_parse($desc, $ex) {
    $r = ['marked' => false, 'intro' => '', 'q' => [], 'deliv' => '', 'warn' => '', 'det' => [], 'mkt' => ''];
    $desc = (string)$desc;
    $ex = is_array($ex) ? $ex : [];
    $hq = th_section($desc, ['تقييم جودة المنتج', 'تقيم جوده المنتج'], '1️⃣');
    $hd = th_section($desc, ['تفاصيل المنتج'], '2️⃣');
    $hm = th_section($desc, ['الوصف التسويقي'], '3️⃣');
    if ($hd !== false && $hq !== false && $hd < $hq) $hq = false;
    if ($hm !== false && $hd !== false && $hm < $hd) $hm = false;
    if ($hm !== false && $hq !== false && $hm < $hq) $hm = false;
    if ($hq === false && $hd === false) {
        $md = trim((string)($ex['marketing_desc'] ?? ''));
        $det = $desc;
        if ($md !== '' && strpos($desc, $md) !== false) $det = trim(substr($desc, 0, strpos($desc, $md)));
        $det = th_deliv_from($det, $r['deliv']);
        $det = th_warn_from($det, $r['warn']);
        foreach (preg_split('/\n/u', $det) as $ln) {
            $ln = trim(preg_replace('/\s+/u', ' ', (string)$ln));
            $ln = preg_replace('/\d\x{FE0F}?\x{20E3}/u', ' ', $ln);
            $ln = trim(preg_replace('/\s+/u', ' ', (string)$ln));
            if ($ln !== '' && !preg_match(th_junk_re(), $ln)) $r['det'][] = ['', $ln];
        }
        $qrn = 0;
        foreach (['category', 'packaging', 'material', 'finishing', 'overall'] as $k) if (trim((string)($ex['quality_report'][$k] ?? '')) !== '') $qrn++;
        if ($qrn >= 2) $r['q'] = null;
    } else {
        $r['marked'] = true;
        $first = $hq !== false ? $hq : $hd;
        $intro = th_trim_end(preg_replace('/[\x{2500}-\x{257F}]{2,}/u', ' ', (string)substr($desc, 0, $first)));
        $intro = th_deliv_from($intro, $r['deliv']);
        $intro = th_warn_from($intro, $r['warn']);
        $r['intro'] = th_trim_end($intro);
        $qsec = '';
        if ($hq !== false) {
            $qend = strlen($desc);
            if ($hd !== false && $hd > $hq) $qend = $hd;
            elseif ($hm !== false && $hm > $hq) $qend = $hm;
            $qsec = (string)substr($desc, $hq, $qend - $hq);
            $qsec = preg_replace('/(?:1️⃣\s*)?(?:تقييم\s+جودة|تقيم\s+جوده)\s+المنتج\s*(?:-\s*\d+)?/u', '', $qsec, 1);
            $qsec = preg_replace('/^\s*[-–—\s]*1️⃣\s*/u', '', $qsec);
            $qsec = preg_replace('/[\x{2500}-\x{257F}]{2,}/u', ' ', $qsec);
            $qsec = th_trim_end($qsec);
            $qsec = th_deliv_from($qsec, $r['deliv']);
            $qsec = th_warn_from($qsec, $r['warn']);
            $qsec = preg_replace('/\s+و$/u', '', $qsec);
            foreach (th_pairs($qsec) as $row) {
                if ($row[0] === '') continue;
                if (preg_match('/تقييم|تقيم|تنويه/u', $row[0])) continue;
                if (preg_match('/\p{Extended_Pictographic}/u', $row[0]) || mb_strlen($row[0]) > 40) continue;
                $val = th_clean_val($row[1]);
                if (mb_strlen($val) > 90) {
                    $t = mb_substr($val, 0, 90);
                    $sp = mb_strrpos($t, ' ');
                    $val = ($sp !== false && $sp > 50) ? mb_substr($t, 0, $sp) : $t;
                }
                if ($val !== '') $r['q'][] = [$row[0], $val];
            }
        }
        if ($hd !== false) {
            $dend = ($hm !== false && $hm > $hd) ? $hm : strlen($desc);
            $ds = (string)substr($desc, $hd, $dend - $hd);
            foreach (['1️⃣ تقييم', 'تقييم جودة المنتج', 'تقيم جوده المنتج'] as $cutn) {
                $ci = strpos($ds, $cutn);
                if ($ci !== false) { $ds = substr($ds, 0, $ci); break; }
            }
            $ds = preg_replace('/(?:2️⃣\s*)?تفاصيل\s+المنتج/u', '', $ds, 1);
            $ds = preg_replace('/^\s*[-–—\s]*2️⃣\s*/u', '', $ds);
            $ds = preg_replace('/[\x{2500}-\x{257F}]{2,}/u', "\n", $ds);
            $ds = th_trim_end($ds);
            foreach (th_pairs($ds) as $row) {
                $lab = trim(preg_replace('/\s+/u', ' ', preg_replace('/\d\x{FE0F}?\x{20E3}/u', ' ', (string)$row[0])));
                $val = trim(preg_replace('/\s+/u', ' ', preg_replace('/\d\x{FE0F}?\x{20E3}/u', ' ', (string)$row[1])));
                if ($lab === '' && $val === '') continue;
                if (strpos($lab . ' ' . $val, 'اسم المنتج') !== false) {
                    $src = ($lab === '') ? $val : ($lab . ' ' . $val);
                    $v = preg_replace('/[\\\\\/]*\s*اسم\s+المنتج\s*[:：]?\s*/u', ' ', $src);
                    $v = trim(preg_replace('/^[\s:：\/\\\\]+|[\s:：\/\\\\]+$/u', '', (string)$v));
                    if ($v !== '') $r['det'][] = ['اسم المنتج', $v];
                    continue;
                }
                if ($lab !== '' && (preg_match('/\p{Extended_Pictographic}/u', $lab) || mb_strlen($lab) > 40)) {
                    $r['det'][] = ['', $lab . ': ' . $val];
                    continue;
                }
                if ($lab !== '' && preg_match('/أ?لوان|الوان/u', $lab)) {
                    $chips = th_color_chips(th_color_src($lab, $val));
                    if ($chips) {
                        $r['det'][] = [$lab, implode(' ', $chips)];
                    } else {
                        if (mb_strlen($val) > 90) {
                            $t = mb_substr($val, 0, 90);
                            $sp = mb_strrpos($t, ' ');
                            $val = ($sp !== false && $sp > 50) ? mb_substr($t, 0, $sp) : $t;
                        }
                        $r['det'][] = [$lab, $val];
                    }
                    continue;
                }
                if ($lab === '' && preg_match(th_junk_re(), $val)) continue;
                $r['det'][] = [$lab, $val];
            }
        }
        if ($hm !== false) {
            $ms = (string)substr($desc, $hm);
            $h2 = strpos($ms, 'الوصف التسويقي', 1);
            if ($h2 !== false) {
                $mid = preg_replace('/\s*[-–—]*\s*(?:3️⃣\s*)?الوصف\s+التسويق[\p{L}]*\s*(?:للسوشيال\s*\.?\s*ميديا)?/u', '', (string)substr($ms, 0, $h2));
                $mid = preg_replace('/[\s\x{2500}-\x{257F}\-–—\d\x{FE0F}\x{20E3}\x{200D}]+/u', '', $mid);
                if ($mid === '') $ms = (string)substr($ms, $h2);
                else $ms = (string)substr($ms, 0, $h2);
            }
            $ms = preg_replace('/\s*[-–—]*\s*(?:3️⃣\s*)?الوصف\s+التسويق[\p{L}]*\s*(?:للسوشيال\s*\.?\s*ميديا)?/u', '', $ms);
            $ms = preg_replace('/^\s*[-–—\s]*(?:\d\x{FE0F}?\x{20E3}\s*)?/u', '', $ms);
            $r['mkt'] = th_trim_end(preg_replace('/[\x{2500}-\x{257F}]{2,}/u', ' ', $ms));
        }
    }
    if ($r['q'] === null || (empty($r['q']) && $r['marked'])) $r['q'] = th_qr_rows($ex);
    if ($r['deliv'] === '' && trim((string)($ex['delivery_rate'] ?? '')) !== '') $r['deliv'] = trim((string)$ex['delivery_rate']);
    if ($r['mkt'] === '') {
        $md = trim((string)($ex['marketing_desc'] ?? ''));
        if ($md !== '') {
            $md = preg_replace('/\s*[-–—]*\s*(?:3️⃣\s*)?الوصف\s+التسويق[\p{L}]*\s*(?:للسوشيال\s*\.?\s*ميديا)?/u', '', $md);
            $md = preg_replace('/^\s*[-–—\s]*(?:\d\x{FE0F}?\x{20E3}\s*)?/u', '', $md);
            $r['mkt'] = th_trim_end(preg_replace('/[\x{2500}-\x{257F}]{2,}/u', ' ', $md));
        }
    }
    if ($r['mkt'] !== '' && preg_match('/^\s*[-–—"\'’]*\s*(?:2️⃣\s*)?تفاصيل\s+المنتج/u', $r['mkt'])) {
        if (!$r['det']) $r['det'][] = ['', $r['mkt']];
        $r['mkt'] = '';
    }
    if ($r['mkt'] !== '' && preg_match('/(?:الألوان|الوان|الالوان)\s+المتوفر|جدول\s+المقاسات|(?:خامة|خامه|الماتريال)\s+المنتج/u', mb_substr($r['mkt'], 0, 60))) {
        if (!$r['det']) $r['det'][] = ['', $r['mkt']];
        $r['mkt'] = '';
    }
    return $r;
}

// استخراج ملخص تسويقي نظيف للمنتج (للإعلانات، الكروت، وبطاقات المتجر بدون أي نصوص مخزون أو تكلفة)
// شيل روابط Google Drive من أي نص معروض للعميل (الميديا بتتجهز من لوحة المسوق)
function wf_strip_drive_links($text) {
    $text = (string)$text;
    if ($text === '' || stripos($text, 'drive.google') === false) return $text;
    $text = preg_replace('~https?://(?:drive|docs)\.google\.com/\S+~iu', '', $text);
    $text = preg_replace('~drive\.google\.com/\S*~iu', '', $text);
    $text = preg_replace('/[ \t]{2,}/u', ' ', $text);
    $text = preg_replace('/\n{3,}/u', "\n\n", $text);
    return trim($text);
}

function product_marketing_summary($p, $plat = '') {
    return wf_strip_drive_links(product_marketing_summary_raw($p, $plat));
}

function product_marketing_summary_raw($p, $plat = '') {
    if (!is_array($p)) return '';
    $plat = $plat ?: ($p['_plat'] ?? resolve_plat($p['id'] ?? ''));
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    $dname = aff_clean($p['name'] ?? '', true);
    $raw_desc = aff_clean(html_entity_decode(str_replace('&nbsp;', ' ', (string)($p['desc'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if ($plat === 'timehand') {
        $th = th_parse($raw_desc, $ex);
        $parts = [];
        if (!empty($th['mkt'])) {
            $parts[] = $th['mkt'];
        }
        if (!empty($th['det'])) {
            foreach ($th['det'] as $dr) {
                $line = trim(($dr[0] !== '' ? $dr[0] . ': ' : '') . $dr[1]);
                if ($line !== '' && $line !== $dname) $parts[] = '• ' . $line;
            }
        }
        if ($parts) return implode("\n", $parts);
    } elseif ($plat === 'angazny') {
        $ag = ag_ad_text($raw_desc);
        if ($ag === '' && !empty($ex['marketing_desc'])) $ag = ag_ad_text((string)$ex['marketing_desc']);
        if ($ag !== '') return $ag;
    } elseif ($plat === 'm5zoon') {
        $mz = mz_desc_data($p, $ex, $dname);
        $parts = [];
        foreach ($mz['prose'] ?? [] as $pr) {
            if (!empty($pr['b'])) $parts[] = $pr['b'];
        }
        foreach ($mz['feat'] ?? [] as $ft) {
            $parts[] = '✅ ' . $ft;
        }
        if ($parts) return implode("\n", $parts);
        $clean_mz = mz_clean_prose($raw_desc);
        if ($clean_mz !== '') return $clean_mz;
    } else {
        $blks = sf_blocks($raw_desc, $plat);
        if ($blks) {
            $parts = [];
            foreach ($blks as $b) {
                if (($b['t'] ?? '') === 'sep') continue;
                if (($b['t'] ?? '') === 'kv') $parts[] = '• ' . $b['a'] . ': ' . $b['b'];
                elseif (!empty($b['b'])) $parts[] = $b['b'];
            }
            if ($parts) return implode("\n", $parts);
        }
    }
    return $raw_desc !== '' ? $raw_desc : $dname;
}

// عرض تفاصيل ومواصفات المنتج بشكل منظم وموحد في الموقع الرئيسي وصفحة الهبوط والمتجر
function render_product_details_html($p, $plat = '', $opts = []) {
    if (!is_array($p)) return '';
    $plat = $plat ?: ($p['_plat'] ?? resolve_plat($p['id'] ?? ''));
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    $is_landing = !empty($opts['is_landing']);
    $in_root = !empty($opts['in_root']);
    $dname = aff_clean($p['name'] ?? '', true) ?: ($p['name'] ?? 'منتج');
    $raw_desc = aff_clean(html_entity_decode(str_replace('&nbsp;', ' ', (string)($p['desc'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (!empty($ex['description_html'])) {
        $ex['description_html'] = aff_clean_html($ex['description_html']);
    }

    $colors_clean = clean_product_colors($p, $plat);
    $sizes_clean = clean_product_sizes($p, $plat);
    $cat_name = wf_normalize_category($p['category'] ?? '');
    $country_name = trim((string)($p['country'] ?? 'مصر'));
    $sku_val = trim((string)($p['sku'] ?? ''));
    if ($sku_val === '' && $plat === 'm5zoon' && function_exists('mz_prod_code')) {
        $sku_val = mz_prod_code($p);
    }

    $fix_img = function ($u) use ($in_root) {
        $u = trim((string)$u);
        if ($u === '') return '';
        if (preg_match('~^https?://|^data:~i', $u)) return $u;
        $u = ltrim(str_replace('../', '', asset_url($u)), '/');
        return $in_root ? $u : ('../' . $u);
    };

    ob_start();
    $is_dup_line = function ($txt) use ($dname) {
        $t = trim(preg_replace('/^[\s•\-–—✅✨🔥🎨📏📦🛡️⭐]+/u', '', (string)$txt));
        if ($t === '' || $t === $dname) return true;
        if (preg_match('/^(?:اسم\s+المنتج|الألوان\s+المتاحة|الالوان\s+المتاحة|الألوان\s+المتوفره?|المقاسات\s+المتاحة|المقاسات\s+المتوفره?)\s*[:：]/u', $t)) return true;
        return false;
    };
    ?>
    <div class="wf-prod-details" data-ui="u-bbc63b79 ue1 ufp ufq ufr udi">

        <!-- 1. الوصف التفصيلي والمميزات (مرتبة تحت بعض بدون تكرار) -->
        <?php if ($plat === 'timehand'):
            $th = th_parse($raw_desc, $ex);
            $thHas = ($th['intro'] !== '' || !empty($th['q']) || !empty($th['det']) || $th['mkt'] !== '');
        ?>
            <?php if ($thHas): ?>
                <?php if ($th['intro'] !== '' && !$is_dup_line($th['intro'])): ?>
                <div data-ui="u-18526c3b ufs udd ude ufg uda udh uer ue1 ufp uft">
                    <?php foreach (th_paras($th['intro']) as $pp): if ($is_dup_line($pp)) continue; ?>
                    <p data-ui="u-b1c192b6 ufu"><?php echo esc($pp); ?></p>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($th['mkt'])):
                    $mktBlocks = sf_blocks($th['mkt'], 'safqa');
                ?>
                <div data-ui="u-4bce1c57 ufv ufw ufx ufg ue1 ufp ues">
                    <h4 data-ui="u-ab49a913 udu ufy uee ufz">✨ الوصف والمميزات</h4>
                    <?php if ($mktBlocks): foreach ($mktBlocks as $mb):
                        if (!empty($mb['b']) && $is_dup_line($mb['b'])) continue;
                    ?>
                        <?php if ($mb['t'] === 'bullet' || $mb['t'] === 'item'): ?>
                            <div data-ui="u-587c503d ug0 ueg ufx ug1 ue9 ufd ueq udi"><?php echo esc($mb['b']); ?></div>
                        <?php elseif ($mb['t'] === 'kv'): if ($is_dup_line($mb['a'] . ': ' . $mb['b'])) continue; ?>
                            <div data-ui="u-fc888cb4 ug0 ueg ude ue9 ufd ue1 ufp ug2"><b data-ui="u-6b87f6a7 ufz"><?php echo esc($mb['a']); ?></b><span data-ui="u-0cbb32a0 udi ueq"><?php echo esc($mb['b']); ?></span></div>
                        <?php elseif (!empty($mb['b'])): ?>
                            <p data-ui="u-88cefbc7 ufu uda udh udi ug3"><?php echo esc($mb['b']); ?></p>
                        <?php endif; ?>
                    <?php endforeach; else: ?>
                        <?php foreach (th_paras($th['mkt']) as $mp): if ($is_dup_line($mp)) continue; ?>
                            <p data-ui="u-88cefbc7 ufu uda udh udi ug3"><?php echo esc($mp); ?></p>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($th['det'])): ?>
                <div data-ui="u-5c9de8d6 ufv udd ude ufg ue1 ufp ues">
                    <h4 data-ui="u-2cdd8e73 udu ufy uee uej">📋 تفاصيل المنتج</h4>
                    <?php foreach ($th['det'] as $dr):
                        $dl = trim((string)$dr[0]);
                        $dv = trim((string)$dr[1]);
                        if ($dl === 'اسم المنتج' || ($dl !== '' && preg_match('/أ?لوان|الوان|مقاس/u', $dl))) continue;
                        if ($dl === '' && $is_dup_line($dv)) continue;
                    ?>
                        <?php if ($dl !== ''): ?>
                        <div data-ui="u-2fc48133 ueg ude ue9 ug0 ue1 ue2 ufj ue7 ufd">
                            <span data-ui="u-b9561a7b udy ueq"><?php echo esc($dl); ?></span>
                            <b data-ui="u-543b2bfb uej ug4"><?php echo esc($dv); ?></b>
                        </div>
                        <?php else: ?>
                            <?php foreach (th_paras($dv) as $pp): if ($is_dup_line($pp)) continue; ?>
                            <div data-ui="u-cbb533ed ueg ude ue9 ug0 ufd ug5 uer"><?php echo esc($pp); ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($th['q'])): ?>
                <div data-ui="u-0c485645 ufv uf6 ug6 ufg ue1 ufp ues">
                    <h4 data-ui="u-4c5c067e udu ufy uee ug7">🛡️ مواصفات الجودة</h4>
                    <?php foreach ($th['q'] as $qr): ?>
                    <div data-ui="u-f441b74b ueg ug6 ue9 ug0 ue1 ue2 ues ue7 ufd">
                        <span data-ui="u-048f1631 uek ueq"><?php echo esc($qr[0]); ?></span>
                        <b data-ui="u-cef22b7a ug7"><?php echo esc($qr[1]); ?></b>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div data-ui="u-787df983 ufv udd ude ufg udh uda"><?php echo nl2br(esc($raw_desc ?: $dname)); ?></div>
            <?php endif; ?>

        <?php elseif ($plat === 'm5zoon'):
            $mzD = mz_desc_data($p, $ex, $dname);
            $mzProse = $mzD['prose'] ?? [];
            $mzFeat = $mzD['feat'] ?? [];
            if (!$mzProse && !$mzFeat) {
                $cleanMz = mz_clean_prose($raw_desc);
                if ($cleanMz !== '') $mzProse = [['h' => '', 'b' => $cleanMz]];
            }
        ?>
            <?php if ($mzProse): ?>
            <div data-ui="u-5c9de8d6 ufv udd ude ufg ue1 ufp ues">
                <h4 data-ui="u-2cdd8e73 udu ufy uee uej">📝 وصف المنتج</h4>
                <?php $mzLastH = null; foreach ($mzProse as $mzp): ?>
                    <?php if ($mzp['h'] !== '' && $mzp['h'] !== $mzLastH && !$is_dup_line($mzp['h'])): $mzLastH = $mzp['h']; ?>
                    <h5 data-ui="u-ce45b0e7 udu ug8 uda ufz"><?php echo esc($mzLastH); ?></h5>
                    <?php endif; ?>
                    <?php foreach (th_paras($mzp['b']) as $mzpp): if ($is_dup_line($mzpp)) continue; ?>
                    <p data-ui="u-57ce491f uda udh uer ufu"><?php echo esc($mzpp); ?></p>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($mzFeat): ?>
            <div data-ui="u-0c485645 ufv uf6 ug6 ufg ue1 ufp ues">
                <h4 data-ui="u-4c5c067e udu ufy uee ug7">✅ مميزات المنتج</h4>
                <?php foreach ($mzFeat as $mzf): if ($is_dup_line($mzf)) continue; ?>
                <div data-ui="u-29e370b6 ufd ug5 udi ug0 ueg ug6 ug9 ue9 ueq"><?php echo esc($mzf); ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (trim((string)($ex['dimensions'] ?? '')) !== ''): ?>
            <div data-ui="u-b91a8af0 ufs uga ugb udf">
                <h4 data-ui="u-2ff489cc ue6 ufy ufd ugc">📐 الأبعاد والقياسات</h4>
                <div data-ui="u-03872f9d ufd ugd udi"><?php echo nl2br(esc($ex['dimensions'])); ?></div>
            </div>
            <?php endif; ?>

        <?php else:
            $src_text = $raw_desc;
            if ($plat === 'angazny') {
                $ag_t = ag_ad_text($raw_desc);
                if ($ag_t === '' && !empty($ex['marketing_desc'])) $ag_t = ag_ad_text((string)$ex['marketing_desc']);
                if ($ag_t !== '') $src_text = $ag_t;
            }
            $blocks = sf_blocks($src_text, $plat === 'vendoor' ? 'vendoor' : 'safqa');
        ?>
            <?php if ($blocks): ?>
            <div data-ui="u-e09bf5ad ue1 ufp ues">
                <?php foreach ($blocks as $b):
                    if (!empty($b['b']) && $is_dup_line($b['b'])) continue;
                ?>
                    <?php if ($b['t'] === 'title'): ?>
                        <div data-ui="u-c076a543 uea ufw ufx udf udu uge ufz"><?php echo esc($b['b']); ?></div>
                    <?php elseif ($b['t'] === 'head'): ?>
                        <h4 data-ui="u-c4a586e9 udu ug8 uge uej"><?php echo esc($b['b']); ?></h4>
                    <?php elseif ($b['t'] === 'bullet'): ?>
                        <div data-ui="u-ae04ae78 ug0 uf6 ug6 ug9 ue9 ufd ug5 udi ueq"><?php echo esc($b['b']); ?></div>
                    <?php elseif ($b['t'] === 'item'): ?>
                        <div data-ui="u-625c78f5 ug0 udd ude ugf ue9 ufd ug5 uer ug3"><?php echo esc($b['b']); ?></div>
                    <?php elseif ($b['t'] === 'kv'): if (preg_match('/أ?لوان|الوان|مقاس|اسم المنتج/u', (string)$b['a'])) continue; ?>
                        <div data-ui="u-4ac67933 ue1 ue2 ugg ugh ug0 udd ude ue9 ufd"><span data-ui="u-b9561a7b udy ueq"><?php echo esc($b['a']); ?></span><b data-ui="u-543b2bfb uej ug4"><?php echo esc($b['b']); ?></b></div>
                    <?php elseif ($b['t'] === 'warranty'): ?>
                        <div data-ui="u-3d032e80 ug0 uf6 ugi ue9 udg ue6 ugj">🛡️ <?php echo esc($b['b']); ?></div>
                    <?php elseif ($b['t'] === 'cta'): ?>
                        <div data-ui="u-c95ee1a6 uea uga ugb ue9 ufd ue6 ugc udn"><?php echo esc($b['b']); ?></div>
                    <?php elseif ($b['t'] === 'sep'): ?>
                    <?php else: ?>
                        <div data-ui="u-c73100d9 uda ugk uer ug0 udd ude ue9">
                            <?php foreach (th_paras($b['b']) as $pp): if ($is_dup_line($pp)) continue; ?>
                            <p data-ui="u-21445313 ugl"><?php echo esc($pp); ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php elseif (!empty($ex['description_html'])): ?>
                <div class="product-description-body" data-ui="u-5fca13d5 udh uer uda"><?php echo $ex['description_html']; ?></div>
            <?php else: ?>
                <div data-ui="u-68a89660 ufv udd ude ufg udh uda uer"><?php echo nl2br(esc($src_text ?: $dname)); ?></div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- 2. المواصفات الأساسية مرتبة تحت بعض بدون تكرار -->
        <div data-ui="u-d5ccb671 ue1 ufp ues ueg ude ufg ufv">
            <h4 data-ui="u-2cdd8e73 udu ufy uee uej">📋 المواصفات</h4>
            <?php if ($cat_name !== ''): ?>
            <div data-ui="u-cfe2e3f5 ue1 ue2 ue7 ug0 udd ude ue9 ufd">
                <span data-ui="u-b9561a7b udy ueq">القسم</span>
                <b data-ui="u-f73bb9d7 uej"><?php echo esc($cat_name); ?></b>
            </div>
            <?php endif; ?>
            <?php if ($sku_val !== '' && !$is_landing): ?>
            <div data-ui="u-cfe2e3f5 ue1 ue2 ue7 ug0 udd ude ue9 ufd">
                <span data-ui="u-b9561a7b udy ueq">كود المنتج</span>
                <b dir="ltr" data-ui="u-f48fa36b uej ugm"><?php echo esc($sku_val); ?></b>
            </div>
            <?php endif; ?>
            <?php if (!empty($ex['material'])): ?>
            <div data-ui="u-cfe2e3f5 ue1 ue2 ue7 ug0 udd ude ue9 ufd">
                <span data-ui="u-b9561a7b udy ueq">الخامة / المادة</span>
                <b data-ui="u-f73bb9d7 uej"><?php echo esc($ex['material']); ?></b>
            </div>
            <?php endif; ?>
            <?php if (!empty($ex['weight'])): ?>
            <div data-ui="u-cfe2e3f5 ue1 ue2 ue7 ug0 udd ude ue9 ufd">
                <span data-ui="u-b9561a7b udy ueq">الوزن</span>
                <b data-ui="u-f73bb9d7 uej"><?php echo esc($ex['weight']); ?></b>
            </div>
            <?php endif; ?>
            <?php if (!empty($colors_clean)): ?>
            <div data-ui="u-cd2d7a9d ue1 ufp uft ug0 udd ude ue9 ufd">
                <span data-ui="u-b9561a7b udy ueq">🎨 الألوان المتاحة</span>
                <div data-ui="u-e7351c2c ue1 uft uet">
                    <?php foreach (array_slice($colors_clean, 0, 20) as $cln): ?>
                    <span data-ui="u-f507e374 ueg ugn uej ugo uds uf1 ue6"><?php echo esc($cln); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($sizes_clean)): ?>
            <div data-ui="u-cd2d7a9d ue1 ufp uft ug0 udd ude ue9 ufd">
                <span data-ui="u-b9561a7b udy ueq">📏 المقاسات المتاحة</span>
                <div data-ui="u-e7351c2c ue1 uft uet">
                    <?php foreach (array_slice($sizes_clean, 0, 25) as $szn): ?>
                    <span data-ui="u-409df7bf uga ugb ugc ugo ugp uf1 ue6"><?php echo esc($szn); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- 3. جدول المقاسات وصور التفاصيل مرتبة تحت بعض -->
        <?php
        $scHtml = !empty($ex['size_chart']) ? vd_size_chart_html((string)$ex['size_chart']) : '';
        $detImgsRaw = array_values(array_filter((array)($ex['detail_images'] ?? []), function ($u) use ($p) {
            return is_string($u) && trim($u) !== '' && !in_array($u, (array)($p['images'] ?? []), true);
        }));
        ?>
        <?php if ($scHtml !== '' || !empty($detImgsRaw)): ?>
        <div data-ui="u-a2a3da72 ufv udd ude ufg ue1 ufp ugg">
            <h4 data-ui="u-188bfe09 udu ufu uee uej">📏 جدول المقاسات وصور التوضيح</h4>
            <?php if ($scHtml !== ''): ?>
            <div><?php echo $scHtml; ?></div>
            <?php endif; ?>
            <?php if (!empty($detImgsRaw)): ?>
            <div data-ui="u-703b0517 ue1 ufp ugg">
                <?php foreach (array_slice($detImgsRaw, 0, 8) as $dim):
                    $dimUrl = $fix_img($dim);
                ?>
                <img loading="lazy" referrerpolicy="no-referrer" src="<?php echo esc($dimUrl); ?>" onclick="if(typeof openImgModal==='function')openImgModal(this.src)" onerror="this.style.display='none'" data-ui="u-309cfb72 uem ugq ugr ueg udf ude ugs ugt">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- 4. الأسئلة الشائعة مرتبة تحت بعض -->
        <?php if (!empty($ex['faqs']) && is_array($ex['faqs'])): ?>
        <div data-ui="u-3408f2d0 udd ude ufg ufv ue1 ufp ufj">
            <h4 data-ui="u-188bfe09 udu ufu uee uej">❓ أسئلة شائعة</h4>
            <?php foreach (array_slice($ex['faqs'], 0, 6) as $f): ?>
            <div data-ui="u-7bc496cd ugu ueg ude ue9">
                <b data-ui="u-f42ba091 ufd uej ugv uew"><?php echo is_array($f) ? esc($f['q'] ?? $f['question'] ?? '') : ''; ?></b>
                <p data-ui="u-33e0306e ufu udg uek ugw"><?php echo is_array($f) ? esc(mb_substr($f['a'] ?? $f['answer'] ?? '', 0, 350)) : esc(mb_substr((string)$f, 0, 250)); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($ex['specifications']) || !empty($ex['features'])): ?>
        <div class="wf-admin-product-specs" style="margin:14px 0;padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#fff">
            <?php if (!empty($ex['features'])): ?><h4>المميزات</h4><div><?php echo nl2br(esc((string)$ex['features'])); ?></div><?php endif; ?>
            <?php if (!empty($ex['specifications'])): ?><h4>المواصفات</h4><div><?php echo nl2br(esc((string)$ex['specifications'])); ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (empty($opts['hide_media']) && function_exists('wf_product_media_html')) echo wf_product_media_html($p, $in_root, $plat); ?>
    </div>
    <?php
    return ob_get_clean();
}
