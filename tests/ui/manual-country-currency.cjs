// Optional dev check: manual products per country with the country's own currency.
// Runs the real PHP 8.3 in WASM (no php CLI needed) against config.php + manual_products_lib.php,
// plus source-level checks for the storefront wiring. Nothing is written to production data.
//   npm install --prefix .cache/ui-tools @php-wasm/node php-parser
//   node tests/ui/manual-country-currency.cjs
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert');
const repo=path.resolve(__dirname,'../..');const NM=path.join(repo,'.cache/ui-tools/node_modules');
const wasmDir=path.join(NM,'@php-wasm/node-8-3/asyncify');
if(!fs.existsSync(path.join(NM,'@php-wasm/node')))throw new Error('Missing @php-wasm/node — run: npm install --prefix .cache/ui-tools @php-wasm/node php-parser');
const {PHP}=require(path.join(NM,'@php-wasm/universal'));
const {loadNodeRuntime}=require(path.join(NM,'@php-wasm/node'));
// config.php requires these siblings at include time (amazon/jumia/noon are file_exists-guarded and skipped).
const FILES=['config.php','manual_products_lib.php','customer_req_lib.php','supabase.php','supabase_users.php','supabase_docs.php','hevo_api.php','ui-components.php','manual_products_import.csv'];
// جداول الشحن بتتحمل من DATA_DIR (/app/data) — بننسخها عشان الاختبار يشتغل على البيانات الحقيقية
const DATA_FILES=['data/shipping.json','data/shipping_cities_shopmart.json','data/shipping_cities_gulf.json'];
const PHP_TESTS=String.raw`
<?php
require '/app/config.php';
$fail=0;$out=[];
function chk(&$out,&$fail,$name,$got,$want){$ok=($got===$want);if(!$ok)$fail++;$out[$name]=$ok?'OK':['FAIL','got'=>$got,'want'=>$want];}
// ---- 1) مصدر واحد للحققيقة: الدول + العملات ----
chk($out,$fail,'country list',wf_country_list(),['مصر','الإمارات','السعودية','الأردن','عُمان']);
chk($out,$fail,'currency map',wf_country_currencies(),['مصر'=>'ج.م','الإمارات'=>'د.إ','السعودية'=>'ر.س','الأردن'=>'د.أ','عُمان'=>'ر.ع']);
chk($out,$fail,'gulf list',wf_gulf_countries(),['الإمارات','السعودية','الأردن','عُمان']);
chk($out,$fail,'gulf flag saudi',wf_is_gulf_country('السعودية'),true);
chk($out,$fail,'gulf flag egypt',wf_is_gulf_country('مصر'),false);
chk($out,$fail,'gulf flag oman-no-haraka',wf_is_gulf_country('عمان'),true);
// ---- 2) مطابقة الدولة من اسم/عملة/كود إنجليزي ----
foreach(['السعودية'=>'السعودية','سعودي'=>'السعودية','KSA'=>'السعودية','Saudi Arabia'=>'السعودية','ريال'=>'السعودية','ر.س'=>'السعودية','ريال سعودي'=>'السعودية',
         'الإمارات'=>'الإمارات','الامارات'=>'الإمارات','uae'=>'الإمارات','AED'=>'الإمارات','درهم'=>'الإمارات','د.إ'=>'الإمارات','د. إ'=>'الإمارات',
         'الأردن'=>'الأردن','الاردن'=>'الأردن','jod'=>'الأردن','دينار'=>'الأردن','د.أ'=>'الأردن','د. أ'=>'الأردن',
         'عُمان'=>'عُمان','عمان'=>'عُمان','OMR'=>'عُمان','ريال عماني'=>'عُمان','ر.ع'=>'عُمان',
         'مصر'=>'مصر','Egypt'=>'مصر','egp'=>'مصر','جنيه'=>'مصر','ج.م'=>'مصر'] as $in=>$want) chk($out,$fail,'match '.$in,wf_match_country($in,'?'),$want);
chk($out,$fail,'match unknown',wf_match_country('المريخ','?'),'?');
chk($out,$fail,'match empty keeps fallback',wf_match_country('  ','مصر'),'مصر');
chk($out,$fail,'currency from name',wf_country_currency('السعودية'),'ر.س');
chk($out,$fail,'currency from currency word',wf_country_currency('درهم'),'د.إ');
chk($out,$fail,'currency fallback',wf_country_currency(''),'ج.م');
// ---- 3) منتج يدوي = سعر بعملة دولته (من غير أي تحويل) ----
function mrow($id,$name,$price,$cat,$details){return ['id'=>$id,'name'=>$name,'price'=>$price,'category'=>$cat,'stock'=>5,'description'=>'وصف','image_url'=>'','details'=>json_encode($details,JSON_UNESCAPED_UNICODE)];}
$sa=manual_row_to_product(mrow(7,'منتج سعودي','250','إلكترونيات',['country'=>'السعودية','images'=>[],'colors'=>[],'sizes'=>[],'sku'=>'','material'=>'','weight'=>'','dimensions'=>'','faqs'=>[]]));
chk($out,$fail,'sa country',$sa['country'],'السعودية');
chk($out,$fail,'sa currency',$sa['currency'],'ر.س');
chk($out,$fail,'sa price_text',$sa['price_text'],'250 ر.س');
chk($out,$fail,'sa price not converted',$sa['price'],250.0);
$ae=manual_row_to_product(mrow(8,'منتج إماراتي','99.5','إكسسوارات',['country'=>'الامارات العربية المتحدة']));
chk($out,$fail,'ae currency',$ae['currency'],'د.إ');
chk($out,$fail,'ae price_text',$ae['price_text'],'99.5 د.إ');
foreach([['مصر','ج.م'],['السعودية','ر.س'],['الإمارات','د.إ'],['الأردن','د.أ'],['عُمان','ر.ع']] as $pair){
  $p=manual_row_to_product(mrow(9,'منتج','12.34','ساعات',['country'=>$pair[0]]));
  chk($out,$fail,'currency of '.$pair[0],$p['currency'].' / '.$p['price_text'],$pair[1].' / 12.34 '.$pair[1]);
}
// صفوف قديمة من غير details.country = مصر / ج.م (توافق رجعي)
chk($out,$fail,'legacy no country',manual_row_to_product(mrow(1,'منتج قديم','110','العناية والتجميل',['sku'=>'X']))['currency'],'ج.م');
chk($out,$fail,'legacy no details',manual_row_to_product(['id'=>2,'name'=>'من غير تفاصيل','price'=>'50','category'=>'ساعات','stock'=>1,'description'=>'','image_url'=>'','details'=>null])['country'],'مصر');
// ---- 4) التحقق: دولة غلط مرفوضة عشان السعر مايتسجلش بعملة غلط ----
list($c,$e)=manual_validate_row(['name'=>'منتج','price'=>'300','country'=>'السعودية','category'=>'إلكترونيات','stock'=>'','images'=>'https://example.com/a.jpg']);
chk($out,$fail,'validate ok',$e,'');
chk($out,$fail,'validate country',$c['country'],'السعودية');
chk($out,$fail,'validate stock default',$c['stock'],999999);
chk($out,$fail,'validate empty country = مصر',manual_validate_row(['name'=>'منتج','price'=>'300','country'=>'','category'=>'إلكترونيات'])[0]['country'],'مصر');
chk($out,$fail,'validate currency word = country',manual_validate_row(['name'=>'منتج','price'=>'300','country'=>'ر.س','category'=>'إلكترونيات'])[0]['country'],'السعودية');
list($bad,$baderr)=manual_validate_row(['name'=>'منتج','price'=>'300','country'=>'المريخ','category'=>'إلكترونيات']);
chk($out,$fail,'validate bad country rejected',$bad,null);
chk($out,$fail,'validate bad msg',mb_strpos($baderr,'الدولة مش معروفة: المريخ')===0&&mb_strpos($baderr,'عُمان')!==false,true);
chk($out,$fail,'digital category still rejected',manual_validate_row(['name'=>'منتج','price'=>'300','country'=>'السعودية','category'=>'منتجات رقمية'])[0],null);
// ---- 5) التخزين في details JSON + رجوع تاني لمنتج ----
$c['description']=$c['description']??'';
$json=manual_details_json($c);
chk($out,$fail,'details stores country',json_decode($json,true)['country'],'السعودية');
chk($out,$fail,'details never stores currency',array_key_exists('currency',json_decode($json,true)),false);
$rt=manual_row_to_product(mrow(9,$c['name'],$c['price'],$c['category'],json_decode($json,true)));
chk($out,$fail,'roundtrip',$rt['country'].' / '.$rt['price_text'],'السعودية / 300 ر.س');
chk($out,$fail,'details unknown country = مصر',json_decode(manual_details_json(['images'=>[],'colors'=>[],'sizes'=>[],'sku'=>'','material'=>'','weight'=>'','dimensions'=>'','faqs'=>[],'country'=>'المريخ']),true)['country'],'مصر');
// ---- 6) CSV: عمود country اختياري والترتيب حر ----
$csv="name,price,country,category,stock,sku,description,colors,sizes,material,weight,dimensions,images,faqs\n"
   ."منتج سعودي,250,السعودية,إلكترونيات,10,SKU1,وصف,أسود|أبيض,,,,,https://example.com/a.jpg,السؤال::الجواب\n"
   ."منتج إماراتي,99,uae,إكسسوارات,,,,,,,,https://example.com/b.jpg,\n"
   ."منتج من غير دولة,50,,ساعات,,,,,,,,,\n"
   ."منتج دولة غلط,50,المريخ,ساعات,,,,,,,,,\n";
file_put_contents('/app/t.csv',"\xEF\xBB\xBF".$csv);
list($rows,$errs)=manual_parse_csv('/app/t.csv');
chk($out,$fail,'csv valid rows',count($rows),3);
chk($out,$fail,'csv rejected rows',count($errs),1);
chk($out,$fail,'csv countries',[$rows[0]['country'],$rows[1]['country'],$rows[2]['country']],['السعودية','الإمارات','مصر']);
chk($out,$fail,'csv error names the country',mb_strpos($errs[0],'الدولة مش معروفة: المريخ')!==false,true);
file_put_contents('/app/t_old.csv',"name,price,category,stock,sku,description,colors,sizes,material,weight,dimensions,images,faqs\nمنتج قديم,110,العناية والتجميل,5,,,,,,,,\n");
list($old,$olderrs)=manual_parse_csv('/app/t_old.csv');
chk($out,$fail,'legacy csv still imports',count($old),1);
chk($out,$fail,'legacy csv country',$old[0]['country'],'مصر');
chk($out,$fail,'legacy csv no errors',count($olderrs),0);
file_put_contents('/app/t_ord.csv',"country,name,price,category\nالسعودية,منتج,20,إلكترونيات\n");
chk($out,$fail,'csv column order free',manual_parse_csv('/app/t_ord.csv')[0][0]['country'],'السعودية');
// القالب الموجود في الريبو نفسه لازم يستورد كله بعد إضافة العمود
list($tpl,$tplerr)=manual_parse_csv('/app/manual_products_import.csv');
chk($out,$fail,'repo csv template rows',count($tpl),4);
chk($out,$fail,'repo csv template errors',count($tplerr)?$tplerr:0,0);
chk($out,$fail,'repo csv template country',array_values(array_unique(array_column($tpl,'country'))),['مصر']);
// ---- 7) العرض: العملة بتتحسب من الدولة في مكوّنات الواجهة ----
require_once '/app/ui-components.php';
chk($out,$fail,'ui currency saudi',wf_ui_currency($sa),'ر.س');
chk($out,$fail,'ui currency emirati',wf_ui_currency($ae),'د.إ');
chk($out,$fail,'ui currency default',wf_ui_currency(['price'=>100]),'ج.م');
// منتجات يدوية مش بعمولة — السعر المخزّن هو سعر البيع
chk($out,$fail,'manual no commission',wf_plat_no_commission('manual'),true);
// ---- 8) الشحن حسب الدولة: المنتجات اليدوية ----
$th = site_ship_regions('timehand');
chk($out,$fail,'ship manual egypt = timehand',site_ship_regions('manual','مصر'),$th);
chk($out,$fail,'ship manual default = egypt',site_ship_regions('manual'),$th);
chk($out,$fail,'ship manual egypt has cairo',in_array('القاهرة',array_column(site_ship_regions('manual','مصر'),'region'),true),true);
chk($out,$fail,'ship currency egypt',wf_ship_currency('manual','مصر'),'ج.م');
chk($out,$fail,'ship region label egypt',wf_ship_region_label('مصر'),'المحافظة');
chk($out,$fail,'ship region label gulf',wf_ship_region_label('السعودية'),'المنطقة / المدينة');
$gulf_tables=['السعودية'=>[13,'الرياض','ر.س'],'الإمارات'=>[7,'دبي','د.إ'],'الأردن'=>[12,'عمّان','د.أ'],'عُمان'=>[11,'مسقط','ر.ع']];
foreach($gulf_tables as $cn=>$spec){
  $m=site_ship_map('manual',$cn);
  chk($out,$fail,'ship '.$cn.' regions',count($m),(int)$spec[0]);
  chk($out,$fail,'ship '.$cn.' has '.$spec[1],isset($m[$spec[1]]),true);
  chk($out,$fail,'ship '.$cn.' no cairo',isset($m['القاهرة']),false);
  chk($out,$fail,'ship '.$cn.' no giza',isset($m['الجيزة']),false);
  chk($out,$fail,'ship '.$cn.' fees zero until owner fills',(float)array_sum($m),0.0);
  chk($out,$fail,'ship '.$cn.' currency',wf_ship_currency('manual',$cn),$spec[2]);
  chk($out,$fail,'ship '.$cn.' table currency',(string)(site_ship_regions('manual',$cn)[0]['currency'] ?? ''),$spec[2]);
}
// الاسم من غير تشكيل برضوه بيوصل لنفس الجدول
chk($out,$fail,'ship oman alias',count(site_ship_map('manual','عمان')),11);
chk($out,$fail,'ship saudi alias ksa',count(site_ship_map('manual','KSA')),13);
// دولة/قيمة مش معروفة ⇒ مصر (نفس قاعدة الموقع في كل مكان)
chk($out,$fail,'ship unknown country = egypt',count(site_ship_map('manual','المريخ')),27);
// دولة مش في قايمة الموقع (الكويت مثلاً) ⇒ معاملة مصر، زي كل مكان تاني في الموقع
chk($out,$fail,'ship unsupported country = egypt',site_ship_regions('manual','الكويت'),$th);
// دولة خليجية موجودة في القايمة لكن جدولها اتحذف من shipping.json ⇒ مفيش مناطق خالص
// (أحسن من رسوم دولة تانية أو محافظات مصرية) — الحارس ده في site_ship_regions
// منتج ⇒ دولته
chk($out,$fail,'product country saudi',wf_product_country(['country'=>'السعودية']),'السعودية');
chk($out,$fail,'product country default',wf_product_country(['id'=>'manual-3']),'مصر');
chk($out,$fail,'product country normalized',wf_product_country(['country'=>'الامارات']),'الإمارات');
// ---- 8b) نافذة الطلب السريع بتترسم بمناطق الدولة الصح (HTML فعلي) ----
$_SERVER['SCRIPT_NAME']='/dashboard/product.php';
ob_start();render_easyorder_dialog_html('','manual','ر.س','السعودية');$__dlg=(string)ob_get_clean();
chk($out,$fail,'dialog renders',mb_strlen($__dlg)>1000,true);
chk($out,$fail,'dialog has saudi region option',mb_strpos($__dlg,'>الرياض (الشحن: 0 ر.س)</option>')!==false,true);
chk($out,$fail,'dialog has no egyptian governorates',mb_strpos($__dlg,'>القاهرة (الشحن:')===false,true);
chk($out,$fail,'dialog region word is saudi',mb_strpos($__dlg,'المنطقة / المدينة')!==false,true);
chk($out,$fail,'dialog select currency attr',mb_strpos($__dlg,'data-ship-currency="ر.س"')!==false,true);
chk($out,$fail,'dialog ships country tables to js',mb_strpos($__dlg,'manual:السعودية')!==false&&mb_strpos($__dlg,'id="wfShippingCurrency"')!==false,true);
chk($out,$fail,'dialog currency state has sar',mb_strpos($__dlg,'"manual:\u0627\u0644\u0633\u0639\u0648\u062f\u064a\u0629":"\u0631.\u0633"')!==false||mb_strpos($__dlg,'"manual:السعودية":"ر.س"')!==false,true);
chk($out,$fail,'dialog has no egp shipping label for saudi',mb_strpos($__dlg,'(الشحن: 0 ج.م)')===false,true);
chk($out,$fail,'dialog city state has gulf cities',mb_strpos($__dlg,'id="wfCityState"')!==false&&mb_strpos($__dlg,'"الرياض":')!==false&&mb_strpos($__dlg,'"دبي":')!==false,true);
chk($out,$fail,'dialog city state keeps egypt',mb_strpos($__dlg,'"القاهرة":')!==false,true);
// ملاحظة: النافذة بتترسم مرة واحدة في الطلب (static \$rendered guard) فبنرسم حالة الخليج هنا —
// وحالة مصر مغطاة بفحوص البيانات فوق (manual + مصر = جدول تايم هاند بالقاهرة والجنيه و«المحافظة»).
chk($out,$fail,'dialog egypt data path',isset(site_ship_map('manual','مصر')['القاهرة'])&&wf_ship_currency('manual','مصر')==='ج.م'&&wf_ship_region_label('مصر')==='المحافظة',true);
// النافذة بتبعت جدول كل منصة ليها دولة (يدوي + مخزون) للواجهة: مفاتيح platform و platform:country
preg_match('#id="wfShippingState">(.*?)</script>#s',$__dlg,$__sm);
$__state=json_decode($__sm[1] ?? '',true) ?: [];
preg_match('#id="wfShippingCurrency">(.*?)</script>#s',$__dlg,$__cm);
$__curs=json_decode($__cm[1] ?? '',true) ?: [];
chk($out,$fail,'dialog state parsed',count($__state)>10,true);
foreach(['السعودية'=>'ر.س','الإمارات'=>'د.إ','الأردن'=>'د.أ','عُمان'=>'ر.ع'] as $__cn=>$__cs){
  chk($out,$fail,'dialog state key m5zoon:'.$__cn,isset($__state['m5zoon:'.$__cn]),true);
  chk($out,$fail,'dialog state m5zoon:'.$__cn.' no cairo',isset($__state['m5zoon:'.$__cn]['القاهرة']),false);
  chk($out,$fail,'dialog currency key m5zoon:'.$__cn,$__curs['m5zoon:'.$__cn] ?? '',$__cs);
  chk($out,$fail,'dialog currency key manual:'.$__cn,$__curs['manual:'.$__cn] ?? '',$__cs);
}
chk($out,$fail,'dialog state m5zoon saudi fees',[25.0],array_values(array_unique(array_map('floatval',array_values($__state['m5zoon:السعودية'] ?? [])))));
chk($out,$fail,'dialog state manual saudi fees',[0.0],array_values(array_unique(array_map('floatval',array_values($__state['manual:السعودية'] ?? [])))));
chk($out,$fail,'dialog state m5zoon default is egypt',isset($__state['m5zoon']['القاهرة']),true);
chk($out,$fail,'dialog currency m5zoon default',($__curs['m5zoon'] ?? ''),'ج.م');
// مفتاح default = حالة الصفحة اللي ات رسمت (هنا: يدوي/السعودية) — مصر مغطّاة بفحوص البيانات فوق
chk($out,$fail,'dialog currency default key = rendered case',($__curs['default'] ?? ''),'ر.س');
chk($out,$fail,'dialog default state = rendered case',($__state['default'] ?? []),($__state['manual:السعودية'] ?? []));
chk($out,$fail,'dialog rendered saudi uses manual table',mb_strpos($__dlg,'data-ship-currency="ر.س"')!==false,true);
chk($out,$fail,'dialog district placeholder follows country',mb_strpos($__dlg,'-- اختر المنطقة أولاً --')!==false&&mb_strpos($__dlg,'-- اختر المحافظة أولاً --')===false,true);
// ---- 8c) مدن دول الخليج (القايمة التانية بقت منسدلة زي مصر) ----
$eg_map = wf_ship_cities_map();
chk($out,$fail,'cities egypt default count',count($eg_map),26);
chk($out,$fail,'cities egypt cairo count',count($eg_map['القاهرة'] ?? []),54);
chk($out,$fail,'cities egypt has no riyadh',isset($eg_map['الرياض']),false);
chk($out,$fail,'governorates list stays egypt only',in_array('دبي',wf_ship_governorates(),true),false);
$gulf_cities=['السعودية'=>['الرياض','الرياض','مكة المكرمة','جدة','المنطقة الشرقية','الدمام'],
             'الإمارات'=>['أبو ظبي','أبو ظبي','دبي','دبي','رأس الخيمة','رأس الخيمة'],
             'الأردن'=>['عمّان','عمّان','العقبة','العقبة','إربد','إربد'],
             'عُمان'=>['مسقط','مسقط','ظفار','صلالة','الداخلية','نزوى']];
foreach($gulf_cities as $cn=>$spec){
  $cm = wf_ship_cities_map($cn);
  $regions = array_keys(site_ship_map('manual',$cn));
  chk($out,$fail,'cities '.$cn.' cover every region',count(array_intersect($regions,array_keys($cm))),count($regions));
  $empty = [];
  foreach($regions as $r) if (empty($cm[$r])) $empty[] = $r;
  chk($out,$fail,'cities '.$cn.' all regions have cities',$empty,[]);
  chk($out,$fail,'cities '.$cn.' first pair',wf_ship_cities($spec[0],$cn)[0] ?? '',$spec[1]);
  chk($out,$fail,'cities '.$cn.' second pair',in_array($spec[3],wf_ship_cities($spec[2],$cn),true),true);
  chk($out,$fail,'cities '.$cn.' third pair',in_array($spec[5],wf_ship_cities($spec[4],$cn),true),true);
  chk($out,$fail,'cities '.$cn.' no egyptian city',in_array('مدينة نصر',wf_ship_cities($spec[0],$cn),true),false);
}
// أسماء مناطق الخليج مش بتتعارض مع محافظات مصر (وإلا القايمة الغلط هتظهر للعميل)
$collision = array_values(array_intersect(array_keys($eg_map), array_keys(wf_ship_cities_map('السعودية')), array_keys(wf_ship_cities_map('الإمارات')), array_keys(wf_ship_cities_map('الأردن')), array_keys(wf_ship_cities_map('عُمان'))));
chk($out,$fail,'no region name collisions with egypt',$collision,[]);
$all = wf_ship_cities_map_all();
chk($out,$fail,'merged map has cairo',count($all['القاهرة'] ?? []),54);
chk($out,$fail,'merged map has riyadh',in_array('الخرج',$all['الرياض'] ?? [],true),true);
chk($out,$fail,'merged map has dubai',in_array('حتا',$all['دبي'] ?? [],true),true);
chk($out,$fail,'merged map size',count($all) >= 26 + 13 + 7 + 12 + 11,true);
// الاسم من غير تشكيل/KSA برضوه بيوصل لقايمة الدولة
chk($out,$fail,'cities alias oman',count(wf_ship_cities_map('عمان')),11);
chk($out,$fail,'cities alias ksa',count(wf_ship_cities_map('KSA')),13);
// ---- 9) رجعة: باقي المنصات ما اتغيّرتش ----
chk($out,$fail,'ship cezma = timehand',site_ship_regions('cezma'),$th);
chk($out,$fail,'ship vendoor 27',count(site_ship_map('vendoor')),27);
chk($out,$fail,'ship timehand 27',count(site_ship_map('timehand')),27);
chk($out,$fail,'ship safqa -> safka 26',count(site_ship_map('safqa')),26);
chk($out,$fail,'ship shopmart 26',count(site_ship_map('shopmart')),26);
chk($out,$fail,'ship unknown plat falls back',count(site_ship_map('hevo')),30);
// ---- 9b) مخزون: عنوان الطلب يعرض مناطق دولة المنتج — مش محافظات مصر (شكوى 2026-10-20) ----
chk($out,$fail,'ship m5zoon default = egypt timehand',site_ship_regions('m5zoon'),$th);
chk($out,$fail,'ship m5zoon egypt has cairo',isset(site_ship_map('m5zoon','مصر')['القاهرة']),true);
chk($out,$fail,'ship m5zoon unknown country = egypt',count(site_ship_map('m5zoon','المريخ')),27);
chk($out,$fail,'ship country plats',site_ship_country_plats(),['manual','m5zoon']);
$mz_tables=['السعودية'=>[13,'الرياض',25.0,'ر.س'],'الإمارات'=>[7,'دبي',25.0,'د.إ'],'الأردن'=>[12,'عمّان',3.0,'د.أ'],'عُمان'=>[11,'مسقط',2.5,'ر.ع']];
foreach($mz_tables as $cn=>$spec){
  $m=site_ship_map('m5zoon',$cn);
  chk($out,$fail,'mz '.$cn.' regions',count($m),(int)$spec[0]);
  chk($out,$fail,'mz '.$cn.' has '.$spec[1],isset($m[$spec[1]]),true);
  chk($out,$fail,'mz '.$cn.' no cairo',isset($m['القاهرة']),false);
  chk($out,$fail,'mz '.$cn.' no giza',isset($m['الجيزة']),false);
  // الرسوم = سعر الدولة الموحّد (قرار الإدارة)، وكل منطقة قابلة للتعديل من إعدادات الشحن
  chk($out,$fail,'mz '.$cn.' every region fee',array_values(array_unique(array_map('floatval',array_values($m)))),[$spec[2]]);
  chk($out,$fail,'mz '.$cn.' currency',wf_ship_currency('m5zoon',$cn),$spec[3]);
  chk($out,$fail,'mz '.$cn.' table currency',(string)(site_ship_regions('m5zoon',$cn)[0]['currency'] ?? ''),$spec[3]);
  chk($out,$fail,'mz '.$cn.' region label',wf_ship_region_label($cn),'المنطقة / المدينة');
  chk($out,$fail,'mz '.$cn.' regions = manual regions',array_keys($m),array_keys(site_ship_map('manual',$cn)));
  chk($out,$fail,'mz '.$cn.' cities covered',array_values(array_diff(array_keys($m),array_keys(wf_ship_cities_map($cn)))),[]);
}
chk($out,$fail,'ship default map is egypt',isset(site_ship_map()['القاهرة']),true);
// ---- 8) خصم الكمية (70 ج.م) للجنيه بس — قرار الإدارة 2026-10-20 ----
chk($out,$fail,'egp label ج.م',wf_is_egp_currency('ج.م'),true);
chk($out,$fail,'egp label EGP',wf_is_egp_currency('EGP'),true);
chk($out,$fail,'egp label جنيه مصري',wf_is_egp_currency('جنيه مصري'),true);
chk($out,$fail,'egp label empty = unknown = egp',wf_is_egp_currency(''),true);
chk($out,$fail,'egp label ر.س',wf_is_egp_currency('ر.س'),false);
chk($out,$fail,'egp label د.إ',wf_is_egp_currency('د.إ'),false);
chk($out,$fail,'egp label درهم',wf_is_egp_currency('درهم'),false);
chk($out,$fail,'product currency from country',wf_product_currency(['country'=>'السعودية']),'ر.س');
chk($out,$fail,'product currency default',wf_product_currency(['price'=>100]),'ج.م');
chk($out,$fail,'product currency stored wins',wf_product_currency(['currency'=>'د.أ','country'=>'مصر']),'د.أ');
chk($out,$fail,'product currency m5zoon source wins',wf_product_currency(['country'=>'مصر','extra'=>['cost'=>'77 درهم']],'m5zoon'),'درهم');
chk($out,$fail,'discount egp 2 pcs',wf_bulk_discount(2,'manual','ج.م'),70.0);
chk($out,$fail,'discount egp 3 pcs',wf_bulk_discount(3,'manual','ج.م'),140.0);
chk($out,$fail,'discount unknown currency = egp',wf_bulk_discount(3,'manual'),140.0);
chk($out,$fail,'discount sar 3 pcs = 0',wf_bulk_discount(3,'manual','ر.س'),0.0);
chk($out,$fail,'discount aed word = 0',wf_bulk_discount(2,'m5zoon','درهم'),0.0);
chk($out,$fail,'discount from product array (saudi)',wf_bulk_discount(2,'manual',['country'=>'السعودية','price'=>250]),0.0);
chk($out,$fail,'discount from product array (egypt)',wf_bulk_discount(2,'manual',['country'=>'مصر','price'=>250]),70.0);
chk($out,$fail,'digital still excluded in egp',wf_bulk_discount(3,'g2g','ج.م'),0.0);
chk($out,$fail,'bundle total sar no discount',wf_bundle_total(250,2,'manual','ر.س'),500.0);
chk($out,$fail,'bundle total egp with discount',wf_bundle_total(100,2,'manual','ج.م'),130.0);
chk($out,$fail,'landing discount sar = 0',wf_landing_bundle_discount(3,false,'manual','ر.س'),0.0);
$st_sar=wf_bulk_discount_state('manual','ر.س');$st_egp=wf_bulk_discount_state('manual','ج.م');$st_none=wf_bulk_discount_state('');
chk($out,$fail,'state sar applies false',$st_sar['applies'],false);
chk($out,$fail,'state egp applies true',$st_egp['applies'],true);
chk($out,$fail,'state without product applies null',$st_none['applies'],null);
chk($out,$fail,'state egp_only flag',$st_sar['egp_only'],true);
chk($out,$fail,'state currency for js',$st_sar['currency'],'ر.س');
chk($out,$fail,'state egp labels for js',in_array('ج.م',$st_sar['egp'],true),true);
// ---- 10) سعر شحن المنتج نفسه: حقل في فورم المنتج اليدوي، بيتقدم على جدول مناطق الدولة ----
chk($out,$fail,'prod ship missing = null',wf_product_shipping(['id'=>'manual-1']),null);
chk($out,$fail,'prod ship empty = null',wf_product_shipping(['ship_fee'=>'']),null);
chk($out,$fail,'prod ship text = null',wf_product_shipping(['ship_fee'=>'abc']),null);
chk($out,$fail,'prod ship negative = null',wf_product_shipping(['ship_fee'=>'-5']),null);
chk($out,$fail,'prod ship not array = null',wf_product_shipping('منتج'),null);
chk($out,$fail,'prod ship zero = free',wf_product_shipping(['ship_fee'=>'0']),0.0);
chk($out,$fail,'prod ship int',wf_product_shipping(['ship_fee'=>25]),25.0);
chk($out,$fail,'prod ship decimal rounded',wf_product_shipping(['ship_fee'=>'12.345']),12.35);
chk($out,$fail,'prod ship clamp 2000',wf_product_shipping(['ship_fee'=>99999]),2000.0);
chk($out,$fail,'prod ship from extra',wf_product_shipping(['extra'=>['ship_fee'=>'7.5']]),7.5);
chk($out,$fail,'prod ship top level wins',wf_product_shipping(['ship_fee'=>'9','extra'=>['ship_fee'=>'3']]),9.0);
// دورة كاملة: فورم → تحقق → تفاصيل مخزنة → منتج → قيمة بتوصل الواجهة/الطلب
list($__c,$__e)=manual_validate_row(['name'=>'منتج بشحن','price'=>'300','country'=>'السعودية','category'=>'إلكترونيات','ship_fee'=>'25','images'=>'https://example.com/a.jpg']);
chk($out,$fail,'validate ship fee ok',$__e,'');
chk($out,$fail,'validate ship fee value',$__c['ship_fee'],25.0);
chk($out,$fail,'details json keeps ship fee',(float)(json_decode(manual_details_json($__c),true)['ship_fee'] ?? -1),25.0);
chk($out,$fail,'details json keeps country',json_decode(manual_details_json($__c),true)['country'],'السعودية');
$__stored=['id'=>31,'name'=>'منتج بشحن','price'=>300,'category'=>'إلكترونيات','stock'=>5,'description'=>'','image_url'=>'https://example.com/a.jpg','details'=>manual_details_json($__c)];
$__prod=manual_row_to_product($__stored);
chk($out,$fail,'stored product ship fee',$__prod['ship_fee'],25.0);
chk($out,$fail,'stored product currency',$__prod['currency'],'ر.س');
chk($out,$fail,'stored product ships its own fee',wf_product_shipping($__prod),25.0);
chk($out,$fail,'stored product country',$__prod['country'],'السعودية');
chk($out,$fail,'row ship fee passthrough',manual_row_to_product(mrow(11,'منتج','100','ساعات',['country'=>'الإمارات','ship_fee'=>12.5]))['ship_fee'],12.5);
chk($out,$fail,'row without ship fee = null',manual_row_to_product(mrow(12,'منتج','100','ساعات',['country'=>'مصر']))['ship_fee'],null);
list($__c2,$__e2)=manual_validate_row(['name'=>'منتج','price'=>'300','country'=>'مصر','category'=>'ساعات','ship_fee'=>'']);
chk($out,$fail,'validate ship fee optional',$__e2,'');
chk($out,$fail,'validate ship fee optional null',$__c2['ship_fee'],null);
chk($out,$fail,'details json ship fee null when unset',json_decode(manual_details_json($__c2),true)['ship_fee'],null);
chk($out,$fail,'legacy stored product has no own fee',wf_product_shipping(manual_row_to_product(['id'=>33,'name'=>'قديم','price'=>50,'category'=>'ساعات','stock'=>1,'description'=>'','image_url'=>'','details'=>manual_details_json($__c2)])),null);
list($__c3,$__e3)=manual_validate_row(['name'=>'منتج','price'=>'300','country'=>'السعودية','category'=>'إلكترونيات','ship_fee'=>'1,200']);
chk($out,$fail,'validate ship fee comma stripped',$__e3,'');
chk($out,$fail,'validate ship fee comma value',$__c3['ship_fee'] ?? null,1200.0);
list($__c4,$__e4)=manual_validate_row(['name'=>'منتج','price'=>'300','country'=>'السعودية','category'=>'إلكترونيات','ship_fee'=>'-1']);
chk($out,$fail,'validate ship fee negative rejected',$__e4,'سعر الشحن لازم يكون رقم صفر أو أكبر (منتج)');
list($__c5,$__e5)=manual_validate_row(['name'=>'منتج','price'=>'300','country'=>'السعودية','category'=>'إلكترونيات','ship_fee'=>'abc']);
chk($out,$fail,'validate ship fee text rejected',$__e5,'سعر الشحن لازم يكون رقم صفر أو أكبر (منتج)');
list($__c6,$__e6)=manual_validate_row(['name'=>'منتج','price'=>'300','country'=>'السعودية','category'=>'إلكترونيات','ship_fee'=>'5000']);
chk($out,$fail,'validate ship fee too big rejected',$__e6,'سعر الشحن كبير جداً (أقصى 2000) (منتج)');
list($__c7,$__e7)=manual_validate_row(['name'=>'منتج','price'=>'300','country'=>'السعودية','category'=>'إلكترونيات','ship_fee'=>'0']);
chk($out,$fail,'validate ship fee zero allowed',$__e7,'');
chk($out,$fail,'validate ship fee zero value',$__c7['ship_fee'],0.0);
file_put_contents('/app/__bad_header.csv',"bad,header\n");
chk($out,$fail,'csv bad header lists ship_fee',mb_strpos(manual_parse_csv('/app/__bad_header.csv')[1][0] ?? '','ship_fee')!==false,true);
chk($out,$fail,'csv bad header still needs name+price',mb_strpos(manual_parse_csv('/app/__bad_header.csv')[1][0] ?? '','name,price')!==false,true);
chk($out,$fail,'csv template file has ship_fee column',in_array('ship_fee',array_map('trim',explode(',',(string)@file_get_contents('/app/manual_products_import.csv'))),true)||mb_strpos((string)@file_get_contents('/app/manual_products_import.csv'),'ship_fee')!==false,true);
$out['failures']=$fail;
echo json_encode($out,JSON_UNESCAPED_UNICODE);
`;
// ربط الواجهة: منتجات يدوية تظهر في تبويب دول الخليج + تبويبات الدول من نفس المصدر
const cfg=fs.readFileSync(path.join(repo,'config.php'),'utf8');
function fn(name){const m=cfg.match(new RegExp('function '+name+'\\([\\s\\S]*?\\n\\}'));assert.ok(m,'missing function '+name);return m[0];}
assert.ok(/wf_gulf_countries\(\)/.test(fn('physical_collection')),'physical_collection must use wf_gulf_countries()');
assert.ok(/\$plats = \['m5zoon', 'manual'\];/.test(fn('physical_collection')),'Gulf physical view must include manual products');
assert.ok(/wf_gulf_countries\(\)/.test(fn('categories_by_mode')),'categories_by_mode must use wf_gulf_countries()');
assert.ok(/\$plats\[\] = 'manual';/.test(fn('categories_by_mode'))&&!/!in_array\(\$country, \$gulf, true\)\) \$plats\[\] = 'manual'/.test(fn('categories_by_mode')),'Gulf category chips must include manual products');
for(const file of ['index.php','dashboard/products.php','dashboard/marketer.php']){
  const src=fs.readFileSync(path.join(repo,file),'utf8');
  assert.ok(/wf_country_list\(\)/.test(src),file+' must build its country tabs from wf_country_list()');
}
assert.ok(/wf_country_currency\(/.test(fs.readFileSync(path.join(repo,'dashboard/marketer.php'),'utf8')),'marketer ranks must use wf_country_currency()');
assert.ok(/wf_country_currency\(/.test(fs.readFileSync(path.join(repo,'dashboard/product.php'),'utf8')),'product page must use wf_country_currency()');
assert.ok(/wf_country_currency\(/.test(fs.readFileSync(path.join(repo,'ui-components.php'),'utf8')),'ui-components must use wf_country_currency()');
// الفورم + قائمة الأدمن + قالب CSV
const admin=fs.readFileSync(path.join(repo,'dashboard/manual_products.php'),'utf8');
assert.ok(/id="mpCountry"/.test(admin)&&/mpSyncCurrency/.test(admin),'admin form must have a country select that syncs the price currency label');
assert.ok(/wf_country_list\(\)/.test(admin)&&/wf_country_currency\(/.test(admin),'admin page must use the shared country/currency helpers');
// سعر الشحن: حقل في نفس الفورم، بعملة دولة المنتج (اللي بتتزامن مع اختيار الدولة)، واختياري
assert.ok(/name="ship_fee"/.test(admin),'the manual product form must have a shipping-price field');
assert.ok(/'ship_fee'/.test(admin)&&/\$FIELDS = \['name', 'price', 'country', 'ship_fee'/.test(admin),'ship_fee must be collected with the other form fields');
assert.ok(/سعر الشحن \(<span class="mp-cur">/.test(admin),'the shipping-price label must show the country currency and be synced by mpSyncCurrency');
assert.ok(/اختياري/.test(admin)&&/حسب منطقة العميل/.test(admin),'the shipping-price field must be documented as optional (falls back to the region table)');
assert.ok(/شحن /.test(admin),'the products list must show the stored shipping fee');
const csvTemplate=fs.readFileSync(path.join(repo,'manual_products_import.csv'),'utf8').replace(/^\uFEFF/,'');
assert.deepStrictEqual(csvTemplate.split('\n')[0].split(','),['name','price','country','ship_fee','category','stock','sku','description','colors','sizes','material','weight','dimensions','images','faqs'],'CSV template header must include country and ship_fee after price');
// خصم الكمية (70 ج.م) بقى للجنيه بس: كل مكان بيحسب الخصم لازم يمرّر العملة/المنتج
const src=file=>fs.readFileSync(path.join(repo,file),'utf8');
const bulkCallers=[
  ['dashboard/orders.php',/wf_bulk_discount\(\$qty, \$plat, \$prod\)/],
  ['dashboard/checkout_cart.php',/wf_bulk_discount\(\$qty, \$pl, \$prod\)/],
  ['dashboard/checkout_cart.php',/wf_bulk_discount\(\$cd_qty, [^;]*wf_ui_currency\(\$cd\)\)/],
  ['dashboard/cart.php',/wf_bulk_discount\([^;]*\$c_cur\)/],
  ['dashboard/product.php',/wf_bulk_discount_plat_ok\(\$plat, \$cur\)/],
  ['landing.php',/wf_bulk_discount_plat_ok\(\$plat, wf_ui_currency\(\$p\)\)/],
  ['landing.php',/wf_bulk_discount\(2, \$plat, \$lp_cur\)/],
  ['landing.php',/wf_bundle_total\(\$final_price, 3, \$plat, \$lp_cur\)/],
  ['config.php',/wf_bulk_discount_state\(\$default_plat, \$default_currency\)/],
  ['config.php',/wf_bulk_discount_plat_ok\(\$default_plat, \$default_currency\)/],
];
for(const [file,re] of bulkCallers)assert.ok(re.test(src(file)),file+' must pass the product currency to the quantity-discount helpers');
// معاينة الخصم في الواجهة لازم تطابق السيرفر (متوريشتش خصم على منتج مش بالجنيه)
const uijs=src('assets/ui.js');
assert.ok(/function eoBulkApplies\(plat,cfg,currency\)/.test(uijs)&&/cfg\.egp_only/.test(uijs),'ui.js must skip the discount preview for non-EGP products');
assert.ok(/eoBulkApplies\(e&&e\.plat,cfg,e&&e\.currency\)/.test(uijs),'the easy-order dialog must check the clicked product currency');
assert.ok(/function eoPageBulk\(q,plat,currency\)/.test(uijs)&&/eoPageBulk\(q,p,c\)/.test(uijs),'the product page bulk preview must use the product currency');
// كل زر بيفتح نافذة الطلب لازم يبعت دولة المنتج وسعر شحنه — وإلا النافذة ترجع لمحافظات مصر
const dialogOpeners=[
  ['dashboard/product.php',/onclick="openProductCheckoutDialog\(\)"/],
  ['dashboard/product.php',/"country"=>wf_product_country\(\$r\)/],
  ['dashboard/product.php',/"ship_fee"=>function_exists\('wf_product_shipping'\) \? wf_product_shipping\(\$r\) : null/],
  ['dashboard/marketer.php',/'country' => wf_product_country\(\$fp\)/],
  ['dashboard/marketer.php',/'ship_fee' => function_exists\('wf_product_shipping'\) \? wf_product_shipping\(\$fp\) : null/],
  ['dashboard/marketer.php',/'country'=>wf_product_country\(\$full_mp\)/],
  ['dashboard/marketer.php',/'ship_fee'=>function_exists\('wf_product_shipping'\) \? wf_product_shipping\(\$full_mp\) : null/],
  ['landing.php',/id="lpInlineGov" data-shipping-country="/],
];
for(const [file,re] of dialogOpeners)assert.ok(re.test(src(file)),file+' must pass the product country to the order dialog');
// صفحة التفاصيل: حوار الطلب بيقرأ الدولة وسعر الشحن من wfProductState ويمرّرهم للنافذة
assert.ok(/country:t\.country,ship_fee:t\.ship_fee/.test(uijs),'the product-page checkout dialog must pass country and ship_fee to the easy-order modal');
// نص placeholder قائمة المدينة يتبدّل حسب الدولة (مش «المحافظة» لكل الدول)
assert.ok(/var cn=\(c\.dataset&&\(c\.dataset\.shippingCountry\|\|c\.dataset\.country\)\)\|\|"",word=cn&&cn!=="مصر"\?"المنطقة":"المحافظة";/.test(uijs),'the city list placeholder must use the country region word');
assert.ok(/s\.dataset\.wordFor!==word/.test(uijs),'the city select must rebuild when the country word changes');
for(const file of ['config.php','landing.php'])assert.ok(/اختر <\?php echo \(\$[a-z_]+ !== '' && \$[a-z_]+ !== 'مصر'\) \? 'المنطقة' : 'المحافظة'; \?> أولاً/.test(src(file)),file+' must render the district placeholder with the country region word');
// ملف مدن الخليج لازم يدخل قائمة النشر على الاستضافة (كان ساقط ⇒ القوايم المنسدلة مش بتوصل)
assert.ok(/'data\/shipping_cities_gulf\.json'/.test(src('updater/update_catalog.py')),'site_deploy_files() must upload data/shipping_cities_gulf.json');
// الشحن حسب الدولة: كل مكان بيعرض/يحسب رسوم لازم يكون country-aware
const shipCallers=[
  ['dashboard/orders.php',/site_ship_map\(\$plat, \$ord_country\)/],
  ['dashboard/orders.php',/wf_product_country\(\$prod\)/],
  ['dashboard/checkout_cart.php',/site_ship_regions\([^;]*\$ship_country\)/],
  ['dashboard/checkout_cart.php',/wf_product_country\(/],
  ['dashboard/checkout_cart.php',/\$ship_currency/],
  ['landing.php',/site_ship_map\(\$plat, \$lp_country\)/],
  ['landing.php',/render_easyorder_dialog_html\(\$ref, \$plat, wf_ui_currency\(\$p\), \$lp_country\)/],
  ['config.php',/site_ship_map\(\$ship_cplat, 'مصر'\)/],
  ['config.php',/site_ship_country_plats\(\)/],
  ['config.php',/wf_gulf_countries\(\) as \$ship_country/],
  ['config.php',/id="wfShippingCurrency"/],
  ['supabase.php',/function site_ship_country_plats/],
  ['supabase.php',/\$per_country = in_array\(\$lookup, site_ship_country_plats\(\), true\);/],
  ['supabase.php',/if \(\$per_country && \(\$cn === '' \|\| \$cn === 'مصر'\)\) \$lookup = 'timehand';/],
  ['supabase.php',/if \(\$pc !== '' && \$pc === \$cn\) return \$p\['regions'\];/],
  ['supabase.php',/مفيش جدول شحن لـ/],
  ['dashboard/admin_shipping.php',/function ship_table_key/],
  ['dashboard/admin_shipping.php',/wf_country_currency\(\$country\)/],
  ['index.php',/'country' => wf_product_country\(\$p\)/],
  ['store.php',/'country' => wf_product_country\(\$p\)/],
  ['dashboard/product.php',/'country'=>wf_product_country\(\$p\)/],
];
for(const [file,re] of shipCallers)assert.ok(re.test(src(file)),file+' must resolve shipping by the product country');
// سعر الشحن الثابت بتاع المنتج: بيتخزّن، بيتبعت للواجهة، والسيرفر هو اللي بيحسبه (مش المتصفح)
const feeCallers=[
  ['config.php',/function wf_product_shipping\(\$p\)/],
  ['config.php',/return min\(2000\.0, \$f\);/],
  ['manual_products_lib.php',/'ship_fee' => \$ship_fee,/],
  ['manual_products_lib.php',/'ship_fee' => \(isset\(\$c\['ship_fee'\]\) && is_numeric\(\$c\['ship_fee'\]\)\) \? round\(\(float\)\$c\['ship_fee'\], 2\) : null,/],
  ['manual_products_lib.php',/سعر الشحن لازم يكون رقم صفر أو أكبر/],
  ['manual_products_lib.php',/سعر الشحن كبير جداً \(أقصى 2000\)/],
  ['index.php',/'ship_fee' => function_exists\('wf_product_shipping'\) \? wf_product_shipping\(\$p\) : null,/],
  ['store.php',/'ship_fee' => function_exists\('wf_product_shipping'\) \? wf_product_shipping\(\$p\) : null,/],
  ['landing.php',/'ship_fee' => function_exists\('wf_product_shipping'\) \? wf_product_shipping\(\$p\) : null,/],
  ['dashboard/product.php',/'ship_fee'=>function_exists\('wf_product_shipping'\) \? wf_product_shipping\(\$p\) : null,/],
  ['dashboard/product.php',/\$pd_ship = function_exists\('wf_product_shipping'\)/],
  ['dashboard/orders.php',/\$prod_ship = function_exists\('wf_product_shipping'\) \? wf_product_shipping\(\$prod\) : null;/],
  ['dashboard/orders.php',/\$shipfee = \(\$prod_ship !== null\)/],
  ['dashboard/checkout_cart.php',/\$ship_fixed = \(\$__fp && function_exists\('wf_product_shipping'\)\)/],
  ['dashboard/checkout_cart.php',/if \(\$ship_fixed !== null\) \$fee = \$ship_fixed;/],
  ['dashboard/checkout_cart.php',/if \(\$ship_fixed !== null\) \{ foreach \(\$shiprows as &\$__sr\)/],
  ['landing.php',/\$lp_ship_fixed = function_exists\('wf_product_shipping'\)/],
  ['landing.php',/\$ship_rates\[\$__k\] = \$lp_ship_fixed;/],
];
for(const [file,re] of feeCallers)assert.ok(re.test(src(file)),file+' must use the product-level shipping fee');
// الطلب بيتحسب على السيرفر: سعر شحن المنتج بيكسب، ومفيش اعتماد على POST لوحده
assert.ok(/\$shipfee = \(\$prod_ship !== null\)[\s\S]{0,120}\$_POST\['ship_fee'\]/.test(src('dashboard/orders.php')),'the order total must prefer the stored product fee over the posted one');
// الواجهة بتبدّل جدول المناطق والعملة حسب دولة المنتج
assert.ok(/function eoSetShippingPlatform\(platform,country,productShip\)/.test(uijs)&&/eoShipCurrency/.test(uijs),'ui.js must switch the region table and currency per product country');
assert.ok(/eoSetShippingPlatform\(e\.plat\|\|"",e\.country\|\|"",e\.ship_fee\)/.test(uijs),'the easy-order dialog must pass the product country and its own shipping fee');
// سعر شحن المنتج بيكتب على كل الخيارات (فالـ total يتحسب بيه) — وفاضي = جدول المناطق زي الأول
assert.ok(/var fixed=\(productShip===null\|\|productShip===undefined\|\|productShip===""\)\?null:Number\(productShip\);/.test(uijs),'ui.js must ignore an absent/blank product shipping fee');
assert.ok(/var fee=\(fixed!==null\)\?fixed:Number\(rates\[region\]\);/.test(uijs),'every region option must show the product fee when it is set');
assert.ok(/select\.dataset\.shipFixed=/.test(uijs),'the shipping select must expose the fixed product fee');
// سلة الشراء: رسوم الشحن بتعرض عملة دولة المنتج (مش ج.م ثابتة)
assert.ok(/t\.dataset\.shipCurrency\)\|\|"ج\.م"/.test(uijs),'the cart checkout must show the shipping fee in the country currency');
assert.ok(/eoShipCur\(\)/.test(uijs)&&!/\+" ج\.م":"اختر المحافظة"/.test(uijs),'ui.js must not hardcode EGP for the shipping line');
// صفحات التسجيل/استكمال بيانات المسوق بتفضل بمحافظات مصر بس (عنوان الحساب مش شحن طلب)
for(const file of ['register.php','marketer_complete.php']){
  const s2=src(file);
  assert.ok(/wf_ship_governorates\(\)/.test(s2)&&/wf_ship_cities_map\(\)/.test(s2),file+' must keep the Egypt-only address lists');
  assert.ok(!/wf_ship_cities_map_all\(\)/.test(s2),file+' must not use the merged (Gulf) city map');
}
// قوايم مدن الخليج موجودة في البيانات ومغطّية كل منطقة
const gulfCities=JSON.parse(fs.readFileSync(path.join(repo,'data/shipping_cities_gulf.json'),'utf8')).countries;
const CUR_BY_COUNTRY={'السعودية':'ر.س','الإمارات':'د.إ','الأردن':'د.أ','عُمان':'ر.ع'};
// رسوم مخزون = سعر الدولة الموحّد (قرار الإدارة)، والرسوم اليدوية لسه صفر لحد ما المالك يدخلها
const MZ_FEE_BY_COUNTRY={'السعودية':25,'الإمارات':25,'الأردن':3,'عُمان':2.5};
const allShipTables=JSON.parse(fs.readFileSync(path.join(repo,'data/shipping.json'),'utf8'));
const shipTables=allShipTables.filter(e=>e.platform==='manual');
const mzTables=allShipTables.filter(e=>e.platform==='m5zoon');
assert.equal(shipTables.length,4,'shipping.json must have one manual table per Gulf country');
assert.equal(mzTables.length,4,'shipping.json must have one stock (m5zoon) table per Gulf country');
assert.ok(!allShipTables.some(e=>e.platform==='m5zoon'&&!e.country),'the legacy country-less m5zoon table must be gone — it listed countries as regions');
for(const entry of shipTables.concat(mzTables)){
  const cities=gulfCities[entry.country];
  assert.ok(cities,'missing city list for '+entry.country);
  for(const region of entry.regions){
    assert.ok(Array.isArray(cities[region.region])&&cities[region.region].length>0,entry.country+' / '+region.region+' has no cities');
    assert.equal(region.currency,CUR_BY_COUNTRY[entry.country],'wrong currency for '+entry.country);
    if(entry.platform==='m5zoon')assert.equal(region.fee,MZ_FEE_BY_COUNTRY[entry.country],'stock fees must start at the country price ('+entry.country+'/'+region.region+')');
    else assert.equal(region.fee,0,'Gulf manual fees must start at 0 until the owner fills them ('+entry.country+'/'+region.region+')');
  }
}
// مخزون واليدوي بيستخدموا نفس المناطق ⇒ قايمة مدن الخليج بتغطيهم كلهم
for(const entry of mzTables){
  const manual=shipTables.find(e=>e.country===entry.country);
  assert.ok(manual,'missing manual table for '+entry.country);
  assert.deepStrictEqual(entry.regions.map(r=>r.region),manual.regions.map(r=>r.region),'stock and manual regions must match for '+entry.country);
  assert.ok(String(entry.name||'').includes('مخزون'),'each stock table needs its own display name ('+entry.country+')');
}
// بيانات مخزون: كل صف له دولته الصح — 5549 كان متخزّن مصر رغم إن سعره بالدرهم
const MZ_CODE_COUNTRY={AE:'الإمارات',SA:'السعودية',JO:'الأردن',OM:'عُمان'};
const MZ_CURRENCY_COUNTRY={'درهم':'الإمارات','د.إ':'الإمارات','دينار':'الأردن','د.أ':'الأردن','ريال':'السعودية','ر.س':'السعودية','ريال عماني':'عُمان','ر.ع':'عُمان'};
function mzExpectedCountry(id,currency){
  const m=String(id).match(/-(AE|SA|JO|OM)$/i);
  if(m)return MZ_CODE_COUNTRY[m[1].toUpperCase()];
  return MZ_CURRENCY_COUNTRY[String(currency||'').trim()]||null;
}
for(const file of ['data/m5zoon.json','data/lite_m5zoon.json']){
  const rows=JSON.parse(fs.readFileSync(path.join(repo,file),'utf8'));
  assert.ok(Array.isArray(rows)&&rows.length>100,file+' must hold the stock catalog');
  const row5549=rows.find(r=>String(r.id)==='5549'||String(r.id).startsWith('5549-'));
  assert.ok(row5549,'5549 must exist in '+file);
  assert.equal(row5549.country,'الإمارات','5549 is priced in dirhams — its country must be the UAE, not Egypt ('+file+')');
  assert.deepEqual(rows.filter(r=>!r.country).length,0,'every stock row must have a country ('+file+')');
  const wrong=rows.filter(r=>{const want=mzExpectedCountry(r.id,r.currency);return want&&want!==(r.country||'');})
                  .map(r=>r.id+':'+r.currency+'->'+r.country);
  assert.deepEqual(wrong,[],'no stock row may keep a country that contradicts its own data ('+file+')');
}
// الكرون لازم يكتب الدولة الصحيحة للمنتجات الجديدة (وكان بيثبّت مصر لكل منتج مخزون)
const updater=src('updater/update_catalog.py');
const mzUpdate=updater.slice(updater.indexOf('def update_m5zoon():'));
assert.ok(mzUpdate.length>500,'updater must still contain update_m5zoon()');
assert.ok(/def _m5zoon_country\(pid, currency\):/.test(updater),'the updater must derive the country of a stock product from its own data');
assert.ok(/M5ZOON_COUNTRY_BY_CODE/.test(updater)&&/M5ZOON_COUNTRY_BY_CURRENCY/.test(updater),'the updater must map both the id suffix and the currency to a country');
assert.ok(!/'country': 'مصر'/.test(mzUpdate.slice(0,mzUpdate.indexOf('jsave'))),'update_m5zoon must not hardcode مصر for new stock products');
assert.ok(/'country': _m5zoon_country\(pid, currency\)\[0\]/.test(mzUpdate),'new stock items must be stored with their derived country');
assert.ok(/country_fixed/.test(mzUpdate),'the updater must repair legacy stock rows stored as مصر');
// صيغة PHP سليمة (مفيش php CLI هنا)
if(fs.existsSync(path.join(NM,'php-parser'))){
  const Engine=require(path.join(NM,'php-parser'));
  const parser=new Engine({parser:{suppressErrors:false,version:'8.3'},ast:{withPositions:false},lexer:{comment_tokens:false}});
  for(const file of ['config.php','manual_products_lib.php','ui-components.php','supabase.php','index.php','store.php','landing.php','dashboard/manual_products.php','dashboard/product.php','dashboard/products.php','dashboard/marketer.php','dashboard/cart.php','dashboard/checkout_cart.php','dashboard/orders.php','dashboard/admin_shipping.php','dashboard/shipping.php'])parser.parseCode(src(file),file);
}
(async()=>{
  const php=new PHP(await loadNodeRuntime('8.3',{emscriptenOptions:{processId:1,locateFile:p=>path.isAbsolute(p)?p:path.join(wasmDir,p)}}));
  php.mkdirTree('/app/data/logs');
  for(const file of FILES)php.writeFile('/app/'+file,fs.readFileSync(path.join(repo,file)));
  for(const file of DATA_FILES)php.writeFile('/app/'+file,fs.readFileSync(path.join(repo,file)));
  php.writeFile('/app/__test.php',PHP_TESTS);
  const res=await php.run({code:"<?php require '/app/__test.php';"});
  const raw=Buffer.from(Object.values(res.bytes)).toString('utf8');
  assert.equal(res.exitCode,0,'PHP exited with '+res.exitCode+(res.errors?': '+res.errors:''));
  let results;try{results=JSON.parse(raw);}catch(e){throw new Error('Unparsable PHP output: '+raw.slice(0,600));}
  const failed=Object.entries(results).filter(([,v])=>v!=='OK'&&v!=='failures').map(([k,v])=>k+' => '+JSON.stringify(v));
  fs.mkdirSync(path.join(repo,'.cache'),{recursive:true});
  fs.writeFileSync(path.join(repo,'.cache/manual-country-currency.json'),JSON.stringify({assertions:Object.keys(results).length-1,failures:results.failures,detail:results},null,2));
  assert.equal(results.failures,0,'PHP assertions failed:\n'+failed.join('\n'));
  console.log({phpAssertions:Object.keys(results).length-1,failures:0,wiringChecks:'physical_collection/categories_by_mode/tabs/form/csv/syntax OK'});
  process.exit(0);
})().catch(error=>{console.error(error.message||error);process.exit(1);});
