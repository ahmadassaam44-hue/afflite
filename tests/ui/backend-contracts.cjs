const fs=require('fs'),assert=require('assert'),Engine=require('../../.cache/ui-tools/node_modules/php-parser');const parser=new Engine({parser:{suppressErrors:false,version:'8.3'},ast:{withPositions:false},lexer:{comment_tokens:false}});
const {execFileSync}=require('child_process');const baseline=process.env.UI_BASE_REF||'5abb787';function readSource(path){return path.startsWith('.cache/ui-baseline/')?execFileSync('git',['show',baseline+':'+path.replace('.cache/ui-baseline/','')],{encoding:'utf8'}):fs.readFileSync(path,'utf8');}function functions(path){const ast=parser.parseCode(readSource(path),path);return Object.fromEntries(ast.children.filter(n=>n.kind==='function').map(n=>[typeof n.name==='string'?n.name:n.name.name,n]));}
function stable(node){if(Array.isArray(node))return node.map(stable);if(node&&typeof node==='object')return Object.fromEntries(Object.entries(node).filter(([k])=>!['loc','leadingComments','trailingComments','comments','raw'].includes(k)).map(([k,v])=>[k,stable(v)]));return node;}
const runtimeFixes=process.env.UI_RUNTIME_FIXES==='1'?['jload','find_product_by_id']:[];
// تغييرات مقصودة وموثّقة: نواة خصم الكمية + أدوار الحساب (مشترٍ/مسوّق) + إصلاح تبويبات المسوق.
// باقي دوال التسعير والعمولات والسحب تفضل محميّة بالمقارنة دي.
const intendedChanges=['wf_bundle_total','wf_landing_bundle_discount','ensure_user_affiliate','wf_marketer_link_stats'];
const before=functions('.cache/ui-baseline/config.php'),after=functions('config.php');const uiOnly=['render_easyorder_dialog_html','render_product_details_html','vd_size_chart_html','mz_sizechart_html'];let checked=0;
for(const [name,node]of Object.entries(before)){assert(after[name],'Removed PHP function '+name);if(uiOnly.includes(name)||runtimeFixes.includes(name)||intendedChanges.includes(name))continue;assert.deepStrictEqual(stable(after[name]),stable(node),'Unexpected non-UI function change: '+name);checked++;}
const payoutBefore=functions('.cache/ui-baseline/dashboard/_payout.php'),payoutAfter=functions('dashboard/_payout.php');assert.deepStrictEqual(stable(payoutAfter.payout_handle_post),stable(payoutBefore.payout_handle_post));
for(const name of ['REF_TEAM_RATE','REF_TEAM_LABEL','MAX_MARKUP_PCT','MAX_MARKUP_AMOUNT','PRICE_MARGIN_PCT']){const re=new RegExp("define\\('"+name+"', [^;]+;"),b=readSource('.cache/ui-baseline/config.php').match(re),a=fs.readFileSync('config.php','utf8').match(re);assert.equal(a&&a[0],b&&b[0],name+' changed');}
// عقود خصم الكمية: 70 ج.م عن كل قطعة إضافية، للمشتملة فقط (الرقمي والسوشيال مستثنيان)
{const cfg=fs.readFileSync('config.php','utf8');
assert.ok(/define\('LP_BUNDLE_DISCOUNT', 70\);/.test(cfg),'LP_BUNDLE_DISCOUNT must stay 70 EGP');
assert.ok(/LP_BUNDLE_DISCOUNT \* \(\$q - 1\)/.test(cfg),'bulk discount must be per extra piece (qty-1)');
assert.ok(/function wf_bulk_discount_plat_ok\(\$plat = ''\) \{\s*return !function_exists\('wf_is_digital_plat'\) \|\| !wf_is_digital_plat\(\$plat\);/.test(cfg),'bulk discount must skip digital/social platforms');
assert.ok(/function wf_bundle_total\(\$unit, \$qty, \$plat = ''\)/.test(cfg),'wf_bundle_total must stay plat-aware');
assert.ok(/'total'\s*=>\s*round\(\$sale \* \$qty, 2\),/.test(cfg),'server_order_line total must stay gross (discount is applied on the order total only)');
assert.ok(/'discount'\s+=> \(float\)\$bundle_disc,/.test(fs.readFileSync('dashboard/orders.php','utf8')),'orders.php must persist the quantity discount on the order row');
assert.ok(/data-checkout-discount=/.test(fs.readFileSync('dashboard/checkout_cart.php','utf8')),'cart checkout summary must show the quantity discount');
const ui=fs.readFileSync('assets/ui.js','utf8');
assert.ok(ui.includes('function eoBulkCfg(')&&ui.includes('function eoBulkApplies(')&&ui.includes('function eoComputeBulk('),'dialog must reuse the shared bulk state helpers');
assert.ok(/bulkOK===false/.test(ui),'dialog must skip the discount for digital/social platforms');
assert.ok(ui.includes('window.eoLpBulk=')&&ui.includes('eoProductBulk('),'landing chips and product page must use the same per-extra-piece math');
assert.ok(/Math\.max\(0,i\+r-D\)/.test(ui),'cart checkout total must subtract the quantity discount');
assert.ok(ui.includes('"wfBulkState"'),'UI must read the single server-rendered bulk discount state');
}
const results={configFunctionsUnchanged:checked,payoutHandlerUnchanged:true,constantsUnchanged:true,runtimeFixesReviewedSeparately:runtimeFixes};fs.writeFileSync('.cache/backend-contract-results.json',JSON.stringify(results,null,2));console.log(results);
