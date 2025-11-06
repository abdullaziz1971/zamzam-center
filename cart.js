/**
 * نظام إدارة السلة (Cart Management System)
 * يعتمد localStorage ويحافظ على السلة بين الصفحات، ويُزامن الأسعار مع ZAMZAM_DATA.
 */
const ZamzamCart = {
  STORAGE_KEY: 'zamzam_cart',
  VERSION: '2.0',

  /* ===== تحميل/حفظ ===== */
  load() {
    try {
      const data = localStorage.getItem(this.STORAGE_KEY);
      if (data) {
        const cart = JSON.parse(data);
        if (cart.version === this.VERSION) return cart;
      }
    } catch (e) { console.error('خطأ تحميل السلة:', e); }
    return this.getEmptyCart();
  },

  save(cart) {
    try {
      cart.version = this.VERSION;
      cart.timestamp = Date.now();
      localStorage.setItem(this.STORAGE_KEY, JSON.stringify(cart));
    } catch (e) {
      console.error('خطأ حفظ السلة:', e);
      alert('تعذر حفظ الطلب. يرجى التحقق من إعدادات المتصفح.');
    }
  },

  /* ===== عمليات على العناصر ===== */
  updateItem(cart, item) {
    const idx = cart.items.findIndex(i =>
      i.id === item.id && i.type === item.type && i.variantIndex === item.variantIndex &&
      (i.companyId || null) === (item.companyId || null)
    );
    if (idx >= 0) {
      if (item.quantity > 0) {
        cart.items[idx] = { ...cart.items[idx], ...item, total: item.quantity * item.price };
      } else {
        cart.items.splice(idx, 1);
      }
    } else if (item.quantity > 0) {
      item.total = item.quantity * item.price;
      cart.items.push(item);
    }
    this.recalculate(cart);
    this.save(cart);
    return cart;
  },

  removeItem(cart, id, type, variantIndex = null, companyId = null) {
    const idx = cart.items.findIndex(i =>
      i.id === id && i.type === type && i.variantIndex === variantIndex &&
      (i.companyId || null) === (companyId || null)
    );
    if (idx >= 0) {
      cart.items.splice(idx, 1);
      this.recalculate(cart);
      this.save(cart);
    }
    return cart;
  },

  getItem(cart, id, type, variantIndex = null, companyId = null) {
    return cart.items.find(i =>
      i.id === id && i.type === type && i.variantIndex === variantIndex &&
      (i.companyId || null) === (companyId || null)
    ) || null;
  },

  /* ===== مجاميع ===== */
  recalculate(cart) {
    cart.totals = { merged: 0, featured: 0, companies: {}, grand: 0 };
    cart.items.forEach(it => {
      it.total = it.quantity * it.price;
      if (it.type === 'merged') cart.totals.merged += it.total;
      else if (it.type === 'featured') cart.totals.featured += it.total;
      else if (it.type === 'company') {
        if (!cart.totals.companies[it.companyId]) cart.totals.companies[it.companyId] = 0;
        cart.totals.companies[it.companyId] += it.total;
      }
    });
    cart.totals.grand = cart.totals.merged + cart.totals.featured +
      Object.values(cart.totals.companies).reduce((a,b)=>a+b,0);
  },

  /* ===== مزامنة الأسعار مع data.js ===== */
  syncPrices(cart, data) {
    let hasChanges = false;
    const changed = [];

    const findCompanyProduct = (cid, pid) => {
      const c = (data.companies||[]).find(x=>x.id===cid);
      if(!c) return {exists:false};
      const p = (c.products||[]).find(x=>x.id===pid);
      if(!p) return {exists:false};
      return {exists:true, company:c, product:p};
    };

    cart.items.forEach(item => {
      let currentPrice = null;
      let exists = true;

      if (item.type === 'merged') {
        const p = (data.mergedOffers && data.mergedOffers.items||[]).find(x=>x.id===item.id);
        if (p) currentPrice = p.price; else exists = false;
      } else if (item.type === 'featured') {
        const p = (data.featuredOffers && data.featuredOffers.items||[]).find(x=>x.id===item.id);
        if (p) currentPrice = p.discountedPrice; else exists = false;
      } else if (item.type === 'company') {
        const r = findCompanyProduct(item.companyId, item.id);
        if (!r.exists) { exists = false; }
        else {
          const pr = r.product;
          if (pr.hasVariants && item.variantIndex != null) {
            const v = (pr.variants||[])[item.variantIndex];
            if (v) currentPrice = v.price; else exists = false;
          } else if (!pr.hasVariants) {
            currentPrice = pr.price;
          } else {
            exists = false;
          }
        }
      }

      if (!exists) {
        changed.push({ title:item.title, change:'removed', oldPrice:item.price, newPrice:null });
        hasChanges = true;
      } else if (currentPrice!=null && currentPrice !== item.price) {
        changed.push({ title:item.title, change:'price', oldPrice:item.price, newPrice:currentPrice });
        item.price = currentPrice;
        item.total = item.quantity * currentPrice;
        hasChanges = true;
      }
    });

    cart.items = cart.items.filter(it => !changed.find(c => c.title===it.title && c.change==='removed'));
    if (hasChanges) { this.recalculate(cart); this.save(cart); }
    return { cart, hasChanges, changes: changed };
  },

  clear() {
    try { localStorage.removeItem(this.STORAGE_KEY); } catch(e) {}
  },

  getEmptyCart() {
    return { version:this.VERSION, timestamp:Date.now(), items:[], totals:{merged:0,featured:0,companies:{},grand:0} };
  },

  formatPrice(num) {
    const n = Number(num||0); return (Math.round((n + Number.EPSILON)*100)/100).toFixed(2);
  },

  /* ===== رسالة واتساب ===== */
  buildWhatsAppMessage(cart, phone) {
    const lines = ['طلب شراء من زمزم جملة:', ''];

    const mergedItems   = cart.items.filter(i=>i.type==='merged');
    const featuredItems = cart.items.filter(i=>i.type==='featured');
    const companyItems  = cart.items.filter(i=>i.type==='company');

    if (mergedItems.length) {
      lines.push('💥 العروض المدمجة:');
      mergedItems.forEach(it=>{
        lines.push(`- ${it.title}`);
        lines.push(`  ${this.formatPrice(it.price)} × ${it.quantity} = ${this.formatPrice(it.total)} د.أ`);
      });
      lines.push('');
    }

    if (featuredItems.length) {
      lines.push('✨ العروض المميزة:');
      featuredItems.forEach(it=>{
        lines.push(`- ${it.title}`);
        lines.push(`  ${this.formatPrice(it.price)} × ${it.quantity} = ${this.formatPrice(it.total)} د.أ`);
      });
      lines.push('');
    }

    if (companyItems.length) {
      const byC = {};
      companyItems.forEach(it=>{
        (byC[it.companyId] = byC[it.companyId] || {name:it.companyName, items:[]}).items.push(it);
      });
      Object.keys(byC).forEach(cid=>{
        const c = byC[cid];
        lines.push(`🏢 ${c.name}:`);
        c.items.forEach(it=>{
          const v = it.variantLabel ? ` (${it.variantLabel})` : '';
          const unitTxt = it.unit1Name ? ` ${it.unit1Name}` : '';
          lines.push(`- ${it.title}${v}`);
          lines.push(`  ${this.formatPrice(it.price)} × ${it.quantity}${unitTxt} = ${this.formatPrice(it.total)} د.أ`);
        });
        lines.push('');
      });
    }

    lines.push('━━━━━━━━━━━━━━━━━━━━');
    if (cart.totals.merged>0)   lines.push(`💥 إجمالي الدمج: ${this.formatPrice(cart.totals.merged)} د.أ`);
    if (cart.totals.featured>0) lines.push(`✨ إجمالي المميز: ${this.formatPrice(cart.totals.featured)} د.أ`);
    Object.keys(cart.totals.companies).forEach(cid=>{
      lines.push(`🏢 إجمالي ${cid}: ${this.formatPrice(cart.totals.companies[cid])} د.أ`);
    });
    lines.push(`📦 الإجمالي الكلي: ${this.formatPrice(cart.totals.grand)} د.أ`);

    const text = encodeURIComponent(lines.join('\n'));
    const p = String(phone||'').replace(/\D+/g,'');
    return `https://wa.me/${p}?text=${text}`;
  }
};
