'use strict';

/* ========= State & utils ========= */
let STATE = {};
let curCompanyIndex = 0;

const DEFAULT_SHOW_UNITS = 'unit2'; // لا يؤثر على الحفظ — للعرض فقط
const DEFAULT_MARGIN_U1  = 5;       // % هامش افتراضي لسعر الوحدة 1 عند الاشتقاق

function qs(s, p){ return (p||document).querySelector(s); }
function qsa(s, p){ return Array.from((p||document).querySelectorAll(s)); }
function setMsg(t, ok){
  const el = qs('#msg'); if(!el) return;
  el.textContent = t||'';
  el.style.color = (ok === false) ? '#c0392b' : '#0a7a3c';
}
function setSrcBadge(t){ const b = qs('#srcBadge'); if(b) b.textContent = t||'—'; }
function num(v,d=0){ const n=parseFloat(v); return Number.isFinite(n)?n:d; }
function str(v){ return (v==null?'':String(v)); }
function round2(x){ return Math.round((Number(x||0)+Number.EPSILON)*100)/100; }
function truthyBool(v){ return !(v===false || v==='false' || v===0 || v==='0'); }

/* ========= Auth helpers ========= */
const AUTH_KEY = 'zz_admin_key';
function readPwdInput(){
  const el = qs('#admPwd');
  const v = el && el.value ? el.value.trim() : '';
  return v || null;
}
async function getAuthKey(interactive=true){
  let k = localStorage.getItem(AUTH_KEY)||'';
  const inline = readPwdInput();
  if(inline){ k = inline; localStorage.setItem(AUTH_KEY, k); }
  if(!k && interactive){
    k = (await Promise.resolve(prompt('أدخل كلمة مرور لوحة الإدارة:')||'')).trim();
    if(k) localStorage.setItem(AUTH_KEY,k);
  }
  return k||'';
}
function clearAuthKey(){ try{ localStorage.removeItem(AUTH_KEY);}catch(_){} }

/* ========= Local backup ========= */
const BK_KEY = 'zz_admin_backup_v2';
function saveBackup(state){ try{ localStorage.setItem(BK_KEY, JSON.stringify(state)); }catch(_){} }
function loadBackup(){ try{ const s=localStorage.getItem(BK_KEY); return s?JSON.parse(s):null; }catch(_){ return null; }}

/* ========= Load ========= */
async function reloadData(){
  setMsg('جارٍ التحميل…');
  async function tryJson(){
    const r = await fetch('data.json?ts=' + Date.now(), {cache:'no-store'});
    if(!r.ok) throw new Error('no data.json');
    return await r.json();
  }
  function tryDataJs(){
    const d = window.ZAMZAM_DATA || window.DATA;
    if(!d) throw new Error('no window.ZAMZAM_DATA');
    return d;
  }
  function tryBackup(){
    const b = loadBackup();
    if(!b) throw new Error('no local backup');
    return b;
  }

  let loadedFrom = '';
  try {
    try { STATE = await tryJson(); loadedFrom = 'data.json'; }
    catch(_e1){ try { STATE = tryDataJs(); loadedFrom = 'data.js'; }
    catch(_e2){ STATE = tryBackup(); loadedFrom = 'Backup'; } }

    if(!STATE || typeof STATE!=='object') throw new Error('bad state');

    if (Array.isArray(STATE.adminCompanies) && STATE.adminCompanies.length){
      STATE.companies = STATE.adminCompanies;
      setSrcBadge(loadedFrom + ' (adminCompanies)');
    } else if (Array.isArray(STATE.companies) && STATE.companies.some(co => (co.products||[]).some(looksLikeSiteProduct))){
      STATE.companies = siteCompaniesToAdminCompanies(STATE.companies);
      setSrcBadge(loadedFrom + ' (migrated site→admin)');
    } else {
      setSrcBadge(loadedFrom);
    }

    STATE.metadata = STATE.metadata || { siteName:'ينابيع زمزم', whatsappNumber:'', lastUpdate:'' };
    if(!STATE.mergedOffers) STATE.mergedOffers = { strong:{active:false,title:'الدمج القوي',items:[]}, free:{active:false,title:'الدمج الحر',items:[]} };
    if(!STATE.featuredOffers) STATE.featuredOffers = { active:false, title:'العروض المميزة', items:[] };
    if(!Array.isArray(STATE.companies)) STATE.companies = [];
    for (let i=0;i<STATE.companies.length;i++){
      const c = STATE.companies[i] || {};
      c.settings = c.settings || {};
      if(!c.settings.showUnits) c.settings.showUnits = DEFAULT_SHOW_UNITS;
      if(typeof c.settings.marginU1!=='number') c.settings.marginU1 = DEFAULT_MARGIN_U1;
      if (typeof c.showInCompanies === 'undefined') c.showInCompanies = true; // [ADD B3]

    }

    renderAll();
    setMsg('تم التحميل ✓');
    saveBackup(STATE);
  } catch(e){
    console.error(e);
    setMsg('فشل التحميل: ' + (e.message||e), false);
    setSrcBadge('—');
  }
}

/* ========= Deep set ========= */
function setPath(obj, path, val){
  const parts = path.split('.');
  let o = obj;
  for(let i=0;i<parts.length-1;i++){
    const k = parts[i];
    if(!(k in o)) o[k] = (String(+parts[i+1]) === parts[i+1]) ? [] : {};
    o = o[k];
  }
  o[parts[parts.length-1]] = val;
}

/* ========= Events ========= */
document.addEventListener('input', function(e){
  const t = e.target;
  if(!t || !t.dataset || !t.dataset.path) return;
  let v = (t.type === 'checkbox') ? t.checked : t.value;
  if(t.type === 'number'){
    const n = parseFloat(v);
    v = Number.isFinite(n) ? n : 0;
  }
  setPath(STATE, t.dataset.path, v);
}, true);

document.addEventListener('change', function(e){
  if(e.target && e.target.id === 'companySelect'){
    curCompanyIndex = +e.target.value;
    renderProducts();
  }
  if(e.target && e.target.id === 'admPwd'){
    const v = e.target.value.trim();
    if(v) localStorage.setItem(AUTH_KEY, v);
  }
});

