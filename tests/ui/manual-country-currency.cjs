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
const csvTemplate=fs.readFileSync(path.join(repo,'manual_products_import.csv'),'utf8').replace(/^\uFEFF/,'');
assert.deepStrictEqual(csvTemplate.split('\n')[0].split(','),['name','price','country','category','stock','sku','description','colors','sizes','material','weight','dimensions','images','faqs'],'CSV template header must include country after price');
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
  ['landing.php',/render_easyorder_dialog_html\(\$ref, \$plat, wf_ui_currency\(\$p\)\)/],
  ['config.php',/wf_bulk_discount_state\(\$default_plat, \$default_currency\)/],
  ['config.php',/wf_bulk_discount_plat_ok\(\$default_plat, \$default_currency\)/],
];
for(const [file,re] of bulkCallers)assert.ok(re.test(src(file)),file+' must pass the product currency to the quantity-discount helpers');
// معاينة الخصم في الواجهة لازم تطابق السيرفر (متوريشتش خصم على منتج مش بالجنيه)
const uijs=src('assets/ui.js');
assert.ok(/function eoBulkApplies\(plat,cfg,currency\)/.test(uijs)&&/cfg\.egp_only/.test(uijs),'ui.js must skip the discount preview for non-EGP products');
assert.ok(/eoBulkApplies\(e&&e\.plat,cfg,e&&e\.currency\)/.test(uijs),'the easy-order dialog must check the clicked product currency');
assert.ok(/function eoPageBulk\(q,plat,currency\)/.test(uijs)&&/eoPageBulk\(q,p,c\)/.test(uijs),'the product page bulk preview must use the product currency');
// صيغة PHP سليمة (مفيش php CLI هنا)
if(fs.existsSync(path.join(NM,'php-parser'))){
  const Engine=require(path.join(NM,'php-parser'));
  const parser=new Engine({parser:{suppressErrors:false,version:'8.3'},ast:{withPositions:false},lexer:{comment_tokens:false}});
  for(const file of ['config.php','manual_products_lib.php','ui-components.php','index.php','landing.php','dashboard/manual_products.php','dashboard/product.php','dashboard/products.php','dashboard/marketer.php','dashboard/cart.php','dashboard/checkout_cart.php','dashboard/orders.php'])parser.parseCode(src(file),file);
}
(async()=>{
  const php=new PHP(await loadNodeRuntime('8.3',{emscriptenOptions:{processId:1,locateFile:p=>path.isAbsolute(p)?p:path.join(wasmDir,p)}}));
  php.mkdirTree('/app/data/logs');
  for(const file of FILES)php.writeFile('/app/'+file,fs.readFileSync(path.join(repo,file)));
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
