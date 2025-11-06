// compat-data-alias.js — توحيد البيانات ودعم الوحدات (الوحدة 1 = pack:1)
(function(){
  if (!window) return;
  var D = window.ZAMZAM_DATA || window.DATA || {};

  // Helper: normalize units for a product
  function normalizeProduct(p){
    p = p || {};
    // If units exist, ensure pack #1 and mirror to variants/price for legacy pages
    if (Array.isArray(p.units) && p.units.length){
      // sanitize units
      p.units = p.units
        .map(function(u){ return u||{}; })
        .filter(function(u){ return (u.name || u.price || u.pack); });

      // enforce unit[0].pack = 1
      if (!p.units[0]) p.units[0] = {name:'حبة', pack:1, price:null};
      p.units[0].pack = 1;

      var def = (typeof p.defaultUnitIndex === 'number' && p.defaultUnitIndex >=0 && p.defaultUnitIndex < p.units.length) ? p.defaultUnitIndex : 0;
      p.defaultUnitIndex = def;

      // build legacy variants
      p.hasVariants = p.units.length > 1;
      p.variants = p.units.map(function(u){
        var label = (u.pack === 1 || u.pack === '1') ? ( (u.name||'') + ' × 1' ) : ((u.name||'') + ' × ' + u.pack);
        return { label: label.trim(), price: Number(u.price)||0 };
      });

      // legacy price uses default unit price
      p.price = (p.units[def] && Number(p.units[def].price)) ? Number(p.units[def].price) : (Number(p.price)||0);

      // packaging text (for pages that show a single packaging)
      p.packaging = (p.units[def] && p.units[def].pack) ? String(p.units[def].pack) : (p.packaging||'—');
      return p;
    }

    // else: build units from legacy 'variants' or single 'price'
    if (p.hasVariants && Array.isArray(p.variants) && p.variants.length){
      var units = [];
      p.variants.forEach(function(v, i){
        var pack = 1, nm = 'وحدة';
        // try to parse "label like: كرتونة × 12"
        try {
          var m = String(v.label||'').split('×');
          if (m.length>=2){
            nm = m[0].trim();
            var k = parseInt(String(m[1]).replace(/[^\d]/g,''),10);
            pack = isFinite(k) && k>0 ? k : 1;
          } else {
            nm = String(v.label||'وحدة').trim();
            pack = (i===0) ? 1 : 12;
          }
        } catch(_){}
        units.push({ name: nm, pack: pack, price: Number(v.price)||0 });
      });
      p.units = units;
      p.defaultUnitIndex = 0;
      return p;
    }

    if (p.price != null) {
      // only one price -> assume single unit (pack=1)
      p.units = [{ name: (p.unit||'حبة'), pack: 1, price: Number(p.price)||0 }];
      p.defaultUnitIndex = 0;
    }
    return p;
  }

  if (Array.isArray(D.companies)) {
    D.companies.forEach(function(c){
      if (!Array.isArray(c.products)) return;
      c.products = c.products.map(function(p){ return normalizeProduct(p); });
    });
  }

  // Expose back
  window.ZAMZAM_DATA = D;
  if (!window.DATA) window.DATA = D;
})();