// ====== منع الإضافة الحرّة + فتح المُلتقط ======
document.addEventListener('click', async function(e){
  const t = e.target;
  if(!t) return;
    // إنشاء صف منتج جديد للشركة المحددة
  if(t.matches('#addProduct')){
    e.preventDefault();
    const c = (STATE.companies||[])[curCompanyIndex];
    if(!c){ setMsg('اختر شركة أولاً', false); return; }
    c.products = c.products || [];
    c.products.push({
      id: 'p' + Date.now(),
      title:'', note:'',
      u1_unit:'', price1:'', minQtyU1:'',
      u2_unit:'', u2_count:'', price2:'',
      u3_unit:'', u3_count:'', price3:'',
      packaging:'', price:'',
      status:'', code:'', visible:true, image:''
    });
    renderProducts();
    return;
  }


  if(t.matches('#btnReload')) { e.preventDefault(); reloadData(); }
  if(t.matches('#btnSave'))   { e.preventDefault(); await saveAll(); }

  // بدلاً من إنشاء صفوف فارغة: افتح مُلتقط الشركات
  if(t.matches('#addFeaturedItem')){ e.preventDefault(); openOfferPicker('featured'); return; }
  if(t.matches('#addStrongItem'))  { e.preventDefault(); openOfferPicker('strongA');  return; }
  if(t.matches('#addFreeItem'))    { e.preventDefault(); openOfferPicker('freeA');    return; }

  if(t.matches('#openOfferPicker')){ e.preventDefault(); openOfferPicker('featured'); return; }

  if(t.matches('#op_close')){ e.preventDefault(); closeOfferPicker(); return; }

  // اختيار من جدول المودال
  if(t.classList && t.classList.contains('op_add')){
    const cidx = +op_el('op_company').value;
    const comp = (STATE.companies||[])[cidx]; if(!comp) return;
    const p = (comp.products||[])[+t.dataset.i]; if(!p) return;

    const mode = op_el('op_type').value; // featured | strongA | freeA
    curCompanyIndex = cidx;

    if(mode === 'featured'){
      addProductToOffer(comp, p, 'featured');
      setMsg('أُضيف للمميز من أصل الشركات ✓');
    }else if(mode === 'strongA'){
      addProductToOffer(comp, p, 'strongMix');
      setMsg('أُضيف للدمج القوي (A) ✓ — أكمل الطرف B من الزر داخل الجدول.');
    }else if(mode === 'freeA'){
      addProductToOffer(comp, p, 'freeMix');
      setMsg('أُضيف للدمج الحر (A) ✓ — أكمل الطرف B من الزر داخل الجدول.');
    }
    closeOfferPicker();
    return;
  }

  // اختيار الطرف B من داخل صفوف الجداول
  if(t.classList && t.classList.contains('op_pick_b')){
    const row = +t.dataset.i || 0;
    OP_BIND = { mode:'strongB', row };
    openOfferPicker('featured'); // النوع لا يهم هنا — الربط عبر OP_BIND
    return;
  }
  if(t.classList && t.classList.contains('op_pick_b_free')){
    const row = +t.dataset.i || 0;
    OP_BIND = { mode:'freeB', row };
    openOfferPicker('featured');
    return;
  }
}, true);

document.addEventListener('input', function(e){
  if(e.target && e.target.id === 'op_search'){ renderOfferPickerBody(); }
  if(e.target && e.target.id === 'op_company'){ renderOfferPickerBody(); }
}, true);

/* ========= Renderers ========= */
function renderAll(){
  const m = STATE.metadata || {};
  const sn = qs('#siteName'); if(sn) sn.value = m.siteName || '';
  const wa = qs('#wa');       if(wa) wa.value = m.whatsappNumber || '';

  renderFeatured();
  renderStrong();
  renderFree();
  renderCompanies();
  renderCompanySelect();
  renderProducts();
}

function renderFeatured(){
  const tb = qs('#tblFeatured tbody'); if(tb) tb.innerHTML = '';
  const items = (STATE.featuredOffers && STATE.featuredOffers.items) || [];
  for(let i=0;i<items.length;i++){
    const it = items[i] || {};
    const original   = num(it.originalPrice, 0);
    const discounted = num(it.discountedPrice, 0);
    const diff = (original && discounted) ? (original - discounted) : 0;
    const tr = document.createElement('tr');
    tr.innerHTML =
      '<td><input type="text" data-path="featuredOffers.items.'+i+'.title" value="'+(it.title||'')+'"></td>'+
      '<td><input type="text" data-path="featuredOffers.items.'+i+'.unit" value="'+(it.unit||'')+'"></td>'+
      '<td><input type="text" data-path="featuredOffers.items.'+i+'.packaging" value="'+(it.packaging||'')+'"></td>'+
      '<td><input type="number" step="0.01" data-path="featuredOffers.items.'+i+'.originalPrice" value="'+(it.originalPrice||'')+'"></td>'+
      '<td><input type="number" step="0.01" data-path="featuredOffers.items.'+i+'.discountedPrice" value="'+(it.discountedPrice||'')+'"></td>'+
      '<td><span>'+((typeof diff.toFixed==='function')? diff.toFixed(2) : diff)+'</span></td>'+
      '<td><input type="url" data-path="featuredOffers.items.'+i+'.image" value="'+(it.image||'')+'"></td>';
    tb && tb.appendChild(tr);
  }
  lockOfferInputs();
}

function renderStrong(){
  const tb = qs('#tblStrong tbody'); if(tb) tb.innerHTML = '';
  const list = (STATE.mergedOffers && STATE.mergedOffers.strong && STATE.mergedOffers.strong.items) || [];
  for(let i=0;i<list.length;i++){
    const it = list[i] || {};
    const tr = document.createElement('tr');
    tr.innerHTML =
      '<td><input type="text" data-path="mergedOffers.strong.items.'+i+'.pairB_title" value="'+(it.pairB_title||'')+'"></td>'+
      '<td><input type="text" data-path="mergedOffers.strong.items.'+i+'.pairB_unit" value="'+(it.pairB_unit||'')+'"></td>'+
      '<td><input type="text" data-path="mergedOffers.strong.items.'+i+'.pairB_packaging" value="'+(it.pairB_packaging||'')+'"></td>'+
      '<td><input type="text" data-path="mergedOffers.strong.items.'+i+'.pairA_title" value="'+(it.pairA_title||'')+'"></td>'+
      '<td><input type="text" data-path="mergedOffers.strong.items.'+i+'.pairA_unit" value="'+(it.pairA_unit||'')+'"></td>'+
      '<td><input type="text" data-path="mergedOffers.strong.items.'+i+'.pairA_packaging" value="'+(it.pairA_packaging||'')+'"></td>'+
      '<td><input type="number" step="0.01" data-path="mergedOffers.strong.items.'+i+'.price" value="'+(it.price||'')+'"></td>'+
      '<td><input type="url" data-path="mergedOffers.strong.items.'+i+'.image" value="'+(it.image||'')+'"></td>';
    // زر اختيار B من الشركات
    const tdB = tr.querySelector('td:nth-child(1)');
    const btnB = document.createElement('button');
    btnB.textContent = 'اختر B من الشركات';
    btnB.className = 'op_pick_b';
    btnB.dataset.i = String(i);
    btnB.style.margin = '4px 0';
    tdB && (tdB.appendChild(document.createElement('br')), tdB.appendChild(btnB));

    tb && tb.appendChild(tr);
  }
  lockOfferInputs();
}

