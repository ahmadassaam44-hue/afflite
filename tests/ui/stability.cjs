// Optional local regression test. Requires the isolated fixtures described in README.md.
// Never run these order checks against production: all POSTs are local synthetic test orders.
const {chromium}=require('../../.cache/ui-tools/node_modules/playwright');
const assert=require('node:assert'),fs=require('node:fs');
const base=process.env.UI_TEST_URL||'http://127.0.0.1:8080';
assert(/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base),'Local test server required');
const axe=fs.readFileSync('.cache/ui-tools/node_modules/axe-core/axe.min.js','utf8');
const results={flows:[],responsive:[],shipping:[]};
let browser;
(async()=>{
 browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/tmp/chromium',headless:true,ignoreDefaultArgs:['--disable-back-forward-cache'],args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 const context=await browser.newContext({viewport:{width:390,height:844}}),page=await context.newPage(),errors=[];
 page.on('pageerror',e=>errors.push(e.message));await page.route('https://**/*',r=>r.abort());
 const pass=name=>{results.flows.push(name);console.log('PASS',name);};
 const login=role=>page.goto(base+'/_ui_fixture_login.php?role='+role,{waitUntil:'domcontentloaded'});
 await login('marketer');
 await page.goto(base+'/dashboard/marketer.php?mtab=performance');
 assert(!/لوحة التحكم الرئيسية|أسعار الشحن للمحافظات/.test(await page.locator('body').innerText()));
 assert.equal(await page.locator('#dashSide a[href*="mtab=shipping"]').count(),0);
 assert.equal(await page.locator('.side-store-box').count(),0);
 assert.equal(await page.locator('#dashSide a[href*="mtab=store"]').count(),1);
 assert.equal((await page.locator('#dashSide a[href*="mtab=store"]').textContent()).trim(),'إدارة متجري');
 await page.goto(base+'/dashboard/index.php');assert(page.url().includes('marketer.php?mtab=performance'));
 await page.goto(base+'/dashboard/shipping.php');assert(page.url().includes('marketer.php?mtab=performance'));
 for(const query of ['mtab=shipping','mtab=missing','mtab%5B%5D=orders']){
  await page.goto(base+'/dashboard/marketer.php?'+query);assert((await page.locator('main').innerText()).includes('ملخص الأداء والأرباح'));
 }
 pass('Affiliate home/shipping removed; old/unknown tab URLs render a useful page');
 await page.goto(base+'/dashboard/marketer.php?mtab=store');
 assert(!/منتجات المتجر:|متجري\s*\(\d+\)/.test(await page.locator('main').innerText()));
 await page.goto(base+'/dashboard/marketer.php?mtab=browse');
 assert(!/عرض\s+\d+\s+من\s+[\d,]+\s+منتج/.test(await page.locator('main').innerText()));
 assert(!/\([\d,]+\)/.test(await page.locator('.catalog-categories').textContent()));
 pass('Affiliate catalogue/category/store inventory counts removed, without removing products');
 await page.goto(base+'/dashboard/marketer.php?mtab=performance');await page.click('#dashDrawerBtn');
 assert(await page.locator('#dashSide').evaluate(e=>e.classList.contains('open')));
 await page.locator('#dashSide a[href="marketer.php?mtab=orders"]').click();await page.waitForURL('**/marketer.php?mtab=orders');
 await page.goBack({waitUntil:'domcontentloaded'});
 assert.equal(await page.locator('[inert]').count(),0);assert.equal(await page.evaluate(()=>document.body.style.overflow),'');
 assert(!await page.locator('#dashSide').evaluate(e=>e.classList.contains('open')));
 await page.click('#dashDrawerBtn');await page.evaluate(()=>window.wfConfirm('اختبار محلي فقط',()=>{throw new Error('Confirmation must not submit during restoration');}));
 await page.evaluate(()=>window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true})));
 assert.equal(await page.locator('[inert]').count(),0);assert.equal(await page.locator('#wfConfirmModal.open').count(),0);
 assert.equal(await page.evaluate(()=>document.body.style.overflow),'');
 pass('Real Back navigation and persisted pageshow release drawers, nested dialogs, inertness and scroll locks');
 await login('guest');await page.goto(base+'/index.php');
 assert(!/\d[\d,]*\s+منتج متاح|عرض\s+\d+\s*[–-]\s*\d+\s+من/.test(await page.locator('body').innerText()));
 assert(!/\([\d,]+\)/.test(await page.locator('.filter-chips-scroll[aria-label="فلتر الأقسام"]').innerText()));
 await page.goto(base+'/store.php?store=MKT-TEST');assert(!/\([\d,]+\)/.test(await page.locator('.eo-cats').innerText()));
 pass('Public homepage and independent store category/result counts removed');
 await page.goto(base+'/index.php');
 const examples=[
  {plat:'angazny',id:'2955',price:220,city:'البحر الأحمر',fee:120},
  {plat:'timehand',id:'-03Q0TH',price:360,city:'أسوان',fee:95},
  {plat:'safqa',id:'6aa16dfdca38d5013ca5eea5',price:180,city:'قنا',fee:95},
  {plat:'vendoor',id:'6029',price:199,city:'القاهرة',fee:80}
 ];
 for(const example of examples){
  await page.evaluate(e=>window.openEasyOrderModal({...e,name:'منتج اختبار محلي',base_price:e.price,qty:2,ref:'MKT-TEST',currency:'ج.م'}),example);
  await page.selectOption('#eoBuyerCity',example.city);
  assert.equal((await page.locator('#eoInvShip').innerText()).trim(),example.fee+' ج.م');
  assert.equal(await page.locator('#eoInvTotal').innerText(),String(example.price*2+example.fee));
  const displayed=await page.locator('#eoBuyerCity').evaluate(el=>Object.fromEntries(Array.from(el.options).filter(o=>o.value).map(o=>[o.value,Number(o.dataset.ship)])));
  const json=JSON.parse(fs.readFileSync('data/shipping.json','utf8'));const platform=json.find(p=>p.platform===(example.plat==='safqa'?'safka':example.plat));
  assert.deepStrictEqual(displayed,Object.fromEntries(platform.regions.map(r=>[r.region.trim(),Number(r.fee)])));
  if(['angazny','timehand'].includes(example.plat)){
   await page.fill('#eoBuyerName','عميل اختبار محلي');await page.fill('#eoBuyerPhone','01012345678');await page.fill('#eoBuyerDistrict','مدينة اختبار');await page.fill('#eoBuyerAddress','عنوان محلي معزول');
   const received=page.waitForResponse(r=>r.url().endsWith('/dashboard/orders.php')&&r.request().method()==='POST');
   await page.click('#eoSubmitBtn');const data=await(await received).json();assert(data.ok,JSON.stringify(data));assert.equal(data.ship_fee,example.fee);assert.equal(data.qty,2);assert.equal(data.ref,'MKT-TEST');assert.equal(Number(data.grand_total),example.price*2+example.fee);
  }
  results.shipping.push({platform:example.plat,governorate:example.city,fee:example.fee});await page.evaluate(()=>window.closeEasyOrderModal());
 }
 pass('All Egyptian supplier modal tables match untouched shipping data; local server orders retain 120/95 fees, qty and ref');
 const bad=await context.request.post(base+'/dashboard/orders.php',{form:{ajax_order:'1',id:'2955',plat:'angazny'},headers:{'X-Requested-With':'XMLHttpRequest'}});
 assert.equal(bad.status(),403);assert.equal((await bad.json()).ok,false);pass('Existing CSRF rejection remains 403 JSON');
 const fatal=await context.request.get(base+'/_ui_fatal.php');assert.equal(fatal.status(),500);assert((await fatal.text()).includes('تعذر تحميل الصفحة'));assert(!(await fatal.text()).includes('PRIVATE_DIAGNOSTIC'));
 const jsonFatal=await context.request.get(base+'/_ui_fatal.php?json=1');assert.equal(jsonFatal.status(),500);assert.equal((await jsonFatal.json()).ok,false);pass('Uncaught-error fallback is private, navigable HTML / valid JSON, without automatic POST retry');
 const slow=await browser.newContext({viewport:{width:390,height:844}}),slowPage=await slow.newPage();await slowPage.route('**/assets/ui.js*',r=>r.abort());await slowPage.route('https://**/*',r=>r.abort());
 await slowPage.goto(base+'/_ui_fixture_login.php?role=marketer');await slowPage.goto(base+'/dashboard/marketer.php?mtab=store');
 assert(await slowPage.locator('table tbody td').first().isVisible());pass('PHP-rendered tables stay visible when the UI script is blocked');await slow.close();
 const cases=[['guest','/index.php'],['guest','/index.php?mode=digital'],['guest','/index.php?mode=social'],['guest','/store.php?store=MKT-TEST'],['guest','/landing.php?id=6029&plat=vendoor&ref=MKT-TEST'],['guest','/dashboard/product.php?id=not-present&plat=vendoor'],['marketer','/dashboard/marketer.php?mtab=performance'],['marketer','/dashboard/marketer.php?mtab=store'],['marketer','/dashboard/marketer.php?mtab=browse'],['marketer','/dashboard/marketer.php?mtab=browse&mmode=digital'],['marketer','/dashboard/marketer.php?mtab=browse&mmode=social'],['marketer','/dashboard/marketer.php?mtab=orders'],['admin','/dashboard/admin_shipping.php'],['admin','/dashboard/shipping.php']];
 for(const [role,url]of cases){
  await login(role);await page.setViewportSize({width:390,height:844});const response=await page.goto(base+url,{waitUntil:'domcontentloaded'});assert(response.status()<400,url);await page.evaluate(()=>document.fonts.ready);
  await page.addScriptTag({content:axe});const audit=await page.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}}));
  const row={url,axe:audit.violations.map(v=>({id:v.id,targets:v.nodes.slice(0,3).map(n=>n.target),failure:v.nodes.slice(0,3).map(n=>n.failureSummary)})),widths:[]};
  for(const width of [360,390,768,1024,1280,1536]){await page.setViewportSize({width,height:844});row.widths.push(await page.evaluate(()=>({viewport:innerWidth,document:document.documentElement.scrollWidth})));}
  results.responsive.push(row);assert.equal(row.axe.length,0,url+': '+JSON.stringify(row.axe));assert(row.widths.every(w=>w.document<=w.viewport+1),url+': overflow');
 }
 assert.deepStrictEqual(errors,[]);pass('14 targeted views × 6 widths: no overflow, Axe WCAG findings or JavaScript errors');
 fs.writeFileSync('.cache/stability-results.json',JSON.stringify(results,null,2));await browser.close();
})().catch(async error=>{console.error(error);if(browser)await browser.close();process.exitCode=1;});
