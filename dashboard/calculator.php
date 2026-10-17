<?php $PUBLIC_PAGE = true; require 'layout.php';
layout_top('حاسبة الأرباح ', 'products');
echo '<div class="card" data-ui="u-67a78532 d6g"><h3>احسب ربحك المتوقع</h3><p class="field-hint">نموذج تقديري قابل للتعديل، وليس دخلاً فعلياً أو مضموناً. لا يغيّر أسعار الموقع أو العمولات.</p>';
echo '<label class="field-label" for="c_cost">سعر الشراء/التكلفة</label><input id="c_cost" type="number" value="200" data-ui="u-5438a54e dx d6h d6i d6j d3k">';
echo '<label class="field-label" for="c_price">سعر البيع</label><input id="c_price" type="number" value="350" data-ui="u-5438a54e dx d6h d6i d6j d3k">';
echo '<label class="field-label" for="c_ship">الشحن</label><input id="c_ship" type="number" value="60" data-ui="u-5438a54e dx d6h d6i d6j d3k">';
echo '<label class="field-label" for="c_rate">نسبة التسليم المتوقعة %</label><input id="c_rate" type="number" value="50" data-ui="u-5438a54e dx d6h d6i d6j d3k">';
echo '<div class="pricebox"><b>صافي الربح المتوقع/طلب: <span id="c_out">—</span> جنيه</b></div>';

echo '</div>';
layout_end();