function renderFree(){
  const tb = qs('#tblFree tbody'); if(tb) tb.innerHTML = '';
  const list = (STATE.mergedOffers && STATE.mergedOffers.free && STATE.mergedOffers.free.items) || [];
  for(let i=0;i<list.length;i++){
    const it = list[i] || {};
    const tr = document.createElement('tr');
    tr.innerHTML =
      '<td><input type="text" data-path="mergedOffers.free.items.'+i+'.groupB_title" value="'+(it.groupB_title||'')+'"></td>'+
      '<td><input type="text" data-path="mergedOffers.free.items.'+i+'.groupB_unit" value="'+(it.groupB_unit||'')+'"></td>'+
      '<td><input type="text" data-path="mergedOffers.free.items.'+i+'.groupB_packaging" value="'+(it.groupB_packaging||'')+'"></td>'+
      '<td><input type="number" step="0.01" data-path="mergedOffers.free.items.'+i+'.groupB_priceHidden" value="'+(it.groupB_priceHidden||'')+'"></td>'+
      '<td><input type="text" data-path="mergedOffers.free.items.'+i+'.groupB_company" value="'+(it.groupB_company||'')+'"></td>'+
      '<td><input type="text" data-path="mergedOffers.free.items.'+i+'.groupA_title" value="'+(it.groupA_title||'')+'"></td>'+
      '<td><input type="text" data-path="mergedOffers.free.items.'+i+'.groupA_unit" value="'+(it.groupA_unit||'')+'"></td>'+
      '<td><input type="text" data-path="mergedOffers.free.items.'+i+'.groupA_packaging" value="'+(it.groupA_packaging||'')+'"></td>'+
      '<td><input type="number" step="0.01" data-path="mergedOffers.free.items.'+i+'.groupA_priceHidden" value="'+(it.groupA_priceHidden||'')+'"></td>'+
      '<td><input type="text" data-path="mergedOffers.free.items.'+i+'.groupA_company" value="'+(it.groupA_company||'')+'"></td>'+
      '<td><input type="url" data-path="mergedOffers.free.items.'+i+'.image" value="'+(it.image||'')+'"></td>';

    // زر اختيار B من الشركات
    const tdB = tr.querySelector('td:nth-child(1)');
    const btnB = document.createElement('button');
    btnB.textContent = 'اختر B من الشركات';
    btnB.className = 'op_pick_b_free';
    btnB.dataset.i = String(i);
    btnB.style.margin = '4px 0';
    tdB && (tdB.appendChild(document.createElement('br')), tdB.appendChild(btnB));
    tb && tb.appendChild(tr);
  }
  lockOfferInputs();
}
function renderCompanies(){
  const tb = qs('#tblCompanies tbody'); if(tb) tb.innerHTML = '';
  for(let i=0;i<(STATE.companies||[]).length;i++){
    const c = STATE.companies[i] || {};
    c.settings = c.settings || {};
    if(typeof c.settings.marginU1 !== 'number') c.settings.marginU1 = DEFAULT_MARGIN_U1;
    const tr = document.createElement('tr');
    tr.innerHTML =
  '<td><input type="text" data-path="companies.'+i+'.name" value="'+(c.name||'')+'"></td>'+
  '<td><input type="text" data-path="companies.'+i+'.id" value="'+(c.id||'')+'"></td>'+
  '<td><input type="text" data-path="companies.'+i+'.icon" value="'+(c.icon||'')+'"></td>'+
  '<td><input type="url"  data-path="companies.'+i+'.logo" value="'+(c.logo||'')+'"></td>'+
  '<td><input type="text" data-path="companies.'+i+'.description" value="'+(c.description||'')+'"></td>'+
  // [ADD] إظهار بالشركات
  '<td style="text-align:center"><input type="checkbox" data-path="companies.'+i+'.showInCompanies" '+((c.showInCompanies!==false)?'checked':'')+'></td>'+
  // [FIX] مفعّل
  '<td style="text-align:center"><input type="checkbox" data-path="companies.'+i+'.active" '+((c.active!==false)?'checked':'')+'></td>'+
  '<td><input type="number" data-path="companies.'+i+'.displayOrder" value="'+(c.displayOrder||'')+'"></td>'+
  '<td><input type="number" step="0.01" data-path="companies.'+i+'.settings.marginU1" value="'+( (c.settings&&c.settings.marginU1)!=null ? c.settings.marginU1 : 5 )+'"></td>';
    tb && tb.appendChild(tr);
  }
}

function renderCompanySelect(){
  const sel = qs('#companySelect'); if(!sel) return;
  sel.innerHTML = '';
  for(let i=0;i<(STATE.companies||[]).length;i++){
    const c = STATE.companies[i] || {};
    const op = document.createElement('option');
    op.value = i;
    op.textContent = c.name || c.id || ('شركة ' + (i+1));
    sel.appendChild(op);
  }
  if(curCompanyIndex >= (STATE.companies||[]).length) curCompanyIndex = 0;
  sel.value = String(curCompanyIndex);
}

