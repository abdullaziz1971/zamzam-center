# حزمة الإصلاح النهائية — موقع مركز ينابيع زمزم

> الهدف: توحيد مرجع البيانات (`data.js`) وربط اللوحة بالموقع، وإخفاء الحبة من عروض الشركات، وتفعيل الحبة فقط في صفحة الطلب بنسبة (افتراضي 5% أو نسبة على مستوى الشركة).

## ما بداخل الحزمة
- **admin.html** — نسخة لوحة إدارة محسّنة (تحميل data.js من الجذر مباشرة، أزرار مفعّلة).
- **admin.js** — منطق اللوحة (يفضّل القراءة من data.js، يحفظ إلى `/save-data.php` في الجذر).
- **companies.html** — عرض الشركات، يحترم `visible=false` ويخفي تعبئة الحبة (×1/حبة) إن وُجدت.
- **order.html** — طلب الشراء، الافتراضي = الكرتونة (الوحدة 2)، ويحسب الحبة تلقائيًا (5% أو نسبة الشركة).
- **save-data.php** — كاتب موحّد يكتب `data.json` و`data.js` (الجذر).
- **healthcheck.html** — صفحة فحص ذاتي للتأكد من المسارات والتوليد.

## التعليمات (خطوة بخطوة)
1. **نسخ احتياطي** للملفات التالية على الاستضافة:
   - `/public_html/admin_v2/admin.html`, `/public_html/admin_v2/admin.js`
   - `/public_html/companies.html`, `/public_html/order.html`
   - `/public_html/save-data.php`, `/public_html/data.js`, `/public_html/data.json`

2. **تعطيل نسخ الحفظ داخل مجلد اللوحة**:
   - إعادة تسمية:
     - `/public_html/admin_v2/save-data.php` → `save-data.php.disabled`
     - `/public_html/admin_v2/quick-save.php` → `quick-save.php.disabled`
   - (اختياري) إعادة تسمية أي ملف قد يحقن أو يبدّل البيانات مثل `overlay.js` → `overlay.js.off`.

3. **رفع واستبدال الملفات** من هذه الحزمة:
   - في **الجذر** `/public_html/`:
     - `save-data.php`, `companies.html`, `order.html`, `healthcheck.html`
   - في مجلد **اللوحة** `/public_html/admin_v2/`:
     - `admin.html`, `admin.js`
   - (لا تترك أسماء `patch_`؛ استبدل بنفس الأسماء المذكورة أعلاه).

4. **التأكد من تحميل data.js من الجذر**:
   - داخل جميع الصفحات واللوحة، يجب تضمين:
     ```html
     <script src="/data.js" defer></script>
     ```
   - اللوحة تحفّظ إلى **`/save-data.php`** (مسار مطلق من الجذر).

5. **توليد data.js الصحيح**:
   - افتح `/public_html/healthcheck.html` في المتصفح.
   - يجب أن ترى: `status: 200` و `has ZAMZAM_DATA: true` ونتيجة حفظ `ok:true`.
   - إن ظهرت `has ZAMZAM_DATA: false`، اضغط "حفظ" من لوحة الإدارة مرة واحدة أو نفّذ طلب الحفظ التالي من الكونسول:
     ```js
     fetch('/save-data.php',{
       method:'POST',headers:{'Content-Type':'application/json'},
       body: JSON.stringify({password:'zamzam2025', companies:[], featuredOffers:{active:false,items:[]}, mergedOffers:{active:false,items:[]}})
     })
     ```
     ثم حدّث `/data.js` وتأكد أن أول سطر يبدأ بـ `window.ZAMZAM_DATA=`.

6. **اختبار عملي سريع**:
   - افتح `/admin_v2/admin.html` → غيّر اسم الموقع → **حفظ**.
   - افتح `/data.js` وتأكد من تحديث `metadata.siteName`.
   - افتح `/companies.html` و `/order.html` وتأكد من ظهور التحديث:
     - الشركات: لا تُظهر الحبة.
     - الطلب: الافتراضي الكرتونة، ويمكن اختيار الحبة إن كانت نسبة الشركة > 0 أو تم تحديد سعر حبة للمادة.

## ملاحظات مهمة
- **سعر الحبة** في الطلب:
  - إن تم تحديده يدويًا (حقل "سعر الحبة" للمنتج) سيُستخدم كما هو.
  - إن لم يُحدّد: نحسبه = (سعر الكرتونة ÷ عدد التعبئة) × (1 + نسبة نصف الجملة).
  - النسبة الافتراضية 5%، ويمكن وضع نسبة على مستوى الشركة (حقل "نصف جملة % (للشركة)" في اللوحة).

- **إخفاء الحبة في عروض الشركات**:
  - حتى لو كانت موجودة داخل متغيّرات المادة قديمًا، الصفحة الآن تفلتر أي تسمية تحتوي (×1/حبة/حبه).

- **الأمان والصلاحيات**:
  - اترك `save-data.php` في الجذر فقط.
  - تأكد أن PHP قادر على الكتابة إلى `data.json` و`data.js` (غالبًا 0644 والمالك صحيح).

بالتوفيق. في حال أي رسالة خطأ في healthcheck، انسخ النص وأرسله لي وسأعطيك السطر الدقيق للتعديل.
