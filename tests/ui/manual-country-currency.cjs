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
const FILES=['config.php','manual_products_lib.php','customer_req_lib.php','supabase.php','supabase_users.php','supabase_docs.php','hevo_api.php','ui-components.php','amazon_lib.php','manual_products_import.csv'];
// جداول الشحن بتتحمل من DATA_DIR (/app/data) — بننسخها عشان الاختبار يشتغل على البيانات الحقيقية
const DATA_FILES=['data/shipping.json','data/shipping_cities_shopmart.json','data/shipping_cities_gulf.json'];
// بيانات وهمية لاختبارات Task-11 (إشعارات المسوقين + تعديل/إلغاء الطلبات + عدّادات الأدمن) — جوه WASM بس
const FIXTURES={
 'orders.json':[
  {order_no:'ORD-TEST-1',plat:'manual',id:'m1',pname:'منتج اختبار',ref:'MK123',m_earn:50,comm_state:'confirmed',base_price:100,markup:0,extra_comm:0,qty:1,unit:100,ship:10,total:110,cname:'عميل',cphone:'01000000001',city:'القاهرة',district:'مصر الجديدة',address:'شارع 1',notes:'',at:'2026-10-20 10:00',status:'pending'},
  {order_no:'ORD-TEST-2',plat:'manual',id:'m1',pname:'منتج اختبار 2',ref:'MK123',m_earn:30,comm_state:'confirmed',qty:1,unit:200,ship:10,total:210,cname:'عميل 2',cphone:'01000000002',city:'الجيزة',address:'شارع 2',at:'2026-10-20 11:00',status:'pending'},
  {order_no:'ORD-TEST-3',plat:'manual',id:'m1',pname:'مشحون',ref:'MK999',m_earn:10,qty:1,unit:100,ship:0,total:100,cname:'عميل 3',cphone:'01000000003',city:'القاهرة',address:'ش 3',at:'2026-10-20 12:00',status:'shipped'},
  {order_no:'ORD-TEST-4',plat:'manual',id:'m1',pname:'قيد التأكيد',ref:'MK777',qty:1,unit:50,ship:5,total:55,cname:'عميل 4',cphone:'01000000004',city:'أسوان',address:'ش 4',at:'2026-10-20 13:00',status:'قيد التأكيد'}
 ],
 'payouts.json':[
  {user:'a@x.com',amount:100,status:'قيد المراجعة'},
  {user:'b@x.com',amount:50,status:'قيد المراجعة'},
  {user:'c@x.com',amount:70,status:'موافق عليه'}
 ],
 'product_requests.json':[{status:'pending',name:'ر1'},{status:'available',name:'ر2'},{status:'pending',name:'ر3'}],
 'support_chats.json':{t1:{messages:[{sender:'user'},{sender:'admin'}]},t2:{messages:[{sender:'admin'},{sender:'user'}]}},
 'notifications.json':[],
 'product_overrides.json':{'timehand#t2':{price:900},'g2g#g1':{price:600}},
 'g2g.json':[{id:'g1',name:'كرت رقمي',price:560,currency:'ج.م',category:'منتجات رقمية',stock:9,images:[]}],
 'timehand.json':[
  {id:'t1',name:'منتج قديم من غير ختم',price:1000,price_text:'1000 جنيه',currency:'جنيه',category:'إلكترونيات',stock:5,images:[]},
  {id:'t2',name:'منتج نزل امبارح',price:500,price_text:'500 جنيه',currency:'جنيه',category:'إلكترونيات',stock:5,images:[],added_at:'2026-10-19'},
  {id:'t3',name:'منتج نزل النهارده',price:250,price_text:'250 جنيه',currency:'جنيه',category:'إلكترونيات',stock:5,images:[],added_at:'2026-10-21'}
 ]
};
const PHP_TESTS=String.raw`
<?php
// في الاختبار كل الملفات محلية (Supabase مطفي): نعرّف DOCS_REMOTE فاضية قبل تضمين config.php
define('DOCS_REMOTE', []);
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
// ديالوج الطلب للزائر: خانة عمولة المسوق لازم تكون مخفية (بتظهر للمسوق المسجّل بس)
chk($out,$fail,'dialog hides marketer commission for guests',mb_strpos($__dlg,'eoMkExtraInput')===false,true);
chk($out,$fail,'dialog has qty stepper',mb_strpos($__dlg,'id="eoQtyInput"')!==false,true);
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
chk($out,$fail,'dialog has no currency-mismatch warning',mb_strpos($__dlg,'عملة المنتج تختلف عن رسوم الشحن')===false&&mb_strpos($__dlg,'eoCurrencyNotice')===false,true);
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
// ---- 12) Task-11: إشعارات المسوقين + تعديل/إلغاء الطلب + عدّادات سايدبار الأدمن ----
$MK=['email'=>'mk1@x.com','name'=>'مسوق ١','role'=>'marketer','ref_code'=>'MK123'];
$c11=wf_admin_pending_counts();
chk($out,$fail,'pending orders count',$c11['orders'],3);
chk($out,$fail,'pending payouts count',$c11['commissions'],2);
chk($out,$fail,'pending requests count',$c11['requests'],2);
chk($out,$fail,'unread chats count',$c11['chats'],1);
chk($out,$fail,'new marketers count is int',is_int($c11['marketers']),true);
wf_notify_marketers('🆕 منتج اختبار للمسوقين','dashboard/marketer.php?mtab=browse');
$ns=jload('notifications.json',[]);
chk($out,$fail,'marketers notification stored',$ns[count($ns)-1]['for'],'marketers');
chk($out,$fail,'marketers notification link',$ns[count($ns)-1]['link'],'dashboard/marketer.php?mtab=browse');
$mkN=my_notifications('mk1@x.com','marketer');
chk($out,$fail,'marketer sees group notification',count(array_filter($mkN,function($n){return $n['for']==='marketers';}))>=1,true);
chk($out,$fail,'admin does not see marketers group',count(array_filter(my_notifications('ad@x.com','admin'),function($n){return $n['for']==='marketers';})),0);
chk($out,$fail,'customer does not see marketers group',count(array_filter(my_notifications('c@x.com','customer'),function($n){return $n['for']==='marketers';})),0);
$ords=jload('orders.json',[]);
chk($out,$fail,'own pending order editable',wf_marketer_can_edit_order($ords[0],$MK),true);
chk($out,$fail,'other ref not editable',wf_marketer_can_edit_order($ords[2],$MK),false);
$o3s=$ords[2];$o3s['ref']='MK123';
chk($out,$fail,'shipped not editable',wf_marketer_can_edit_order($o3s,$MK),false);
$ed=wf_marketer_apply_order_edit('ORD-TEST-1',$MK,['qty'=>'2','cname'=>'عميل معدّل','cphone'=>'01000000009','city'=>'الإسكندرية','address'=>'ش 9','notes'=>'']);
chk($out,$fail,'edit ok',$ed['ok'],true);
$ords=jload('orders.json',[]);
chk($out,$fail,'edit recomputes total 100x2+10-70 qty discount',$ords[0]['total'],140.0);
chk($out,$fail,'edit qty saved',$ords[0]['qty'],2);
chk($out,$fail,'edit city saved',$ords[0]['city'],'الإسكندرية');
chk($out,$fail,'edit marks mk_edited_by',$ords[0]['mk_edited_by'],'marketer:MK123');
chk($out,$fail,'edit by stranger rejected',wf_marketer_apply_order_edit('ORD-TEST-1',['email'=>'x@x.com','ref_code'=>'MK000'],['qty'=>'1','cname'=>'لص','cphone'=>'01000000000'])['ok'],false);
chk($out,$fail,'edit bad phone rejected',wf_marketer_apply_order_edit('ORD-TEST-2',$MK,['qty'=>'1','cname'=>'عميل','cphone'=>'12'])['ok'],false);
$cn=wf_marketer_cancel_order('ORD-TEST-2',$MK,'العميل اعتذر');
chk($out,$fail,'cancel ok',$cn['ok'],true);
$ords=jload('orders.json',[]);
chk($out,$fail,'cancelled row kept for admin',count($ords),4);
chk($out,$fail,'cancelled status',$ords[1]['status'],'cancelled');
chk($out,$fail,'cancelled label is ملغي',status_label(status_key($ords[1]['status'])),'ملغي');
chk($out,$fail,'cancelled reverses comm',$ords[1]['comm_state'],'reversed');
chk($out,$fail,'cancelled reason from marketer',mb_strpos($ords[1]['cancel_reason'],'إلغاء المسوق')===0,true);
chk($out,$fail,'cancelled no longer editable',wf_marketer_can_edit_order($ords[1],$MK),false);
chk($out,$fail,'cancel by stranger rejected',wf_marketer_cancel_order('ORD-TEST-3',['email'=>'y@x.com','ref_code'=>'MK000'])['ok'],false);
$cns=wf_marketer_cancel_order('ORD-TEST-3',['email'=>'y@x.com','ref_code'=>'MK999'],'ماتوصلش');
chk($out,$fail,'cancel shipped by owner allowed',$cns['ok'],true);
$ords3=jload('orders.json',[],false);
chk($out,$fail,'owner cancel flips shipped row',$ords3[2]['status'],'cancelled');
chk($out,$fail,'owner cancel reason prefix',mb_strpos($ords3[2]['cancel_reason'],'إلغاء المسوق')===0,true);
// ---- 13) تخفيض 12% على الفيزيائي (نقطة مركزية واحدة) + «أحدث المنتجات» بختم added_at ----
chk($out,$fail,'global discount constant',PRICE_GLOBAL_DISCOUNT,0.12);
// timehand: 1000 ×1.02 = 1020 ×0.88 = 897.6 → تقريب 5/0 (ceil) = 900
chk($out,$fail,'uplift timehand discounted',wf_price_uplift('timehand',1000.0),900.0);
chk($out,$fail,'uplift cezma discounted',wf_price_uplift('cezma',500.0),450.0);
chk($out,$fail,'uplift digital untouched',wf_price_uplift('g2g',560.0),560.0);
chk($out,$fail,'uplift social untouched',wf_price_uplift('social',75.0),75.0);
chk($out,$fail,'uplift manual untouched',wf_price_uplift('manual',300.0),300.0);
chk($out,$fail,'physical plat list covers stock cats',wf_is_physical_plat('shopmart')&&wf_is_physical_plat('m5zoon')&&!wf_is_physical_plat('manual')&&!wf_is_digital_plat('shopmart'),true);
// نافذة الرئيسية: الترتيب بختم added_at — فالآخر = الأحدث فعلًا مش ترتيب الملف
$w3=wf_home_platform_window('timehand',3);
chk($out,$fail,'window sorted by added_at',array_column($w3,'id'),['t1','t2','t3']);
$w2=wf_home_platform_window('timehand',2);
chk($out,$fail,'window tail is newest stamped',array_column($w2,'id'),['t2','t3']);
// ---- 14) إصلاح «تعذر الحفظ» في تعديل المنتجات: صور محلية/بفاصلة + سعر الأدمن = السعر النهائي ----
chk($out,$fail,'img ok https with comma',wf_image_url_allowed('https://cdn.example.com/a,b%20c.jpg'),true);
chk($out,$fail,'img ok root path',wf_image_url_allowed('/assets/a.jpg'),true);
chk($out,$fail,'img ok local detail',wf_image_url_allowed('assets/detail/vendoor_1252_00071.jpg'),true);
chk($out,$fail,'img reject data uri',wf_image_url_allowed('data:image/svg+xml;utf8,svg'),false);
chk($out,$fail,'img reject javascript',wf_image_url_allowed('javascript:alert(1)'),false);
chk($out,$fail,'img reject bare domain',wf_image_url_allowed('example.com/a.jpg'),false);
chk($out,$fail,'img reject empty',wf_image_url_allowed('   '),false);
$lt14=platform_products_lite('timehand');
$t1r=null;$t2r=null;
foreach($lt14 as $r14){if(($r14['id']??'')==='t1')$t1r=$r14; if(($r14['id']??'')==='t2')$t2r=$r14;}
chk($out,$fail,'override flag on row',!empty($t2r['_price_override']),true);
chk($out,$fail,'override row price',(float)$t2r['price'],900.0);
chk($out,$fail,'override is final store price',store_price($t2r,'timehand'),900.0);
chk($out,$fail,'override is final display price',display_price($t2r,'timehand'),900.0);
chk($out,$fail,'resaving display price is stable',store_price(apply_product_override_record(['id'=>'t2','price'=>display_price($t2r,'timehand'),'currency'=>'جنيه'],'timehand'),'timehand'),900.0);
chk($out,$fail,'no override still uplift+discount',store_price($t1r,'timehand'),900.0);
$gl14=platform_products_lite('g2g');
$g1r=null; foreach($gl14 as $r14) if(($r14['id']??'')==='g1')$g1r=$r14;
chk($out,$fail,'digital override final too',store_price($g1r,'g2g'),600.0);
// ---- 15) إصدار تلقائي للأصول: الجلسات القديمة تسحب CSS/JS الجديد من أول تحميل ----
$v15=wf_asset_v('style.css');
chk($out,$fail,'asset version = filemtime',$v15,(string)(int)filemtime('/app/assets/style.css'));
chk($out,$fail,'asset version memoized',wf_asset_v('style.css'),$v15);
chk($out,$fail,'missing asset falls back numeric',ctype_digit(wf_asset_v('ui.js')),true);
chk($out,$fail,'traversal stays numeric',ctype_digit(wf_asset_v('../../etc/passwd')),true);
// ---- 16) ديالوج التفاصيل العام: التفاصيل الطويلة في ديالوج + أسباب الرفض/الإلغاء ظاهرة ----
if(!function_exists('wf_details_btn'))require_once '/app/ui-components.php';
$b16=wf_details_btn('سبب الإلغاء',[['السبب','العميل اعتذر عن الاستلام'],['حقل فاضي','']],'التفاصيل');
chk($out,$fail,'details button attr',strpos($b16,'data-wf-details="')!==false,true);
preg_match('/data-wf-details="([^"]*)"/',$b16,$m16);
$d16=json_decode(html_entity_decode($m16[1],ENT_QUOTES,'UTF-8'),true);
chk($out,$fail,'details payload title',$d16['t'],'سبب الإلغاء');
chk($out,$fail,'details payload rows',count($d16['r']),2);
chk($out,$fail,'empty value becomes dash',$d16['r'][1][1],'—');
chk($out,$fail,'no rows no button',wf_details_btn('t',[['','']]),'');
$pt16=wf_det_text(str_repeat('كلمة ',40),30,'عنوان','التفاصيل');
chk($out,$fail,'preview truncated with ellipsis',strpos($pt16,'wf-det-preview')!==false&&strpos($pt16,'…')!==false,true);
chk($out,$fail,'preview has dialog button',strpos($pt16,'data-wf-details')!==false,true);
chk($out,$fail,'empty text renders dash only',wf_det_text(''),'—');
$o16=['order_no'=>'ORD-16','cancel_reason'=>'إلغاء المسوق: العميل غير متاح','address'=>'شارع طويل جدا في مدينة كبيرة','city'=>'القاهرة','qty'=>2];
chk($out,$fail,'cancel reason button shown',strpos(wf_order_reasons_btns($o16),'سبب الإلغاء')!==false,true);
chk($out,$fail,'reason button danger style',strpos(wf_order_reasons_btns($o16),'wf-det-danger')!==false,true);
chk($out,$fail,'no reasons no buttons',wf_order_reasons_btns(['order_no'=>'X']),'');
$rows16=wf_order_details_rows($o16);
$has16=false; foreach($rows16 as $r16x) if($r16x[0]==='العنوان بالتفصيل'&&$r16x[1]===$o16['address'])$has16=true;
chk($out,$fail,'full address in dialog rows',$has16,true);
$x16=wf_details_btn('t',[['سبب','<script>"x"</script>']]);
chk($out,$fail,'payload is XSS-safe',strpos($x16,'<script>')===false&&strpos($x16,'&quot;')!==false,true);
// ---- 17) مهمة 16: «تذكرني» runtime + قلب المفضلة في الهيدر الموحّد ----
// Supabase مطفي في الاختبار — update_user بيفشل بهدوء، لكن الكوكي والتحقق逻辑 محليين
$rm17=remember_issue('Tester@Example.com ');
chk($out,$fail,'remember_issue returns true',$rm17,true);
$rmck17=(string)($_COOKIE['wf_remember'] ?? '');
chk($out,$fail,'remember cookie format email|token64',(bool)preg_match('/^tester%40example\.com\|[0-9a-f]{64}$/',$rmck17),true);
remember_clear('tester@example.com');
chk($out,$fail,'remember_clear removes cookie',isset($_COOKIE['wf_remember']),false);
// كوكي مزور/مستخدم مش موجود ⇒ مفيش جلسة اتوماتيكية + الكوكي يت مسح
// الجلسة لازم تكون مفتوحة عشان نقيس سلوك remember_login الحقيقي (config بيفتحها عند التحميل)
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
chk($out,$fail,'session active before remember_login',session_status()===PHP_SESSION_ACTIVE,true);
unset($_SESSION['user']);
$_COOKIE['wf_remember']='ghost%40example.com|'.str_repeat('a',64);
remember_login();
chk($out,$fail,'remember_login rejects unknown user',empty($_SESSION['user']),true);
chk($out,$fail,'remember_login clears bad cookie',isset($_COOKIE['wf_remember']),false);
// جلسة قائمة ⇒ remember_login ما يلمسهاش
$_SESSION['user']=['name'=>'X','email'=>'x@y.z','role'=>'customer'];
$_COOKIE['wf_remember']='other%40example.com|'.str_repeat('b',64);
remember_login();
chk($out,$fail,'remember_login keeps existing session',(string)($_SESSION['user']['email'] ?? ''),'x@y.z');
unset($_COOKIE['wf_remember']);
// الهيدر الموحّد: أيقونة المفضلة بعدّاد
$wh17a=wf_store_header(['brand'=>'B','home'=>'index.php','wish_href'=>'dashboard/wishlist.php','wish_count'=>3,'cart_count'=>0]);
chk($out,$fail,'store header shows wish icon',strpos($wh17a,'wish-wrap')!==false&&strpos($wh17a,'المفضلة')!==false,true);
chk($out,$fail,'store header wish badge count',strpos($wh17a,'<span class="wish-count">3</span>')!==false,true);
$wh17b=wf_store_header(['brand'=>'B','home'=>'index.php','cart_count'=>0]);
chk($out,$fail,'store header hides wish without href',strpos($wh17b,'wish-wrap')===false,true);
$wh17c=wf_store_header(['brand'=>'B','home'=>'index.php','wish_href'=>'dashboard/wishlist.php','wish_count'=>0,'cart_count'=>0]);
chk($out,$fail,'zero wish count shows icon without badge',strpos($wh17c,'wish-wrap')!==false&&strpos($wh17c,'wish-count')===false,true);
// شريط الموبايل السفلي: المفضلة جنب السلة — يظهر بس لما الصفحة تمرر wish_href
$bn17a=wf_bottom_nav('home',['cart_count'=>0,'wish_href'=>'dashboard/wishlist.php','wish_count'=>2]);
chk($out,$fail,'mobile nav shows wish item with count',strpos($bn17a,'dashboard/wishlist.php')!==false&&strpos($bn17a,'المفضلة (2)')!==false,true);
$bn17b=wf_bottom_nav('home',['cart_count'=>0]);
chk($out,$fail,'mobile nav hides wish without href',strpos($bn17b,'wishlist.php')===false,true);
$bn17c=wf_bottom_nav('home',['cart_count'=>0,'wish_href'=>'dashboard/wishlist.php','hide'=>['wish']]);
chk($out,$fail,'mobile nav wish respects hide list',strpos($bn17c,'wishlist.php')===false,true);
// ---- 18) تعديل الطلبات الكامل: المسوق والإدارة (بما فيها العمولة والحالة وكود المسوق) ----
unset($_SESSION['user']);
$rd18=function($no){foreach((array)jload('orders.json',[],false) as $o18) if(($o18['order_no']??'')===$no) return $o18; return null;};
// نفس الحقول اللي بتبعتها الفورمات الحقيقية — الناقص معناه «امسح القيمة»
$mk18=function($o,$over=[]){return array_merge([
 'cname'=>(string)($o['cname']??''),'cphone'=>(string)($o['cphone']??''),'cphone2'=>(string)($o['cphone2']??''),
 'city'=>(string)($o['city']??''),'district'=>(string)($o['district']??''),'address'=>(string)($o['address']??''),
 'notes'=>(string)($o['notes']??''),'pname'=>(string)($o['pname']??''),'variant'=>(string)($o['variant']??''),
 'color'=>(string)($o['color']??''),'size'=>(string)($o['size']??''),
 'qty'=>(string)(int)($o['qty']??1),'unit'=>(string)(float)($o['unit']??0),'ship'=>(string)(float)($o['ship']??0),
 'm_earn'=>(string)(float)($o['m_earn']??0),'extra_comm'=>(string)(float)($o['extra_comm']??0)],$over);};
// -- المسوق: تعديل كامل لطلب قيد التأكيد بتاعه --
$u18=['email'=>'mk777@test.local','name'=>'مسوق 777','role'=>'marketer','ref_code'=>'MK777'];
$r18=wf_marketer_apply_order_edit('ORD-TEST-4',$u18,$mk18($rd18('ORD-TEST-4'),[
  'cname'=>'عميل معدّل','qty'=>'3','unit'=>'60','ship'=>'7','m_earn'=>'25','extra_comm'=>'5',
  'pname'=>'اسم معدّل','variant'=>'عرض 2','color'=>'أحمر','size'=>'L','city'=>'القاهرة']));
chk($out,$fail,'marketer full edit ok',!empty($r18['ok']),true);
$t4=$rd18('ORD-TEST-4');
chk($out,$fail,'marketer edit qty',(int)$t4['qty'],3);
chk($out,$fail,'marketer edit unit',(float)$t4['unit'],60.0);
chk($out,$fail,'marketer edit ship',(float)$t4['ship'],7.0);
chk($out,$fail,'marketer edit m_earn',(float)$t4['m_earn'],25.0);
chk($out,$fail,'marketer edit extra_comm',(float)$t4['extra_comm'],5.0);
chk($out,$fail,'marketer edit color',$t4['color'],'أحمر');
chk($out,$fail,'marketer edit size',$t4['size'],'L');
chk($out,$fail,'marketer edit variant',$t4['variant'],'عرض 2');
chk($out,$fail,'marketer edit pname',$t4['pname'],'اسم معدّل');
chk($out,$fail,'marketer edit city',$t4['city'],'القاهرة');
$cur18=wf_ui_currency($t4);
$d18=function_exists('wf_bulk_discount')?(float)wf_bulk_discount(3,'manual',$cur18):0.0;
chk($out,$fail,'marketer edit total recalced',(float)$t4['total'],round(max(0,60*3+7-$d18),2));
chk($out,$fail,'marketer edit discount stored',(float)($t4['discount']??-1),$d18);
chk($out,$fail,'marketer edit comm_state pending',$t4['comm_state'],'pending');
chk($out,$fail,'marketer edit status untouched',status_key($t4['status']),'pending');
chk($out,$fail,'marketer edit ref untouched',$t4['ref'],'MK777');
chk($out,$fail,'marketer edit stamp',($t4['mk_edited_by']??''),'marketer:MK777');
chk($out,$fail,'marketer edit changed map unit',($r18['changed']['unit']??null)===[50.0,60.0],true);
// -- بوابات المسوق: طلبات الغير والحالات المتأخرة --
$g18=wf_marketer_apply_order_edit('ORD-TEST-3',$u18,$mk18($rd18('ORD-TEST-3')));
chk($out,$fail,'marketer blocked on others order',(empty($g18['ok'])&&strpos($g18['msg'],'مش بتاعك')!==false),true);
$u18b=['email'=>'mk999@test.local','role'=>'marketer','ref_code'=>'MK999'];
$g18b=wf_marketer_apply_order_edit('ORD-TEST-3',$u18b,$mk18($rd18('ORD-TEST-3')));
chk($out,$fail,'marketer blocked on shipped status',empty($g18b['ok']),true);
// -- تحقق الحقول: أرقام غلط تترفض والصف ما يتلمسش --
$v18=wf_marketer_apply_order_edit('ORD-TEST-4',$u18,$mk18($t4,['qty'=>'0']));
chk($out,$fail,'qty 0 rejected',strpos((string)($v18['msg']??''),'الكمية')!==false,true);
$v18b=wf_marketer_apply_order_edit('ORD-TEST-4',$u18,$mk18($t4,['m_earn'=>'-5']));
chk($out,$fail,'negative commission rejected',strpos((string)($v18b['msg']??''),'العمولة')!==false,true);
$v18c=wf_marketer_apply_order_edit('ORD-TEST-4',$u18,$mk18($t4,['extra_comm'=>'99999']));
chk($out,$fail,'extra_comm above max rejected',strpos((string)($v18c['msg']??''),'الإضافية')!==false,true);
$v18d=wf_marketer_apply_order_edit('ORD-TEST-4',$u18,$mk18($t4,['cname'=>'','cphone'=>'123']));
chk($out,$fail,'bad customer rejected',strpos((string)($v18d['msg']??''),'اسم العميل')!==false,true);
$t4b=$rd18('ORD-TEST-4');
chk($out,$fail,'rejected edits leave row intact',(float)$t4b['m_earn'],25.0);
// -- الرقمي/السوشيال: العمولة الإضافية تتصفر حتى لو اتبعتت --
$row18=['plat'=>'g2g','id'=>'g1','qty'=>1,'unit'=>10.0,'ship'=>0.0,'status'=>'pending'];
wf_order_apply_row_edit($row18,['cname'=>'a','cphone'=>'01000000000','cphone2'=>'','city'=>'','district'=>'','address'=>'','notes'=>'','pname'=>'','variant'=>'','color'=>'','size'=>'','qty'=>2,'unit'=>15.0,'ship'=>0.0,'m_earn'=>5.0,'extra_comm'=>50.0]);
chk($out,$fail,'digital extra_comm forced zero',(float)$row18['extra_comm'],0.0);
chk($out,$fail,'digital row total',(float)$row18['total'],30.0);
chk($out,$fail,'digital row comm_state',$row18['comm_state'],'pending');
// -- الإدارة: حالة + فلوس في حفظ واحدة (ORD-TEST-1 عمولته مؤكدة) --
$r18a=wf_admin_apply_order_edit('ORD-TEST-1',$mk18($rd18('ORD-TEST-1'),[
  'cname'=>'عميل أدمن','qty'=>'2','unit'=>'90','m_earn'=>'40','extra_comm'=>'5','status'=>'cancelled','sub'=>'حملة-1']));
chk($out,$fail,'admin full edit ok',!empty($r18a['ok']),true);
$t1=$rd18('ORD-TEST-1');
chk($out,$fail,'admin edit status cancelled',$t1['status'],'cancelled');
chk($out,$fail,'admin edit comm_state reversed',$t1['comm_state'],'reversed');
chk($out,$fail,'admin edit m_earn',(float)$t1['m_earn'],40.0);
chk($out,$fail,'admin edit qty',(int)$t1['qty'],2);
chk($out,$fail,'admin edit sub',$t1['sub'],'حملة-1');
chk($out,$fail,'admin edit ref untouched',$t1['ref'],'MK123');
chk($out,$fail,'admin edit status_at set',!empty($t1['status_at']),true);
chk($out,$fail,'admin edit stamp set',!empty($t1['adm_edited_at']),true);
$d18a=function_exists('wf_bulk_discount')?(float)wf_bulk_discount(2,'manual',wf_ui_currency($t1)):0.0;
chk($out,$fail,'admin edit total recalced',(float)$t1['total'],round(max(0,90*2+10-$d18a),2));
chk($out,$fail,'admin changed map has status',isset($r18a['changed']['status']),true);
// -- الإدارة: كود مسوق مش موجود يترفض والصف يسلم --
$r18r=wf_admin_apply_order_edit('ORD-TEST-2',$mk18($rd18('ORD-TEST-2'),['ref'=>'NOSUCHCODE']));
chk($out,$fail,'admin bad ref rejected',(empty($r18r['ok'])&&strpos($r18r['msg'],'غير موجود')!==false),true);
$t2=$rd18('ORD-TEST-2');
chk($out,$fail,'bad ref leaves row intact',$t2['ref'],'MK123');
// -- الإدارة: حالة غلط / طلب مش موجود --
$r18st=wf_admin_apply_order_edit('ORD-TEST-2',$mk18($t2,['status'=>'flying']));
chk($out,$fail,'admin bad status rejected',strpos((string)($r18st['msg']??''),'حالة الطلب')!==false,true);
$r18no=wf_admin_apply_order_edit('ORD-NOPE',$mk18($t2));
chk($out,$fail,'admin unknown order rejected',strpos((string)($r18no['msg']??''),'غير موجود')!==false,true);
// -- الإدارة: تعديل فلوس بس على طلب ملغي (اتلغى في اختبارات مهمة 11) — القيمة تتحفظ والمحفظة ماتتحركش --
$r18m=wf_admin_apply_order_edit('ORD-TEST-2',$mk18($rd18('ORD-TEST-2'),['m_earn'=>'45']));
chk($out,$fail,'admin money edit on cancelled ok',!empty($r18m['ok']),true);
$t2b=$rd18('ORD-TEST-2');
chk($out,$fail,'admin money edit on cancelled m_earn',(float)$t2b['m_earn'],45.0);
chk($out,$fail,'cancelled order comm_state stays reversed',$t2b['comm_state'],'reversed');
chk($out,$fail,'admin money edit changed map',($r18m['changed']['m_earn']??null)===[30.0,45.0],true);
// -- الإدارة: تعديل فلوس بس على طلب قيد التأكيد (T-4 بعد تعديل المسوق) --
$r18p=wf_admin_apply_order_edit('ORD-TEST-4',$mk18($rd18('ORD-TEST-4'),['m_earn'=>'30']));
$t4c=$rd18('ORD-TEST-4');
chk($out,$fail,'admin money-only pending edit ok',!empty($r18p['ok']),true);
chk($out,$fail,'admin money-only pending m_earn',(float)$t4c['m_earn'],30.0);
chk($out,$fail,'admin money-only pending comm_state',$t4c['comm_state'],'pending');
chk($out,$fail,'admin money-only pending status kept',status_key($t4c['status']),'pending');
// -- الإدارة: تسليم يحرر العمولة (comm_state confirmed) --
$r18d=wf_admin_apply_order_edit('ORD-TEST-3',$mk18($rd18('ORD-TEST-3'),['status'=>'delivered']));
$t3=$rd18('ORD-TEST-3');
chk($out,$fail,'admin delivered edit ok',!empty($r18d['ok']),true);
chk($out,$fail,'admin delivered status',$t3['status'],'delivered');
chk($out,$fail,'admin delivered comm_state confirmed',$t3['comm_state'],'confirmed');
// ---- 19) تعديلات ما بعد PR#12: «لم يتم الرد» + إلغاء/إرجاع المسوق بعد الشحن + سبب الإدارة يظهر للمسوق + ديالوج واحد لكل التفاصيل ----
$sl19=order_status_list();$ks19=array_keys($sl19);
chk($out,$fail,'no_answer label لا يرد',$sl19['no_answer']??'','لا يرد');
chk($out,$fail,'no_answer sits above cancelled',array_search('no_answer',$ks19,true)===array_search('cancelled',$ks19,true)-1,true);
chk($out,$fail,'no_answer reverses commission',status_reverses_commission('no_answer'),true);
$u19=['email'=>'mk999@test.local','role'=>'marketer','ref_code'=>'MK999'];
$t319=$rd18('ORD-TEST-3');
chk($out,$fail,'can cancel delivered own order',wf_marketer_can_cancel_order($t319,$u19),true);
chk($out,$fail,'cannot cancel others order',wf_marketer_can_cancel_order($t319,['email'=>'z@z.com','ref_code'=>'MK000']),false);
$t219=$rd18('ORD-TEST-2');
chk($out,$fail,'cannot cancel already cancelled',wf_marketer_can_cancel_order($t219,$MK),false);
$r19a=wf_admin_apply_order_edit('ORD-TEST-4',$mk18($rd18('ORD-TEST-4'),['status'=>'no_answer','status_reason'=>'محدش رد على العميل']));
$t419=$rd18('ORD-TEST-4');
chk($out,$fail,'admin no_answer ok',!empty($r19a['ok']),true);
chk($out,$fail,'admin no_answer status',$t419['status'],'no_answer');
chk($out,$fail,'admin no_answer reverses comm',$t419['comm_state'],'reversed');
chk($out,$fail,'admin no_answer reason visible',$t419['cancel_reason'],'الإدارة: محدش رد على العميل');
$r19b=wf_admin_apply_order_edit('ORD-TEST-4',$mk18($rd18('ORD-TEST-4'),['status'=>'returned','status_reason'=>'العميل رجّع الشحنة']));
$t419b=$rd18('ORD-TEST-4');
chk($out,$fail,'admin returned reason visible',$t419b['return_reason'],'الإدارة: العميل رجّع الشحنة');
chk($out,$fail,'marketer cancel returned rejected',wf_marketer_cancel_order('ORD-TEST-4',$u18,'')['ok'],false);
$r19c=wf_marketer_cancel_order('ORD-TEST-3',$u19,'العميل رفض الاستلام','returned');
$t319c=$rd18('ORD-TEST-3');
chk($out,$fail,'marketer return delivered ok',!empty($r19c['ok']),true);
chk($out,$fail,'marketer return status returned',$t319c['status'],'returned');
chk($out,$fail,'marketer return reason prefix',mb_strpos($t319c['return_reason'],'إرجاع المسوق')===0,true);
chk($out,$fail,'marketer return reverses comm',$t319c['comm_state'],'reversed');
$r19d=wf_admin_apply_order_edit('ORD-TEST-3',$mk18($rd18('ORD-TEST-3'),['status'=>'shipped']));
$r19e=wf_marketer_cancel_order('ORD-TEST-3',$u19,'مافيش رد من العميل','cancelled');
$t319e=$rd18('ORD-TEST-3');
chk($out,$fail,'admin reship returned order ok',!empty($r19d['ok']),true);
chk($out,$fail,'marketer cancel shipped ok',!empty($r19e['ok']),true);
chk($out,$fail,'marketer cancel shipped reason',mb_strpos($t319e['cancel_reason'],'إلغاء المسوق')===0,true);
$o19=['order_no'=>'O19','status'=>'no_answer','cancel_reason'=>'الإدارة: س','unit'=>50,'ship'=>10,'total'=>110,'qty'=>2,'discount'=>0,'m_earn'=>12.5,'comm_state'=>'reversed','ref'=>'MK999','payment_status'=>'cod','plat'=>'manual','cname'=>'عميل','cphone'=>'01000000001','city'=>'القاهرة'];
$rows19m=wf_order_details_rows($o19,true);
$lbl19=function($rows19x,$want19){foreach($rows19x as $r19y) if($r19y[0]===$want19) return $r19y[1]; return null;};
chk($out,$fail,'dialog money rows: status',$lbl19($rows19m,'الحالة'),'لا يرد');
chk($out,$fail,'dialog money rows: commission',$lbl19($rows19m,'عمولة المسوق على الطلب'),'12.50 ج.م');
chk($out,$fail,'dialog money rows: comm state',$lbl19($rows19m,'حالة العمولة'),'مسحوبة');
chk($out,$fail,'dialog money rows: payment',$lbl19($rows19m,'حالة الدفع'),'الدفع عند الاستلام');
chk($out,$fail,'dialog money rows: unit',$lbl19($rows19m,'سعر القطعة'),'50.00 ج.م');
chk($out,$fail,'dialog money rows: admin reason',$lbl19($rows19m,'سبب الإلغاء'),'الإدارة: س');
chk($out,$fail,'dialog money rows: marketer ref',strpos((string)$lbl19($rows19m,'المسوق'),'MK999')!==false,true);
$rows19c=wf_order_details_rows($o19);
chk($out,$fail,'customer rows hide commission',$lbl19($rows19c,'عمولة المسوق على الطلب'),null);
chk($out,$fail,'customer rows hide unit price',$lbl19($rows19c,'سعر القطعة'),null);
chk($out,$fail,'customer rows still show reason',$lbl19($rows19c,'سبب الإلغاء'),'الإدارة: س');
// ---- 20) رد الطلب المضمون: توكن عدم التكرار + نقطة التأكيد + إشعارات مؤجلة بعد الرد ----
jupdate('orders.json',function(&$os20){if(!is_array($os20))return false;foreach($os20 as $i20=>$o20x){if(($o20x['order_no']??'')==='ORD-TEST-3'){$os20[$i20]['client_token']='tok20-abc';return true;}}return false;},[]);
$f20=wf_find_order_by_token('tok20-abc');
chk($out,$fail,'find order by client token',is_array($f20)&&($f20['order_no']??'')==='ORD-TEST-3',true);
chk($out,$fail,'unknown client token returns null',wf_find_order_by_token('tok20-missing'),null);
chk($out,$fail,'empty client token returns null',wf_find_order_by_token('   '),null);
$n20=jload('notifications.json',[]);$c20=is_array($n20)?count($n20):0;
wf_order_notify_queue('admin','إشعار مؤجل للتجربة','dashboard/admin.php?tab=orders');
wf_order_notify_queue('buyer20@test.local','إشعار مستخدم مؤجل','dashboard/orders.php');
wf_order_notify_flush();
$n20b=jload('notifications.json',[],false);$c20b=is_array($n20b)?count($n20b):0;
chk($out,$fail,'deferred notifications flushed to store',$c20b,$c20+2);
$has20=false;foreach((array)$n20b as $nn20) if(($nn20['for']??'')==='admin'&&strpos((string)($nn20['text']??''),'إشعار مؤجل للتجربة')!==false)$has20=true;
chk($out,$fail,'queued admin notification stored',$has20,true);
wf_order_notify_queue('admin','ماتتنفذش','');
wf_order_notify_flush();
$n20c=jload('notifications.json',[],false);
chk($out,$fail,'flush runs once per request',is_array($n20c)?count($n20c):-1,$c20b);
// ---- 21) ملاحظات الإدارة توصل للمسوق في شات الدعم + حالة «لا يرد» ----
chk($out,$fail,'status_label no_answer is لا يرد',status_label('no_answer'),'لا يرد');
wf_support_admin_message('mknote@test.local','📝 ملاحظة تجربة ١');
wf_support_admin_message('mknote@test.local','📝 ملاحظة تجربة ٢');
$sup21=jload('support_chats.json',[],false);
$th21=is_array($sup21)?($sup21['mknote@test.local']??null):null;
chk($out,$fail,'support thread created for marketer',is_array($th21),true);
chk($out,$fail,'support thread got both admin messages',is_array($th21)?count($th21['messages']??[]):0,2);
chk($out,$fail,'support messages marked unread for user',is_array($th21)?(int)($th21['unread_user']??0):0,2);
chk($out,$fail,'support message sender is admin',is_array($th21)&&isset($th21['messages'][0])?($th21['messages'][0]['sender']??''):'','admin');
chk($out,$fail,'empty support message ignored',wf_support_admin_message('mknote@test.local','   '),false);
// ---- 22) الضغط على المسوق يفتح كل أوردراته بكل تفاصيلها: wf_orders_by_ref ----
$ords22=jload('orders.json',[],false);
$by999=wf_orders_by_ref($ords22,'MK999');
chk($out,$fail,'orders by ref MK999 count',count($by999),1);
chk($out,$fail,'orders by ref MK999 row',(string)($by999[0]['order_no']??''),'ORD-TEST-3');
$by123=wf_orders_by_ref($ords22,'mk123');
chk($out,$fail,'orders by ref case-insensitive',count($by123),2);
chk($out,$fail,'orders by unknown ref empty',wf_orders_by_ref($ords22,'MKXYZ'),[]);
chk($out,$fail,'orders by empty ref empty',wf_orders_by_ref($ords22,''),[]);
// ---- 23) تصفير بيانات أمازون: الإحصائيات ترجع جديدة من صفر ----
jupdate(AMZ_LINKS_F,function(&$l23){$l23=['t23'=>['clicks'=>7,'uniq'=>3,'orders'=>2,'qty'=>4,'gross'=>100.5,'site_earn'=>50.25,'mk_earn'=>50.25,'last_click'=>'2026-10-20T10:00:00+00:00','status'=>'approved']];return true;},[]);
jupdate(AMZ_CLICKS_F,function(&$c23){$c23=[['id'=>'t23','at'=>'2026-10-20T10:00:00+00:00']];return true;},[]);
jupdate(AMZ_ORDERS_F,function(&$o23){$o23=[['id'=>'c23','link_id'=>'t23']];return true;},[]);
chk($out,$fail,'amz reset returns true',amz_reset_stats(),true);
chk($out,$fail,'amz clicks log cleared',jload(AMZ_CLICKS_F,[],false),[]);
chk($out,$fail,'amz orders log cleared',jload(AMZ_ORDERS_F,[],false),[]);
$l23b=jload(AMZ_LINKS_F,[],false);
chk($out,$fail,'amz link counters zeroed',(int)($l23b['t23']['clicks']??-1),0);
chk($out,$fail,'amz link earnings zeroed',(float)($l23b['t23']['mk_earn']??-1),0.0);
chk($out,$fail,'amz link itself kept',(string)($l23b['t23']['status']??''),'approved');
// ---- 24) ملاحظات الإدارة تظهر في تفاصيل الطلب (ديالوج المسوق والأدمن) ----
$o24=['order_no'=>'ORD-N24','pname'=>'منتج','admin_note'=>'اتصل بالعميل','admin_notes'=>[['note'=>'اتصل بالعميل','by'=>'أحمد','at'=>'2026-10-22T10:00:00+00:00'],['note'=>'تم الاتصال','by'=>'أحمد','at'=>'2026-10-22T11:00:00+00:00']]];
$r24=wf_order_details_rows($o24,true);
$has24=0;$txt24='';
foreach($r24 as $rr24){if(strpos((string)$rr24[0],'ملاحظة إدارة')===0){$has24++;$txt24.=(string)$rr24[1];}}
chk($out,$fail,'admin notes rows in details dialog',$has24,2);
chk($out,$fail,'admin note text shown in dialog',strpos($txt24,'اتصل بالعميل')!==false,true);
chk($out,$fail,'second admin note shown too',strpos($txt24,'تم الاتصال')!==false,true);
$out['failures']=$fail;
echo json_encode($out,JSON_UNESCAPED_UNICODE);
`;
// Task-14 wiring: إصدار الأصول تلقائي (filemtime) — مفيش نسخ CSS/JS قديمة للجلسات السابقة
{
  const assetPages=['affiliate-program.php','affiliate-register.php','config.php','dashboard/layout.php','error.php','forgot.php','google_callback.php','index.php','landing.php','login.php','marketer_complete.php','register.php','reset.php','store.php','style-guide.php'];
  for(const f of assetPages){
    const srcx=fs.readFileSync(path.join(repo,f),'utf8');
    assert.ok(!/(style\.css|ui\.js|tracker\.js)\?v=2026[0-9a-z-]+/.test(srcx),f+' must not hardcode a stale asset version');
    assert.ok(/wf_asset_v\(|filemtime/.test(srcx),f+' must version assets automatically');
  }
  assert.ok(/function wf_asset_v\(/.test(fs.readFileSync(path.join(repo,'config.php'),'utf8')),'wf_asset_v helper must exist in config.php');
}
// Task-15 wiring: ديالوج تفاصيل عام — التفاصيل الطويلة وأسباب الرفض/الإلغاء في ديالوج بدل الحشر في خلايا صغيرة
{
  const uic=fs.readFileSync(path.join(repo,'ui-components.php'),'utf8');
  for(const fn of ['function wf_details_btn(','function wf_det_text(','function wf_order_reasons_btns(','function wf_order_details_rows('])
    assert.ok(uic.includes(fn),'ui-components.php must define '+fn);
  const js=fs.readFileSync(path.join(repo,'assets/ui.js'),'utf8');
  assert.ok(/window\.wfDetails\s*=/.test(js),'ui.js must expose wfDetails()');
  assert.ok(js.includes('[data-wf-details]'),'ui.js must wire data-wf-details buttons');
  assert.ok(/val\.textContent/.test(js),'dialog values must be rendered as text (XSS-safe)');
  assert.ok(!js.includes('.get("variant")'),'success summary must not call .get on a DOM element (successful order shown as failure)');
  assert.ok(js.includes('catch(sumErr)'),'success summary build must be crash-proof so e.ok always shows the success view');
  const css=fs.readFileSync(path.join(repo,'assets/style.css'),'utf8');
  for(const sel of ['.wf-det-backdrop{','.wf-det-card{','.wf-det-row{','.btn.wf-det-danger{','.wf-det-preview{'])
    assert.ok(css.includes(sel),'style.css must include '+sel);
  const adm=fs.readFileSync(path.join(repo,'dashboard/admin.php'),'utf8');
  assert.ok((adm.match(/wf_details_btn\(|wf_det_text\(/g)||[]).length>=10,'admin must open details dialogs (orders, notes, payouts, payments, audit, noon, errors)');
  assert.ok(adm.includes('wf_order_details_rows($o, true)')&&adm.includes('تفاصيل إضافية'),'admin order rows must open ONE dialog with all details incl. money');
  assert.ok(adm.includes('status_reason'),'admin must capture a cancel/return/no-answer reason that reaches the marketer');
  assert.ok(!adm.includes('wf_order_reasons_btns('),'reasons must live inside the details dialog, not as nested buttons');
  assert.ok(adm.includes('wf_support_admin_message')&&adm.includes('order_note_idx'),'admin order notes must reach the marketer support chat + notification');
  assert.ok(adm.includes('كل طلبات المسوق بكل تفاصيلها')&&adm.includes('wf_order_details_rows($oo, true)'),'marketer page must list ALL his orders with full details + dialog');
  assert.ok(adm.includes('wf_orders_by_ref'),'marketer page must filter orders through the shared helper');
  // جداول الطلبات بسيطة: التفاصيل كلها في الديالوج الكبير مش خلايا مكدسة (خصوصًا على الموبايل)
  assert.ok(adm.includes('<th>#</th><th>التاريخ</th><th>المنتج</th><th>العميل</th><th>الإجمالي</th><th>المسوق</th><th>الحالة</th><th>ملاحظة الإدارة</th><th>حالة الإرسال للمصدر</th>')&&!adm.includes('<th>الهاتف / واتساب</th>'),'admin orders table must be slim — every detail lives in the big dialog');
  assert.ok(!adm.includes('كل ملاحظات الإدارة للطلب'),'admin notes cell must not duplicate the notes dialog (they are inside تفاصيل إضافية)');
  assert.ok(adm.includes("</a><br>' . wf_details_btn('كل تفاصيل الطلب "),'admin product cell must open the full details dialog directly');
  assert.ok(!/array_slice\(\$mo_rev, 0, 15/.test(adm),'the marketer orders list must not be capped at 15 rows');
  assert.ok((adm.match(/tab=marketers&mk=/g)||[]).length>=3,'marketer name/email in the table must be clickable to his orders page');
  // تصفير بيانات أمازون + تحصينات الأخطاء الفادحة
  const amzSrc=fs.readFileSync(path.join(repo,'amazon_lib.php'),'utf8');
  assert.ok(amzSrc.includes('function amz_reset_stats'),'amazon_lib must define the stats reset function');
  assert.ok(adm.includes("'reset_stats'")&&adm.includes('تصفير الإحصائيات والبدء من جديد'),'admin amazon tab must expose a confirmed reset button');
  const ordSrc23=fs.readFileSync(path.join(repo,'dashboard/orders.php'),'utf8');
  const qCalls=(ordSrc23.match(/wf_order_notify_queue\(/g)||[]).length;
  const qGuarded=(ordSrc23.match(/function_exists\('wf_order_notify_queue'\)\)? wf_order_notify_queue\(/g)||[]).length;
  assert.ok(qCalls>0&&qCalls===qGuarded,'every deferred-notify queue call must be function_exists-guarded (rolling-upload fatal safety)');
  const cfg23=fs.readFileSync(path.join(repo,'config.php'),'utf8');
  assert.ok(cfg23.includes(String.fromCharCode(64)+"ini_set('memory_limit', '256M')"),'config.php must raise the memory limit to cut OOM fatals');
  const rt23=fs.readFileSync(path.join(repo,'ui-runtime.php'),'utf8');
  assert.ok(rt23.includes('memory_get_peak_usage'),'fatal log must record peak memory for triage');
  const cfg21=fs.readFileSync(path.join(repo,'config.php'),'utf8');
  assert.ok(cfg21.includes('function wf_support_admin_message'),'config.php must define the support push helper');
  assert.ok(/'no_answer'\s*=>\s*'لا يرد'/.test(cfg21),'the no-answer status must carry the label لا يرد');
  assert.ok(/payment_reject_reason/.test(adm),'payment reject reason must be visible to admin');
  assert.ok(/payout_reject_reason/.test(adm)&&/'reject_reason'\]/.test(adm),'payout reject must record a reason');
  const mk=fs.readFileSync(path.join(repo,'dashboard/marketer.php'),'utf8');
  // شارة ملاحظة الإدارة على صف طلب المسوق — بتفتح نفس الديالوج الكبير
  assert.ok(mk.includes('$mk_has_note'),'marketer orders rows must detect admin notes');
  assert.ok(mk.includes('📝 ملاحظة إدارة'),'marketer orders rows must show the admin-note badge');
  assert.ok(mk.includes("$mk_has_note ? ' ' . wf_details_btn('ملاحظات الإدارة على الطلب "),'the badge must open the details dialog only when a note exists');
  assert.ok(adm.includes("find_user($saved_ref)"),'admin note handler must fall back to email lookup so the note always reaches the marketer chat');
  // زرار سبب الإلغاء/الاسترجاع على صف طلب المسوق — يظهر بس لو فيه سبب مسجل
  assert.ok(mk.includes("$mk_cancel_reason !== ''"),'the cancel-reason button must be conditional on a stored reason');
  assert.ok(mk.includes("wf_details_btn('سبب إلغاء الطلب "),'cancelled orders must get a dedicated cancel-reason dialog button');
  assert.ok(mk.includes("🚫 سبب الإلغاء"),'the button label must read cancel reason');
  assert.ok(mk.includes("↩️ سبب الاسترجاع"),'returned orders must get a return-reason button too');
  assert.ok(mk.includes("[['سبب الإلغاء', $mk_cancel_reason]]"),'the dialog must show the stored cancel reason text');
  // خانة الإلغاء في الإدارة: زرار إرسال يحفظ السبب + السبب يوصل في الإشعارات
  assert.ok(adm.includes('💾 حفظ السبب'),'the admin status form must have a submit button so the reason saves without changing status');
  assert.ok(adm.includes("' — السبب: ' . $st_reason_q"),'the reason suffix must be built from the posted status_reason');
  assert.ok((adm.match(/\$st_reason_sfx/g)||[]).length>=3,'both customer and marketer status notifications must carry the reason suffix');
  assert.ok(adm.includes('وحُفظ السبب وهيظهر للمسوق والعميل'),'the admin must get confirmation that the reason was saved');
  assert.ok(mk.includes('wf_details_btn(')&&mk.includes('wf_order_details_rows($o, true)'),'marketer orders must have one full details dialog incl. commission');
  assert.ok(mk.includes('value="return"')&&mk.includes('wf_marketer_can_cancel_order'),'marketer must be able to cancel/return orders even after shipping');
  assert.ok(!mk.includes('هتلغي الطلب؟'),'the old cancel confirmation sentence must be gone');
  assert.ok(!mk.includes('wf_order_reasons_btns('),'marketer rows must not carry nested reason buttons');
  assert.ok(!mk.includes('<th>سعر البيع</th>')&&!mk.includes('<th>المحافظة والعنوان</th>')&&!mk.includes('<th>الهاتف</th>'),'marketer orders table must not carry inline detail columns (dialog has them)');
  assert.ok(mk.includes("</b><br>' . wf_details_btn('كل تفاصيل الطلب "),'marketer product cell must open the full details dialog directly');
  const ord=fs.readFileSync(path.join(repo,'dashboard/orders.php'),'utf8');
  assert.ok(ord.includes('wf_details_btn(')&&ord.includes('تفاصيل إضافية'),'customer orders must show one full details dialog');
  assert.ok(!ord.includes('wf_order_details_rows($o, true)'),'customer dialogs must NOT expose commission/money rows');
  assert.ok(!ord.includes('wf_order_reasons_btns('),'customer rows must not carry nested reason buttons');
  const noon=fs.readFileSync(path.join(repo,'dashboard/noon.php'),'utf8');
  assert.ok(noon.includes('wf_det_text('),'noon reject notes must open a dialog');
  const po=fs.readFileSync(path.join(repo,'dashboard/_payout.php'),'utf8');
  assert.ok(po.includes('wf_details_btn(')&&po.includes('reject_reason'),'marketer payout history must show reject reason + notes');
  const mp=fs.readFileSync(path.join(repo,'dashboard/manual_products.php'),'utf8');
  assert.ok(mp.includes('wf_details_btn('),'manual products list must open a full-details dialog');
  const idx=fs.readFileSync(path.join(repo,'dashboard/index.php'),'utf8');
  assert.ok(idx.includes('wf_det_text(')&&idx.includes('wf_details_btn('),'account page reviews/news must open dialogs for long text');
}
// Task-13 wiring: «تعذر الحفظ» في تعديل المنتجات + سعر الأدمن override = السعر النهائي
{
  const adm13=fs.readFileSync(path.join(repo,'dashboard/admin.php'),'utf8');
  assert.ok((adm13.match(/wf_image_url_allowed\(/g)||[]).length>=3,'the three image validations (full images, variant images, quick image) must use wf_image_url_allowed');
  assert.ok(/fail_reason/.test(adm13)&&/تعذر الحفظ' \. \(\$fail_reason/.test(adm13),'the failure message must carry the specific reason');
  assert.ok(/\/\[\\r\\n\]\+\/u/.test(adm13),'image lines must split on newlines only (CDN filenames may contain commas)');
  assert.ok(/\$category !== \$cur_cat/.test(adm13)&&/find_product_by_id\(\$raw_id, \$plat\)/.test(adm13),"the product's own current category must stay acceptable");
  const cfg13=fs.readFileSync(path.join(repo,'config.php'),'utf8');
  assert.ok(/function wf_image_url_allowed\(/.test(cfg13)&&/strpos\(\$u, 'assets\/'\) === 0/.test(cfg13),'local assets/detail images must be accepted');
  const ovr=cfg13.match(/function apply_product_override_record\([\s\S]*?\n\}/);
  assert.ok(ovr&&/_price_override/.test(ovr[0]),'price overrides must flag the row');
  const sp=cfg13.match(/function store_price\([\s\S]*?\n\}/);
  assert.ok(sp&&/_price_override/.test(sp[0])&&/return round\(max\(0, \(float\)\$p\['price'\]\), 2\);/.test(sp[0]),'store_price must return the admin-set price as-is (no re-uplift/re-discount)');
}
// Task-12 wiring: تخفيض 12% مركزي + «أحدث المنتجات» = ختم added_at فعلًا
{
  const cfg12=fs.readFileSync(path.join(repo,'config.php'),'utf8');
  assert.ok(/define\('PRICE_GLOBAL_DISCOUNT', 0\.12\)/.test(cfg12),'the 12% discount constant must exist');
  const upl=cfg12.match(/function wf_price_uplift\([\s\S]*?\n\}/);
  assert.ok(upl&&/round\(\$v \* \(1 - PRICE_GLOBAL_DISCOUNT\), 2\)/.test(upl[0]),'wf_price_uplift (the single physical sale-price funnel) must apply the 12% cut');
  assert.ok(/usort\(\$arr, function \(\$a, \$b\) \{[\s\S]{0,140}added_at/.test(cfg12),'the catalog «أحدث المنتجات» branch must sort by added_at desc');
  assert.ok(/usort\(\$all, function \(\$a, \$b\) \{[\s\S]{0,140}added_at/.test(cfg12),'the home window must sort by added_at before slicing the newest 900');
  assert.ok(/usort\(\$newest_first,[\s\S]{0,160}added_at/.test(cfg12),'cezma_recent_products must prefer stamped rows');
  const mk12=fs.readFileSync(path.join(repo,'dashboard/marketer.php'),'utf8');
  assert.ok(/usort\(\$mall,[\s\S]{0,160}added_at/.test(mk12),'marketer «الأحدث» sort must use added_at');
  const upd12=fs.readFileSync(path.join(repo,'updater/update_catalog.py'),'utf8');
  assert.ok(/def stamp_new_products/.test(upd12)&&/r\['added_at'\] = TODAY/.test(upd12),'the updater must stamp added_at on new products (incl. cezma shards)');
  assert.ok(/cezma_shards/.test(upd12)&&/_new_cezma_rows = stamp_new_products\(_pre_platform_ids\)/.test(upd12),'cezma shards must be snapshotted, stamped and fed to marketer notifications');
}
// Task-11 wiring: إشعارات لكل المسوقين + تعديل/إلغاء المسوق لطلباته + عدّادات سايدبار الأدمن
{
  const cfg11=fs.readFileSync(path.join(repo,'config.php'),'utf8');
  assert.ok(/function wf_notify_marketers\(/.test(cfg11)&&/notify\('marketers'/.test(cfg11),'config must broadcast to the marketers group');
  assert.ok(/\$for === 'marketers' && \$is_mk/.test(cfg11),'my_notifications must include the marketers group for marketer role');
  assert.ok(/function wf_admin_pending_counts\(/.test(cfg11)&&/function wf_marketer_can_edit_order\(/.test(cfg11)&&/function wf_marketer_apply_order_edit\(/.test(cfg11)&&/function wf_marketer_cancel_order\(/.test(cfg11),'config must expose admin counters + marketer order edit/cancel helpers');
  const lay11=fs.readFileSync(path.join(repo,'dashboard/layout.php'),'utf8');
  assert.ok(/wf_admin_pending_counts\(\)/.test(lay11)&&/notification-count side-count/.test(lay11),'admin sidebar must render pending-count badges');
  assert.ok(/my_notifications\(\$user\['email'\], wf_user_role\(\$user\)\)/.test(lay11),'bell must pass the role so marketers get group notifications');
  const mkp=fs.readFileSync(path.join(repo,'dashboard/marketer.php'),'utf8');
  assert.ok(/mk_order_action/.test(mkp)&&/wf_marketer_apply_order_edit/.test(mkp)&&/wf_marketer_cancel_order/.test(mkp),'marketer dashboard must wire order edit/cancel');
  assert.ok(/<th>إجراءات<\/th>/.test(mkp)&&/name="cname"/.test(mkp)&&/mtab=orders&edit=/.test(mkp),'orders table must offer actions column + edit form');
  assert.ok((mkp.match(/notify_user\(\$user\['email'\]/g)||[]).length>=4,'marketer must be notified about his own account events');
  const ord11=fs.readFileSync(path.join(repo,'dashboard/orders.php'),'utf8');
  assert.ok(/find_marketer_by_code\(\$ref\)/.test(ord11)&&/طلب جديد على لينكك/.test(ord11),'new orders must notify the owning marketer');
  const adm11=fs.readFileSync(path.join(repo,'dashboard/admin.php'),'utf8');
  assert.ok(/<th>العمولة<\/th>/.test(adm11)&&/find_marketer_by_code\(\(string\)\$o\['ref'\]\)/.test(adm11),'admin orders table must show commission + marketer name');
  const mpl=fs.readFileSync(path.join(repo,'manual_products_lib.php'),'utf8');
  assert.ok(/wf_notify_marketers\(/.test(mpl),'manual product insert must broadcast to marketers');
  const upd=fs.readFileSync(path.join(repo,'updater/update_catalog.py'),'utf8');
  assert.ok(/def push_new_product_notifications/.test(upd)&&/'for': 'marketers'/.test(upd)&&/snapshot_platform_ids\(\)/.test(upd),'catalog updater must notify marketers about new products');
  const notp=fs.readFileSync(path.join(repo,'dashboard/notifications.php'),'utf8');
  assert.ok(/wf_user_role\(\$user\)/.test(notp),'notifications page must pass the role');
}
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
  // إشعار الخصم 🎁 اتحذف من الديالوج (كان بينادي plat_ok) — الخصم نفسه لسه بيتمرر بالعملة في كل المواقع التانية
  ['dashboard/product.php',/wf_bulk_discount_plat_ok\(\$plat, \$cur\)/],
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
// تحذير «عملة المنتج تختلف عن رسوم الشحن المصرية» اتشال من النافذة بقرار الإدارة
assert.ok(!/eoCurrencyNotice/.test(uijs)&&!/eoCurrencyNotice/.test(src('config.php')),'the removed currency-mismatch warning must not come back');
// رسالتا خلط العملات (سلة/طلبات/لوحات) اتشالوا كمان بقرار الإدارة 2026-10-21
for(const file of ['ui-components.php','dashboard/admin.php','dashboard/cart.php','dashboard/checkout_cart.php','dashboard/marketer.php','dashboard/orders.php'])
  assert.ok(!/wf_ui_(shipping_)?currency_notice/.test(src(file)),file+' must not render the removed mixed-currency notices');
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
// ---- توصيلات مهمة 16: تذكرني / مرونة JSON / سقف الكمية / مفضلة+سلة في كل مكان ----
{
  const cfgSrc=src('config.php');
  assert.ok(/HTTP_X_REQUESTED_WITH[\s\S]{0,260}display_errors/.test(cfgSrc),'XHR requests must suppress display_errors so order replies stay clean JSON');
  assert.ok(cfgSrc.includes('function remember_issue')&&cfgSrc.includes('function remember_login')&&cfgSrc.includes('function remember_clear'),'config.php must define the remember-me trio');
  assert.ok(/remember_login\(\);\s*$/.test(cfgSrc.trim()),'remember_login() must bootstrap at the end of config.php (every request)');
  assert.ok(cfgSrc.includes('id="eoMkExtraInput"')&&cfgSrc.includes('name="mk_extra"'),'order dialog must render the marketer extra-commission field');
  assert.ok(cfgSrc.includes('$eo_mk_code')&&cfgSrc.includes('wf_is_marketer_account($eo_u)'),'commission field must be gated to logged-in marketer accounts');
  const ordSrc=src('dashboard/orders.php');
  assert.ok(ordSrc.includes('_WF_ORDER_JSON_SENT')&&ordSrc.includes('register_shutdown_function'),'orders.php must guarantee a JSON reply for ajax orders even on fatal errors');
  assert.ok(ordSrc.includes('$__wf_ajax_order'),'orders.php must answer ajax POSTs without id as JSON, not the HTML page');
  const uiSrc=src('assets/ui.js');
  assert.ok(uiSrc.includes('window.wfParseJsonLoose'),'ui.js must ship the lenient JSON parser (hosting injects text around JSON)');
  assert.ok((uiSrc.match(/return (?:e|res)\.text\(\)/g)||[]).length>=2,'order submits and the check endpoint must read responses as text and parse leniently');
  assert.ok(/['"]X-Requested-With['"]\s*:\s*['"]XMLHttpRequest['"]/.test(uiSrc),'order submits must send the ajax header (CSRF/JSON branch)');
  // رد الطلب المضمون: توكن + تأكيد + إشعارات بعد الرد — مفيش رسالة «ممكن يكون اتسجل أو لأ» تاني
  assert.ok(ordSrc.includes('ajax_order_check')&&ordSrc.includes('client_token')&&ordSrc.includes('wf_find_order_by_token'),'orders.php must expose the token check endpoint and idempotent submits');
  assert.ok(ordSrc.includes('wf_order_notify_flush')&&ordSrc.includes('fastcgi_finish_request')&&ordSrc.includes('Content-Length'),'orders.php must deliver the JSON reply BEFORE telegram/whatsapp notifications');
  assert.ok(cfgSrc.includes('function wf_order_notify_flush')&&cfgSrc.includes('function wf_find_order_by_token'),'config.php must define the deferred-notify and token lookup helpers');
  assert.ok(uiSrc.includes('wfOrderSubmitWithCheck')&&uiSrc.includes('wfOrderCheck')&&uiSrc.includes('client_token'),'ui.js must verify via the check endpoint instead of showing an ambiguous failure');
  assert.ok(!uiSrc.includes('تعذر قراءة رد الخادم'),'the ambiguous unreadable-reply message must be gone from ui.js');
  assert.ok(!uiSrc.includes('ممكن يكون اتسجل أو لأ'),'the may-or-may-not-registered wording must be gone from ui.js');
  // إشعار خصم الكمية 🎁 اتحذف — الخصم نفسه يفضل شغال
  assert.ok(!cfgSrc.includes('بيتحسب أوتوماتيك في الإجمالي'),'the bulk-discount notice must be removed from the order dialog');
  assert.ok(!cfgSrc.includes('id="eoBulkHint"'),'the eoBulkHint notice element must be removed');
  assert.ok(cfgSrc.includes('function wf_bulk_discount')&&ordSrc.includes('wf_bulk_discount'),'the bulk discount itself must stay active server-side');
  const cartSrc=src('dashboard/cart.php');
  assert.ok(!cartSrc.includes('اطلب أكثر من قطعة'),'the cart bulk-discount notice must be removed too');
  assert.ok(cartSrc.includes('خصم الكمية'),'the cart discount math row must stay');
  assert.ok(/qi\.value=String\(e\)/.test(uiSrc),'eoRecalc must write the clamped quantity back to the visible input');
  assert.ok(/Math\.min\(99,parseInt\(t\.value/.test(uiSrc),'product page updateTotal must cap quantity at 99 and sync the input');
  const prodSrc=src('dashboard/product.php');
  assert.ok(prodSrc.includes('max="99"')&&prodSrc.includes('Math.min(99,(parseInt(q.value||1,10)||1)+1)'),'bqty stepper must be capped at 99');
  const loginSrc=src('login.php'),regSrc=src('register.php'),logoutSrc=src('logout.php');
  assert.ok(loginSrc.includes('name="remember"')&&loginSrc.includes('remember_issue('),'login must offer تذكرني and issue the remember cookie');
  assert.ok(regSrc.includes('name="remember"')&&/name="remember" value="1" checked/.test(regSrc)&&(regSrc.match(/remember_issue\(/g)||[]).length>=2,'register must offer pre-checked تذكرني on both success paths');
  assert.ok(logoutSrc.indexOf('remember_clear')>-1&&logoutSrc.indexOf('remember_clear')<logoutSrc.indexOf('$_SESSION = []'),'logout must clear the remember cookie before destroying the session');
  const laySrc=src('dashboard/layout.php');
  assert.ok((laySrc.match(/wishlist\.php/g)||[]).length>=7,'layout must wire wishlist into chrome: pages list, noindex, topbar, sidebars, bottom nav');
  assert.ok(laySrc.includes("'wishlist'=>['heart','المفضلة','wishlist.php']"),'customer sidebar must list المفضلة');
  assert.ok(laySrc.includes("'wishlist.php'=>['heart','المفضلة']"),'mobile bottom nav must include المفضلة');
  assert.ok(laySrc.includes("'wish_href' => $dash_pre . 'wishlist.php'"),'storefront header call must pass the wishlist link');
  assert.ok(laySrc.includes('title="المفضلة"')&&laySrc.includes('title="سلة التسوق"'),'panel topbar/sidebars must expose cart + wishlist to logged-in users');
  const idxSrc=src('index.php');
  assert.ok(idxSrc.includes("'wish_href' => 'dashboard/wishlist.php'"),'homepage header must show the wishlist heart');
  const wishSrc=src('dashboard/wishlist.php');
  assert.ok(wishSrc.includes("jload('wishlist.json'")&&wishSrc.includes('data-easy-order')&&wishSrc.includes('?rm=')&&wishSrc.includes("layout_top('المفضلة'"),'wishlist page must read the store, order via the dialog, and support link-based removal');
  assert.ok(wishSrc.includes('guest_')&&wishSrc.includes('name="csrf"'),'wishlist page must key guests like the hearts and post csrf-protected cart adds');
  const cssSrc=src('assets/style.css');
  assert.ok(cssSrc.includes('.wish-count')&&cssSrc.includes('.wish-wrap'),'style.css must style the wishlist badge');
}
// ---- توصيلات التعديل الكامل للطلبات (مسوق + إدارة) ----
{
  const cfgSrc=src('config.php');
  for(const fn of ['wf_order_edit_validate','wf_order_apply_row_edit','wf_order_edit_changed_fields','wf_order_comm_adjust','wf_admin_apply_order_edit'])
    assert.ok(cfgSrc.includes('function '+fn),'config.php must define '+fn);
  assert.ok(/REF_TEAM_RATE/.test(cfgSrc.slice(cfgSrc.indexOf('function wf_order_comm_adjust'))),'team commission share must be adjusted with the order commission');
  assert.ok(cfgSrc.includes("in_array((string)$comm_state, ['reversed', 'suspended'], true)"),'dead orders must never move wallets on commission edits');
  const mkSrc=src('dashboard/marketer.php');
  for(const f of ['name="unit"','name="ship"','name="m_earn"','name="extra_comm"','name="pname"','name="variant"','name="color"','name="size"'])
    assert.ok(mkSrc.includes(f),'marketer edit form must expose '+f);
  assert.ok(mkSrc.includes("'changed' => $mk_res['changed'] ?? []"),'marketer edit audit must record the changed fields');
  const admSrc=src('dashboard/admin.php');
  assert.ok(admSrc.includes("wf_admin_apply_order_edit((string)$_POST['adm_order_no']"),'admin.php must route the full-edit POST');
  assert.ok(admSrc.includes('adm_order_action')&&admSrc.includes('name="ref"')&&admSrc.includes('name="status"'),'admin full-edit form must include status + marketer code');
  assert.ok(admSrc.includes('?tab=orders&edit=')&&admSrc.includes('تعديل كامل'),'admin orders table must link to the full-edit view');
  assert.ok((admSrc.match(/name="m_earn"/g)||[]).length>=1&&admSrc.includes('name="extra_comm"'),'admin full-edit form must expose both commission fields');
}
// صيغة PHP سليمة (مفيش php CLI هنا)
if(fs.existsSync(path.join(NM,'php-parser'))){
  const Engine=require(path.join(NM,'php-parser'));
  const parser=new Engine({parser:{suppressErrors:false,version:'8.3'},ast:{withPositions:false},lexer:{comment_tokens:false}});
  for(const file of ['config.php','manual_products_lib.php','ui-components.php','supabase.php','index.php','store.php','landing.php','dashboard/manual_products.php','dashboard/product.php','dashboard/products.php','dashboard/marketer.php','dashboard/cart.php','dashboard/wishlist.php','dashboard/layout.php','dashboard/checkout_cart.php','dashboard/orders.php','login.php','register.php','logout.php','dashboard/admin_shipping.php','dashboard/shipping.php','dashboard/admin.php','dashboard/noon.php','dashboard/_payout.php','dashboard/index.php'])parser.parseCode(src(file),file);
}
(async()=>{
  const php=new PHP(await loadNodeRuntime('8.3',{emscriptenOptions:{processId:1,locateFile:p=>path.isAbsolute(p)?p:path.join(wasmDir,p)}}));
  php.mkdirTree('/app/data/logs');
  for(const file of FILES)php.writeFile('/app/'+file,fs.readFileSync(path.join(repo,file)));
  for(const file of DATA_FILES)php.writeFile('/app/'+file,fs.readFileSync(path.join(repo,file)));
  for(const [name,data] of Object.entries(FIXTURES))php.writeFile('/app/data/'+name,JSON.stringify(data));
  php.mkdirTree('/app/assets');php.writeFile('/app/assets/style.css','/*t*/');
  // أي مسافة/سطر فاضي قبل <?php كان بيتطبع قبل تحميل config ⇒ headers sent ⇒ session_start بيفشل
  php.writeFile('/app/__test.php',PHP_TESTS.replace(/^\s+/,''));
  const res=await php.run({code:"<?php require '/app/__test.php';"});
  const raw=Buffer.from(Object.values(res.bytes)).toString('utf8');
  assert.equal(res.exitCode,0,'PHP exited with '+res.exitCode+(res.errors?': '+res.errors:''));
  let results;try{results=JSON.parse(raw);}catch(e){throw new Error('Unparsable PHP output: '+raw.slice(0,600));}
  const failed=Object.entries(results).filter(([,v])=>v!=='OK'&&v!=='failures').map(([k,v])=>k+' => '+JSON.stringify(v));
  fs.mkdirSync(path.join(repo,'.cache'),{recursive:true});
  fs.writeFileSync(path.join(repo,'.cache/manual-country-currency.json'),JSON.stringify({assertions:Object.keys(results).length-1,failures:results.failures,detail:results},null,2));
  assert.equal(results.failures,0,'PHP assertions failed:\n'+failed.join('\n'));
  console.log({phpAssertions:Object.keys(results).length-1,failures:0,wiringChecks:'physical_collection/categories_by_mode/tabs/form/csv/remember_me/wishlist/robust_json/qty_caps/syntax OK'});
  process.exit(0);
})().catch(error=>{console.error(error.message||error);process.exit(1);});