function renderProducts(){
  const tb = qs('#tblProducts tbody'); if(tb) tb.innerHTML = '';
  const c = STATE.companies[curCompanyIndex];
  if(!c){
    tb && (tb.innerHTML = '<tr><td colspan="17"><em>لا توجد شركة مختارة</em></td></tr>');
    return;
  }
  c.products = c.products || [];
  for(let i=0;i<c.products.length;i++){
    const p = c.products[i] || {};
    const tr = document.createElement('tr');
    tr.setAttribute('data-row', String(i));
    tr.innerHTML =
      '<td><input type="text"   data-path="companies.'+curCompanyIndex+'.products.'+i+'.title" value="'+(p.title||'')+'"></td>'+
      '<td><input type="text"   data-path="companies.'+curCompanyIndex+'.products.'+i+'.note" value="'+(p.note||'')+'"></td>'+
      '<td><input type="text"   data-path="companies.'+curCompanyIndex+'.products.'+i+'.u1_unit" value="'+(p.u1_unit||p.unit||'')+'"></td>'+
      '<td><input type="number" step="0.01" data-path="companies.'+curCompanyIndex+'.products.'+i+'.price1" value="'+(p.price1!=null?p.price1:(p.prices&&p.prices.unit!=null?p.prices.unit:(p.u1_price!=null?p.u1_price:(p.price!=null?p.price:''))))+'"></td>'+
      '<td><input type="number" step="1"    data-path="companies.'+curCompanyIndex+'.products.'+i+'.minQtyU1" value="'+(p.minQtyU1||'')+'"></td>'+
      '<td><input type="text"   data-path="companies.'+curCompanyIndex+'.products.'+i+'.u2_unit" value="'+(p.u2_unit||'')+'"></td>'+
      '<td><input type="number" step="1"    data-path="companies.'+curCompanyIndex+'.products.'+i+'.u2_count" value="'+(p.u2_count||'')+'"></td>'+
      '<td><input type="number" step="0.01" data-path="companies.'+curCompanyIndex+'.products.'+i+'.price2" value="'+(p.price2!=null?p.price2:(p.prices&&p.prices.dozen!=null?p.prices.dozen:''))+'"></td>'+
      '<td><input type="text"   data-path="companies.'+curCompanyIndex+'.products.'+i+'.u3_unit" value="'+(p.u3_unit||'')+'"></td>'+
      '<td><input type="number" step="1"    data-path="companies.'+curCompanyIndex+'.products.'+i+'.u3_count" value="'+(p.u3_count||'')+'"></td>'+
      '<td><input type="number" step="0.01" data-path="companies.'+curCompanyIndex+'.products.'+i+'.price3" value="'+(p.price3!=null?p.price3:(p.prices&&p.prices.carton!=null?p.prices.carton:''))+'"></td>'+
      '<td><input type="text"   data-path="companies.'+curCompanyIndex+'.products.'+i+'.packaging" value="'+(p.packaging||p.u1_pack||'')+'"></td>'+
      '<td><input type="number" step="0.01" data-path="companies.'+curCompanyIndex+'.products.'+i+'.price" value="'+(p.price!=null?p.price:'')+'"></td>'+
      '<td><input type="text"   data-path="companies.'+curCompanyIndex+'.products.'+i+'.status" value="'+(p.status||'')+'"></td>'+
      '<td><input type="text"   data-path="companies.'+curCompanyIndex+'.products.'+i+'.code"   value="'+(p.code||'')+'"></td>'+
      '<td style="text-align:center"><input type="checkbox" data-path="companies.'+curCompanyIndex+'.products.'+i+'.visible" '+((p.visible!==false)?'checked':'')+'"></td>'+
      '<td><input type="url"    data-path="companies.'+curCompanyIndex+'.products.'+i+'.image"  value="'+(p.image||'')+'"></td>';
    tb && tb.appendChild(tr);
  }
}

/* ========= Compose helpers ========= */
function composeMergeTitles(){
  try{
    const sList = (STATE.mergedOffers && STATE.mergedOffers.strong && STATE.mergedOffers.strong.items) || [];
    for(let i=0;i<sList.length;i++){
      const it = sList[i] || {};
      const a = (it.pairA_title || '').trim();
      const b = (it.pairB_title || '').trim();
      if(a || b) it.title = (a && b) ? (a + ' + ' + b) : (a || b);
    }
    const fList = (STATE.mergedOffers && STATE.mergedOffers.free && STATE.mergedOffers.free.items) || [];
    for(let j=0;j<fList.length;j++){
      const it2 = fList[j] || {};
      const a2 = (it2.groupA_title || '').trim();
      const b2 = (it2.groupB_title || '').trim();
      if(a2 || b2) it2.title = (a2 && b2) ? (a2 + ' + ' + b2) : (a2 || b2);
    }
  } catch(_){}
}

/* ========= Migration / Normalization ========= */
function looksLikeSiteProduct(p){ return !!(p && (Array.isArray(p.variants) || p.price!=null || (p.prices && (p.prices.unit!=null || p.prices.dozen!=null || p.prices.carton!=null)))); }

function siteCompaniesToAdminCompanies(siteCompanies){
  const out = [];
  (siteCompanies||[]).forEach((c, ci)=>{
    const cc = {
      id: c.id || ('c'+ci+Date.now()),
      name: c.name || '',
      icon: c.icon || '',
      logo: c.logo || '',
      description: c.description || '',
      active: (c.active !== false),
      displayOrder: +c.displayOrder || (ci+1),
      settings: { showUnits: DEFAULT_SHOW_UNITS, marginU1: DEFAULT_MARGIN_U1 },
      products: []
    };
    (c.products||[]).forEach((p, pi)=>{
      if (looksLikeAdminProduct(p)) { cc.products.push(p); return; }
      const unitPrice   = (p.prices && p.prices.unit   != null) ? Number(p.prices.unit)   : (p.price1 ?? (Array.isArray(p.variants)?(p.variants[0]?.price):undefined) ?? p.price ?? '');
      const dozenPrice  = (p.prices && p.prices.dozen  != null) ? Number(p.prices.dozen)  : (p.price2 ?? (Array.isArray(p.variants)?(p.variants[1]?.price):undefined) ?? '');
      const cartonPrice = (p.prices && p.prices.carton != null) ? Number(p.prices.carton) : (p.price3 ?? (Array.isArray(p.variants)?(p.variants[2]?.price):undefined) ?? '');

      function parseIntSafe(v){ const n = parseInt(v,10); return Number.isFinite(n)?n:''; }

      const base = {
        id: p.id || p.code || ('p'+Date.now()+'_'+pi),
        title: p.title || '',
        note:  p.notes || p.note || '',
        status:'', code:'', visible:(p.visible!==false), image:p.image||'',
        u1_unit: p.u1_unit || p.unit1Name || p.unit || '',
        price1:  (unitPrice!=='' ? unitPrice : ''),
        u2_unit: p.u2_unit || p.unit2Name || '',
        u2_count: parseIntSafe(p.u2_count || p.u2_pack || p.unit2Count),
        price2:  (dozenPrice!=='' ? dozenPrice : ''),
        u3_unit: p.u3_unit || p.unit3Name || '',
        u3_count: parseIntSafe(p.u3_count || p.u3_pack || p.unit3Count),
        price3:  (cartonPrice!=='' ? cartonPrice : ''),
        packaging: p.packaging || p.u1_pack || '',
        price:     (p.price!=null?p.price:''),
        minQtyU1:  p.minQtyU1 || ''
      };
      cc.products.push(base);
    });
    out.push(cc);
  });
  return out;
}
function looksLikeAdminProduct(p){
  return (p && ('u1_unit' in p || 'price1' in p || 'u2_unit' in p || 'u3_unit' in p));
}

