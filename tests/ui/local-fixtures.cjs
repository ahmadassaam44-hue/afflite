// Development-only fixtures. Never upload the copied site or its login/probe helpers.
const fs = require('node:fs'), path = require('node:path'), {execFileSync} = require('node:child_process');
const repo = path.resolve(__dirname, '../..');
const root = path.resolve(repo, process.env.UI_FIXTURE_ROOT || '.cache/site');
if (!root.startsWith(path.join(repo, '.cache') + path.sep)) throw new Error('Fixtures must live inside repository .cache/');
const baseline = process.env.UI_FIXTURE_BASE;
const files = execFileSync('git', ['ls-files', '-z'], {cwd:repo}).toString().split('\0').filter(Boolean);
for (const file of files) {
 if (!(/\.php$/.test(file) || /^(assets|data)\//.test(file) || /^(manifest\.json|sw\.js)$/.test(file))) continue;
 const dest = path.join(root, file); fs.mkdirSync(path.dirname(dest), {recursive:true});
 try { fs.writeFileSync(dest, baseline ? execFileSync('git',['show',`${baseline}:${file}`],{cwd:repo,maxBuffer:64*1024*1024}) : fs.readFileSync(path.join(repo,file))); }
 catch(e) { if (!baseline) throw e; }
}
const write = (file,value) => {const dest=path.join(root,file);fs.mkdirSync(path.dirname(dest),{recursive:true});fs.writeFileSync(dest,typeof value==='string'?value:JSON.stringify(value,null,2));};
const read = file => JSON.parse(fs.readFileSync(path.join(root,file),'utf8'));
fs.mkdirSync(path.join(root,'.sessions'),{recursive:true});
const simple={id:'UI-CHOICES',name:'قميص اختبار اللون والمقاس',price:250,price_text:'250 جنيه',commission:0,images:['/assets/ui-fixture.svg'],videos:[],desc:'منتج اصطناعي لاختبار انتقال اللون والمقاس فقط.',category:'اختبار محلي',country:'مصر',stock:999999,sizes:['M','XL'],extra:{colors:['أحمر','أسود'],variants_detailed:[]}};
const grouped={...simple,id:'UI-GROUPED',name:'حذاء اختبار المتغيرات المجمعة',sizes:['41','43'],extra:{colors:['أحمر','أسود'],variants_detailed:[{color:'أحمر',size:'41',price:'',stock:999999},{color:'أحمر',size:'43',price:'',stock:999999},{color:'أسود',size:'41',price:'',stock:999999},{color:'أسود',size:'43',price:'',stock:999999}]}};
const ag={...simple,id:'UI-MODELS',name:'قميص اختبار الموديل واللون والمقاس',extra:{colors:['ساده احمر','نايكي اسود'],variants_detailed:[{color:'ساده احمر',size:'M',price:'',stock:999999},{color:'ساده احمر',size:'XL',price:'',stock:999999},{color:'نايكي احمر',size:'M',price:'',stock:999999},{color:'نايكي احمر',size:'XL',price:'',stock:999999},{color:'نايكي اسود',size:'M',price:'',stock:999999},{color:'نايكي اسود',size:'XL',price:'',stock:999999}]}};
const radio={...simple,id:'UI-RADIO',name:'اختبار جدول اختيار المقاس',extra:{colors:['أحمر','أسود'],variants_detailed:[{color:'',size:'M',price:'',stock:999999},{color:'',size:'XL',price:'',stock:999999}]}};
const noChoices={...simple,id:'UI-NONE',name:'اختبار منتج بلا ألوان ومقاسات',sizes:[],extra:{colors:[],variants_detailed:[]}};
for (const [platform, additions] of Object.entries({vendoor:[simple,grouped,noChoices],angazny:[ag],timehand:[radio]})) {
 for (const prefix of ['', 'lite_']) {const file=`data/${prefix}${platform}.json`;write(file,[...additions,...read(file).filter(p=>!String(p.id).startsWith('UI-'))]);}
}
const index=read('data/id_index.json');Object.assign(index,{'UI-CHOICES':'vendoor','UI-GROUPED':'vendoor','UI-NONE':'vendoor','UI-MODELS':'angazny','UI-RADIO':'timehand'});write('data/id_index.json',index);
write('assets/ui-fixture.svg','<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240"><rect width="240" height="240" fill="#e2e8f0"/></svg>');
const users=[
 {email:'marketer@ui.test',name:'مسوق اختبار محلي',role:'marketer',ref_code:'MKT-TEST',balance:1000,expected:0,earned:0,at:'2026-09-01T12:00:00Z'},
 {email:'other@ui.test',name:'مسوق آخر للاختبار',role:'marketer',ref_code:'MKT-OTHER',balance:0,expected:0,earned:0,at:'2026-09-01T12:00:00Z'},
 {email:'buyer@ui.test',name:'عميل اختبار',role:'buyer',ref_code:'MKT-BUYER',balance:0,expected:0,earned:0,at:'2026-09-01T12:00:00Z'},
 {email:'admin@ui.test',name:'أدمن اختبار محلي',role:'admin',ref_code:'',balance:0,expected:0,earned:0,at:'2026-09-01T12:00:00Z'}
];write('data/users.json',users);
const products={};for(const [plat,id]of [['vendoor','UI-CHOICES'],['vendoor','UI-GROUPED'],['vendoor','UI-NONE'],['angazny','UI-MODELS'],['timehand','UI-RADIO'],['vendoor','6029'],['angazny','2955']]) products[`${plat}#${id}`]={plat,id,extra_comm:0,added_at:'2026-09-01T12:00:00Z'};
write('data/marketer_stores.json',{'MKT-TEST':{title:'متجر اختبار محلي',bio:'متجر اصطناعي للاختبار فقط.',theme:'#059669',products},'MKT-OTHER':{title:'متجر آخر للاختبار',products:{}}});
const orders=Array.from({length:35},(_,i)=>({order_no:`UI-TEST-${100+i}`,id:'6029',plat:'vendoor',pname:`منتج سجل قديم ${i}`,ref:'MKT-TEST',user:'guest:01012345678',variant:i===0?'اللون: أزرق | المقاس: XL':i===1?'موديل قديم / أسود / 43':'',qty:1,unit:199,base_price:199,markup:0,ship:80,total:279,m_earn:0,comm_state:'none',status:i%3===0?'delivered':'pending',at:'2026-09-01T12:00:00Z',cname:`عميل الاختبار ${i}`,cphone:'01012345678',city:'القاهرة',district:'مدينة اختبار',address:'عنوان اختبار محلي',notes:''}));
orders.push({...orders[0],order_no:'UI-OTHER-PRIVATE',ref:'MKT-OTHER',cname:'بيانات المتجر الآخر لا تظهر',variant:'اللون: أخضر | المقاس: S'});
write('data/orders.json',orders);
for(const file of ['notifications','clicks','payouts','events','visitors','ratelimit','cart_log','love_log','wishlist','resets','fraud_alerts','ledger','presence']) write(`data/${file}.json`,[]);
write('data/settings.json',{});
write('_ui_fixture_login.php',`<?php
require __DIR__ . '/config.php';
$role = $_GET['role'] ?? 'guest';
$_SESSION = [];
$emails = ['marketer'=>'marketer@ui.test','other'=>'other@ui.test','buyer'=>'buyer@ui.test','admin'=>'admin@ui.test'];
if (isset($emails[$role])) $_SESSION['user'] = find_user($emails[$role]);
if (empty($_GET['empty'])) $_SESSION['cart'] = [['key'=>'vendoor#6029|','id'=>'6029','plat'=>'vendoor','pname'=>'منتج سلة اختبار','price'=>199,'base'=>199,'qty'=>1,'ref'=>'MKT-TEST','variant'=>'اللون: أزرق | المقاس: XL','img'=>'/assets/ui-fixture.svg']];
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>دخول اختبار محلي</title><p>جلسة اصطناعية محلية فقط</p></html>';
`);
write('_ui_probe.php',`<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['cart'=>$_SESSION['cart'] ?? [],'csrf'=>csrf_token(),'price'=>server_order_line('UI-CHOICES','vendoor',2,null,'MKT-TEST')], JSON_UNESCAPED_UNICODE);
`);
write('_ui_fatal.php',`<?php require __DIR__ . '/config.php'; if (!empty($_GET['json'])) header('Content-Type: application/json; charset=utf-8'); throw new RuntimeException('PRIVATE_DIAGNOSTIC_NOT_FOR_VISITORS');`);
console.log('Prepared isolated synthetic fixtures:',path.relative(repo,root),baseline?`baseline ${baseline}`:'working tree');