function toSiteProduct(p, company){
  const cSettings = (company && company.settings) ? company.settings : {marginU1:DEFAULT_MARGIN_U1, showUnits:DEFAULT_SHOW_UNITS};

  const title = p.title || '';
  const desc  = p.note  || '';
  const code  = p.code  || '';
  const image = p.image || '';
  const visible = (p.visible!==false);
  const status  = p.status || '';

  const u1 = str(p.u1_unit||p.unit||'').trim();
  const u2 = str(p.u2_unit||'').trim();
  const u3 = str(p.u3_unit||'').trim();

  const price1_raw = (p.price1!=null) ? num(p.price1, NaN) : (p.u1_price!=null ? num(p.u1_price, NaN) : NaN);
  const hasExplicitU1 = Number.isFinite(price1_raw) && price1_raw > 0;
  const price1 = hasExplicitU1 ? round2(price1_raw) : null;

  const price2 = (p.price2!=null) ? num(p.price2,0) : null;
  const price3 = (p.price3!=null) ? num(p.price3,0) : null;

  const pack2 = num(p.u2_count,0);
  const pack3 = num(p.u3_count,0);

  const minU1 = num(p.minQtyU1||0,0);

  const v = [];
  function mkLabel(unitName, qty){
    const right = (qty && qty>1) ? (`${qty} × ${u1||'حبة'}`) : (u1?u1:'');
    return (unitName && right) ? `${unitName} — ${right}` : (unitName || right || 'خيار');
  }

  if(hasExplicitU1){
    v.push({ label: mkLabel(u1||'حبة', 1), price: round2(price1) });
  }
  if(Number.isFinite(price2) && price2>0 && (u2 || pack2>0)){
    v.push({ label: mkLabel(u2, pack2||0), price: round2(price2) });
  }
  if(Number.isFinite(price3) && price3>0 && (u3 || pack3>0)){
    v.push({ label: mkLabel(u3, pack3||0), price: round2(price3) });
  }

  const out = {
    id: p.id, title, description: desc, packaging: p.packaging||'', image, code, status, visible
  };
  if(minU1>0) out.u1Min = minU1;

  const vCount = v.length;
  if (vCount >= 2 || (!hasExplicitU1 && vCount === 1)) {
    out.hasVariants = true;
    out.variants = v;
    out.price = null;
  } else if (vCount === 1) {
    out.hasVariants = false;
    out.price = v[0].price;
  } else {
    out.hasVariants = false;
    out.price = (p.price!=null?num(p.price,0):null);
  }
  return out;
}

function normalizeCompaniesForSite(companies){
  return (companies||[]).map((c, idx)=>{
    const cc = {
      id: c.id||('c'+(idx+1)),
      name: c.name||('شركة '+(idx+1)),
      description: c.description||'',
      active: (c.active!==false),
      displayOrder: num(c.displayOrder, idx+1),
      icon: c.icon||'',
      logo: c.logo||'',
      products: (c.products||[]).map(p => toSiteProduct(p, c))
    };
    return cc;
  });
}
/* ========= قفل تحرير حقول العروض ========= */
function lockOfferInputs(){
  // اقفل كل شيء أولاً كالسابق
  qsa('#tblFeatured input, #tblStrong input, #tblFree input').forEach(el=>{
    el.readOnly = true;
    el.style.background = '#f9fafb';
    el.title = 'الإضافة والتعديل من عروض الشركات فقط';
  });

  // ثم افتح ما نريده فقط في "العرض المميز":
  // 1) السماح بتعديل سعر العرض المخصوم
  qsa('#tblFeatured input[data-path$=".discountedPrice"]').forEach(el=>{
    el.readOnly = false;
    el.style.background = '';
    el.title = '';
  });

  // 2) السماح بتعديل "التعبئة" يدويًا إن لم تُسحب آليًا
  qsa('#tblFeatured input[data-path$=".packaging"]').forEach(el=>{
    el.readOnly = false;
    el.style.background = '';
    el.title = '';
  });

  // إن أردت السماح بتعديل "الوحدة" أيضًا، أزل التعليق التالي:
  // qsa('#tblFeatured input[data-path$=".unit"]').forEach(el=>{
  //   el.readOnly = false;
  //   el.style.background = '';
  //   el.title = '';
  // });
}

/* ========= مُلتقط الشركات ========= */
let OP_BIND = null; // {mode:'featured'|'strongA'|'strongB'|'freeA'|'freeB', row?:number}
function op_el(id){ return document.getElementById(id); }

function openOfferPicker(mode='featured', rowIdx=null){
  OP_BIND = { mode, row: rowIdx };
  const sel = op_el('op_company'); if(!sel) return;
  sel.innerHTML = '';
  (STATE.companies||[]).forEach((c,idx)=>{
    const o=document.createElement('option');
    o.value=idx; o.textContent=c.name||c.id||('شركة '+(idx+1));
    sel.appendChild(o);
  });
  op_el('op_type').value = (mode==='featured'||mode==='strongA'||mode==='freeA') ? mode : 'featured';
  op_el('op_search').value='';
  renderOfferPickerBody();
  op_el('offerPicker').style.display='flex';
}
function closeOfferPicker(){ const m=op_el('offerPicker'); if(m) m.style.display='none'; }

function renderOfferPickerBody(){
  const body = op_el('op_body'); if(!body) return;
  body.innerHTML='';
  const cidx = +op_el('op_company').value || 0;
  const q = (op_el('op_search').value||'').trim().toLowerCase();
  const comp = (STATE.companies||[])[cidx];
  if(!comp){ body.innerHTML = '<tr><td colspan="3" style="padding:10px">لا توجد شركة.</td></tr>'; return; }

  let list = (comp.products||[]);
  if(q){
    list = list.filter(p =>
      String(p.title||'').toLowerCase().includes(q) ||
      String(p.note||'').toLowerCase().includes(q) ||
      String(p.packaging||'').toLowerCase().includes(q)
    );
  }
  if(!list.length){
    body.innerHTML = '<tr><td colspan="3" style="padding:10px">لا توجد نتائج.</td></tr>'; return;
  }
  list.forEach((p, i)=>{
    const tr = document.createElement('tr');
    tr.innerHTML =
      '<td style="border-bottom:1px solid #e6e8ef;padding:8px">'+(p.title||'')+'</td>'+
      '<td style="border-bottom:1px solid #e6e8ef;padding:8px">'+(p.packaging||'')+'</td>'+
      '<td style="border-bottom:1px solid #e6e8ef;padding:8px;text-align:center">'+
        '<button class="op_add" data-i="'+i+'">إضافة</button>'+
      '</td>';
    body.appendChild(tr);
  });
}

/* ========= إضافة من الشركات مع وسم الأصل ========= */
function getAdminProductBasePrice(p){
  if(p.price!=null && p.price!=='') return Number(p.price)||0;
  if(p.price1!=null && p.price1!=='') return Number(p.price1)||0;
  if(p.price2!=null && p.price2!=='') return Number(p.price2)||0;
  if(p.price3!=null && p.price3!=='') return Number(p.price3)||0;
  return 0;
}

/* ========= Add product to offers (from companies) ========= */
function addProductToOffer(company, product, type){
  // يجمع كل احتمالات (وحدة/تعبئة/سعر) من صيغ مختلفة داخل المنتج
  function collectUnitOptions(p){
    const opts = [];
    // 1) u1/u2/u3
    [['u1','u1_unit','u1_pack','u1_price'],
     ['u2','u2_unit','u2_pack','u2_price'],
     ['u3','u3_unit','u3_pack','u3_price']].forEach(([key,unitK,packK,priceK])=>{
      const unit  = (p && p[unitK]) ? String(p[unitK]).trim() : '';
      const pack  = (p && p[packK]) ? String(p[packK]).trim() : '';
      const price = p && p[priceK] != null ? parseFloat(p[priceK]) : NaN;
      const label = Number.isNaN(price) || price <= 0 ? 'غير مسعّر' : price;
      opts.push({ key, label: `${unit||'وحدة'} — تعبئة: ${pack||'-'} — سعر: ${label}`, unit, packaging: pack, price });
    });
    // 2) variants
    if (Array.isArray(p?.[ 'variants' ]) && p.variants.length){
      p.variants.forEach((v,i)=>{
        const price = v && v.price != null ? parseFloat(v.price) : NaN;
        const unit  = String(v.unit || p.unit || '').trim();
        const pack  = String(v.label || p.packaging || '').trim();
        const label = Number.isNaN(price) || price <= 0 ? 'غير مسعّر' : price;
        opts.push({ key:`var${i}`, label:`${unit||'وحدة'} — ${pack||'-'} — سعر: ${label}`, unit:unit, packaging:pack, price });
      });
    }
    // 3) prices.{unit,dozen,carton}
    if (p?.prices){
      [['unit','حبة'],['dozen','الدزينة'],['carton','كرتونة']].forEach(([k,uLabel])=>{
        const price = p.prices[k] != null ? parseFloat(p.prices[k]) : NaN;
        const unit  = String(p.unit || uLabel).trim();
        const pack  = String(p.packaging || p.description || uLabel).trim();
        const label = (Number.isNaN(price) || price <= 0) ? 'غير مسعّر' : price;
        opts.push({ key:k, label:`${uLabel} — تعبئة: ${pack||'-'} — سعر: ${label}`, unit, packaging:pack, price });
      });
    }
    // 4) fallback قديم
    if (!opts.length){
      const price = p && p.price != null ? parseFloat(p.price) : NaN;
      const unit  = String(p.unit||'').trim();
      const pack  = String(p.packaging||p.description||'').trim();
      const label = Number.isNaN(price) || price <= 0 ? 'غير مسعّر' : price;
      opts.push({ key:'legacy', label:`${unit||'وحدة'} — تعبئة: ${pack||'-'} — سعر: ${label}`, unit, packaging:pack, price });
    }
    return opts;
  }

  // مربع اختيار بسيط
  function chooseUnitFor(p){
    const opts = collectUnitOptions(p);
    if (!opts.length) return null;
    let msg = 'اختر رقم الوحدة المراد استخدامها:\n';
    opts.forEach((o,idx)=>{ msg += `${idx+1}) ${o.title?o.title+' — ':''}${o.label}\n`; });
    const ans = window.prompt(msg,'1');
    const i = Math.max(1, Math.min(opts.length, parseInt(String(ans),10) || 1)) - 1;
    return opts[i];
  }

  // اجلب الاختيار أو وفّر بديلًا افتراضيًا
  let sel = chooseUnitFor(product);
  if (!sel){
    sel = {
      key: 'legacy',
      unit:      String(product.u1_unit || product.unit || '').trim(),
      packaging: String(product.u1_pack || product.packaging || product.description || '').trim(),
      price:     Number.isFinite(product.u1_price) ? Number(product.u1_price) : (Number(product.price) || 0)
    };
  }
  if (!(Number.isFinite(sel.price) && sel.price > 0)){
    const typed = window.prompt('السعر غير مسجّل لهذه الوحدة. أدخل السعر:','');
    sel.price = parseFloat(String(typed||'').replace(',', '.')) || 0;
  }

  const baseUnit  = sel.unit || '';
  const basePack  = sel.packaging || '';
  const basePrice = Number(sel.price) || 0;

  if (type === 'long' /* keep API stable */) type = 'featured';

  if (type === 'featured'){
    if(!STATE.featuredOffers) STATE.featuredOffers = {active:true, title:'عروض الأيام الثلاث', items:[]};
    STATE.featuredOffers.items.push({
      refCompanyId:    company.id || company.pk || '',
      refProductId:    product.id || product.code || '',
      id:              Date.now(),
      title:           product.title || product.name || '',
      unit:            baseUnit,
      packaging:       basePack,
      originalPrice:   basePrice,
      discountedPrice: basePrice,
      image:           product.image || ''
    });
    renderFeatured();
  } else if (type === 'strong' || type === 'strongMix' || type === 'free' || type === 'freeMix'){
    const targetStrong = (STATE.mergedOffers && STATE.mergedOffers.strong)
       ? STATE.mergedOffers.strong
       : (STATE.mergedOffers.strong = {active:true, title:'التيار القوي', items:[]});

    const a = {
      pairA_refCompanyId:  company.id || company.pk || '',
      pairA_refProductId:  product.id || product.code || '',
      id:                  Date.now(),
      pairA_title:         (product.title || product.name || ''),
      pairA_unit:          baseUnit,
      pairA_packaging:     basePack,
      price:               basePrice,
      image:               product.image || '',
      // B يُملأ لاحقًا من الواجهة إن لزم
      pairB_title:'', pairB_unit:'', pairB_packaging:''
    };

    if (type === 'strong' || type === 'strongMix'){
      targetStrong.items.push(a);
      renderStrong();
    } else {
      const targetFree = (STATE.mergedOffers && STATE.mergedOffers.free)
        ? STATE.mergedOffers.free
        : (STATE.mergedOffers.free = {active:true, title:'الدمج الحر', items:[]});
      targetFree.items.push({
        id:                   Date.now(),
        groupA_refCompanyId:  company.id || company.pk || '',
        groupA_refProductId:  product.id || product.code || '',
        groupA_title:         product.title || product.name || '',
        groupA_unit:          basePack ? baseUnit : (product.u1_unit || product.unit || ''),
        groupA_packaging:     basePack || (product.u1_pack || product.packaging || ''),
        groupA_priceHidden:   '',
        groupA_company:       '',
        groupB_title:         '',
        groupB_unit:          '',
        groupB_packaging:     '',
        groupB_priceHidden:   '',
        groupB_company:       '',
        image:                product.image || ''
      });
      renderFree();
    }
  }
} // ←← إغلاق دالة addProductToOffer بالكامل

/* ========= تحقق صارم وقت الحفظ ========= */
function findProd(companyId, productId){
  const c = (STATE.companies||[]).find(x=> x.id === companyId);
  if (!c) return null;
  const p = (c.products||[]).find(x => (x.id===productId || x.code===productId));
  return p ? { c, p } : null;
}

function assertOffersHaveRefs(){
  // featured
  const F = (STATE.featuredOffers && STATE.featuredOffers.items) || [];
  for(let i=0;i<F.length;i++){
    const it = F[i] || {};
    if(!it.refCompanyId || !it.refProductId){
      throw new Error('المميّز صف #' + (i+1) + ': اختر المنتج من قائمة الشركات.');
    }
    const fp = findProd(it.refCompanyId, it.refProductId);
    if(!fp) throw new Error('المميّز صف #' + (i+1) + ': المرجع غير موجود.');
    // املأ الناقص
    it.title       = it.title       || fp.p.title || '';
    if(!it.packaging)   it.packaging   = fp.p.packaging || fp.p.u1_pack || '';
    if(!it.unit)        it.seq = null, it.unit = fp.p.u1_unit || fp.p.unit || '';
    if(it.originalPrice == null || it.originalPrice === '' || Number(it.originalPrice) === 0){
      it.original = null; it.originalPrice = Number(fp.p.u1_price || fp.p.price || 0);
    }
    if(it.discountedPrice == null || it.discountedPrice === '') it.discountedPrice = it.originalPrice;
    if(!it.image) it.image = fp.p.image || '';
  }

  // strong (A/B)
  const S = (STATE.mergedOffers && STATE.mergedOffers.strong && STATE.mergedOffers.strong.items) || [];
  for(let j=0;j<S.length;j++){
    const it = S[j] || {};
    if(!it.pairA_refCompanyId || !it.pairA_refProductId){
      throw new Error('الدمج القوي #' + (j+1) + ': الطرف A بلا مرجع.');
    }
    const A = findProd(it.pairA_refCompanyId, it.pairA_refProductId);
    if(!A) throw new Error('الدمج القوي #' + (j+1) + ': مرجع A غير موجود.');
    it.pairA_title      = it.pairA_title      || A.p.title || '';
    if(!it.pairA_packaging) it.pairA_packaging = A.p.packaging || A.p.u1_pack || '';
    if(!it.pairA_unit)      it.pairA_unit      = A.p.u1_unit   || A.p.unit    || '';

    if (it.hasOwnProperty('pairB_refCompanyId') && it.pairB_refCompanyId && it.pairB_refProductId){
      const B = findProd(it.pairB_refCompanyId, it.pairB_refProductId);
      if(!B) throw new Error('الدمج القوي #' + (j+1) + ': مرجع B غير موجود.');
      it.pairB_title      = it.pairB_title      || B.p.title || '';
      if(!it.pairB_packaging) it[p='pairB_packaging']=B.p.packaging||B.p.u1_pack||'';
      if(!it.pairB_unit)      it.pairB_unit      = B.p.u1_unit   || B.p.unit    || '';
    }
  }

  // free (A/B)
  const R = (STATE.mergedOffers && STATE.mergedOffers.free && STATE.mergedOffers.free.items) || [];
  for(let k=0;k<R.length;k++){
    const it = R[k] || {};
    if(!it.groupA_refCompanyId || !it.groupA_refProductId){
      throw new Error('الدمج الحر #' + (k+1) + ': الطرف A بلا مرجع.');
    }
    const A = findProd(it.groupA_refCompanyId, it.groupA_refProductId);
    if(!A) throw new Error('الدمج الحر #' + (k+1) + ': مرجع A غير موجود.');
    it.groupA_title      = it.groupA_title      || A.p.title || '';
    if(!it.groupA_packaging) it.groupA_packaging = A.p.packaging || A.p.u1_pack || '';
    if(!it.groupA_unit)      it.groupA_unit      = A.p.u1_unit   || A.p.unit    || '';
    if(!it.groupA_company)   it.groupA_company   = A.c.name || A.c.id || it.groupA_company;

    if (it.groupB_refCompanyId && it.groupB_refProductId){
      const B = findProd(it.groupB_refCompanyId, it.groupB_refProductId);
      if(!B) throw new Error('الدمج الحر #' + (k+1) + ': مرجع B غير موجود.');
      it.groupB_title      = it.groupB_title      || B.p.title || '';
      if(!it.groupB_packaging) it.groupB_packaging = B.p.packaging || B.p.u1_pack || '';
      if(!it.groupB_unit)      it.groupB_unit      = B.p.u1_unit   || B.p.unit    || '';
      if(!it.groupB_company)   it.groupB_company   = B.c.name || B.c.id || it.groupB_company;
    }
  }
}

/* ========= Save ========= */
async function saveAll(){
  // metadata
  const sn = qs('#siteName'), wa = qs('#wa');
  STATE.metadata = STATE.metadata || {};
  if (sn) STATE.metadata.siteName = str(sn.value).trim();
  if (wa) STATE.metadata.whatsappNumber = str(wa.value).trim();
  STATE.metadata.lastUpdate = new Date().toISOString();

  // عناوين الدمج
  composeMergeTitles();
  // إجبار active على Boolean صريح (افتراضيًا true إلا إذا كانت قيمته سالب)
 (STATE.companies||[]).forEach(c => { c.active = truthyBool(c.active); });
(STATE.companies||[]).forEach(c => { c.showInCompanies = truthyBool(c.showInCompanies); }); // [ADD]

  // تحقق صارم: جميع عناصر غير "الشركات" يجب أن تكون ذات مرجع أصل
  assertOffersHaveRefs();

  // تطبيع الدمج لإرسال نسخة مبسطة يقرأها الموقع (mergedOffers.items)
  const strongList = (STATE.mergedOffers && STATE.mergedOffers.strong && Array.isArray(STATE.mergedOffers.strong.items))
    ? STATE.mergedOffers.strong.items : [];
  const siteMergedItems = [];
  for (let i = 0; i < strongList.length; i++){
    const it = strongList[i] || {};
    const left  = [it.pairA_title || '', it.pairA_unit || '', it.pairA_packaging || '']
                  .filter(Boolean).map(s=>String(s).trim()).join(' × ');
    const right = [it.pairB_title || '', it.pairB_unit || '', it.pairB_packaging || '']
                  .filter(Boolean).map(s=>String(s).trim()).join(' × ');
    const title = (left && right) ? (left + ' + ' + right) : ((it.title||'').trim());
    const price = Number(it.price || 0) || 0;
    if (title) siteMergedItems.push({ id: it.id || ('m_'+(i+1)), title, price });
  }

  // payload
  const payload = {
    companies: normalizeCompaniesForSite(STATE.companies),
    adminCompanies: STATE.companies,
    metadata: STATE.metadata,
    mergedOffers: {
      ...(STATE.mergedOffers || {}),
      items: siteMergedItems,
      active: (STATE.mergedOffers && STATE.mergedOffers.active === true) || siteMergedItems.length > 0,
      title:  (STATE.mergedOffers && STATE.mergedOffers.title) || '💥 عروض الدمج المميزة 🔥',
      expiryLogic: (STATE.mergedOffers && STATE.mergedOffers.expiryLogic) || 'saturday_tuesday'
    },
    featuredOffers: STATE.featuredOffers || {active:false, items:[]},
    schema: 'zamzam_admin_v2',
    schemaVersion: 2
  };

  // auth
  const key = await getAuthKey(true);
  if (!key){ setMsg('أدخل كلمة السر للحفظ.', false); return; }
  payload.password = key;
  payload.token    = key;

  try{
    setMsg('جارٍ الحفظ…');
    const res  = await fetch('save-data.php?ts=' + Date.now(), {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const data = await res.json().catch(()=> ({}));

    if (!res.ok || !(data && (data.ok || data.success))){
      if ((data && data.error === 'BAD_PASSWORD') || res.status === 401){
        clearAuthKey();
        setMsg('كلمة السر غير صحيحة. أعد المحاولة.', false);
        return;
      }
      throw new Error(data.error || data.message || ('HTTP '+res.status));
    }

    saveBackup(STATE);
    setMsg('تم الحفظ ✓');
    await reloadData();
    setMsg('تم الحفظ ✓');

  }catch(err){
    setMsg('خطأ في الحفظ: ' + err.message, false);
  }
}

/* ========= Context-menu ========= */
(function setupContextMenu(){
  const menu = document.getElementById('ctxMenu');
  if(!menu) return;
  function hide(){ menu.style.display = 'none'; menu.dataset.rowIndex = ''; }
  document.addEventListener('contextmenu', function(e){
    const targetCell = e.target.closest('#tblProducts tbody td, #tblProducts tbody tr');
    if(!targetCell){ hide(); return; }
    const tr = targetCell.closest('tr'); if(!tr){ hide(); return; }
    const row = +tr.getAttribute('data-row') || 0;
    const vw = window.innerWidth, vh = window.innerHeight;
    const mw = menu.offsetWidth||180, mh = menu.offsetHeight||120;
    const x = Math.min(e.clientX, vw - mw - 8);
    const y = Math.min(e.clientY, vh - mh - 8);
    menu.style.left = x + 'px'; menu.style.top  = y + 'px';
    menu.style.display = 'block';
    menu.dataset.rowIndex = String(row);
    e.preventDefault();
  });
  document.addEventListener('click', function(e){
    const item = e.target.closest('#ctxMenu .item');
    if(item){
      const act = item.getAttribute('data-act');
      const i = +menu.dataset.rowIndex || 0;
      const cc = STATE.companies[curCompanyIndex]; if(!cc){ hide(); return; }
      cc.products = cc.products || [];
      if(act === 'before' || act === 'after'){
        const pos = (act === 'before') ? i : (i+1);
        cc.products.splice(pos, 0, { id:Date.now(), title:'', note:'', u1_unit:'', price1:'', minQtyU1:'', u2_unit:'', u2_count:'', price2:'', u3_unit:'', u3_count:'', price3:'', packaging:'', price:'', status:'', code:'', visible:true, image:'' });
        renderProducts();
      }else if(act === 'delete'){
        cc.products.splice(i, 1);
        renderProducts();
      }
      hide();
    }else{
      hide();
    }
  });
})();

/* ========= Keyboard navigation ========= */
document.addEventListener('keydown', function (e) {
  const t = e.target;
  if (!(t instanceof HTMLInputElement || t instanceof HTMLSelectElement)) return;
  if (e.ctrlKey || e.altKey || e.metaKey) return;
  const key = e.key;
  if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Enter'].includes(key)) return;

  const td = t.closest('td'); const tr = t.closest('tr'); if (!td || !tr) return;
  const table = tr.closest('table'); const inputs = Array.from(table.querySelectorAll('input,select'));
  const index = inputs.indexOf(t);
  let target = null; const cellIndex = td.cellIndex;

  switch (key) {
    case 'ArrowLeft':  target = inputs[index - 1]; break;
    case 'ArrowRight':
    case 'Enter':      target = inputs[index + 1]; break;
    case 'ArrowUp': {
      const prevRow = tr.previousElementSibling;
      if (prevRow) {
        const upCell = prevRow.querySelectorAll('td')[cellIndex];
        if (upCell) target = upCell.querySelector('input,select');
      } break;
    }
    case 'ArrowDown': {
      const nextRow = tr.nextElementSibling;
      if (nextRow) {
        const downCell = nextRow.querySelectorAll('td')[cellIndex];
        if (downCell) target = downCell.querySelector('input,select');
      } break;
    }
  }

  if (target) {
    e.preventDefault();
    target.focus();
    if (target.select) target.select();
  }
});
/* ========= Init ========= */
window.addEventListener('DOMContentLoaded', ()=>{ reloadData().catch(()=>{}); });