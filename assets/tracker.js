(function () {
  // 1. فلترة البوتات والمتصفحات الوهمية قبل أي تسجيل
  var ua = (navigator.userAgent || '').toLowerCase();
  if (navigator.webdriver || /bot|crawl|spider|slurp|headless|phantom|lighthouse/i.test(ua)) {
    return;
  }

  // 2. توليد وحفظ UUID فريد للزائر في localStorage + Cookie
  function genUUID() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0,
        v = c === 'x' ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
  }

  var vid = '';
  try {
    vid = localStorage.getItem('wf_visitor_uuid') || '';
    if (!vid || vid.length < 8) {
      vid = genUUID();
      localStorage.setItem('wf_visitor_uuid', vid);
    }
    document.cookie = 'wf_vid=' + encodeURIComponent(vid) + ';path=/;max-age=' + 365 * 86400 + ';SameSite=Lax';
  } catch (e) {
    vid = 'v_' + Math.random().toString(36).substring(2, 14);
  }

  var inDash = window.location.pathname.indexOf('/dashboard/') !== -1;
  var apiBase = inDash ? '../' : '';

  var urlParams = new URLSearchParams(window.location.search);
  var refParam = urlParams.get('ref') || '';
  var utmSource = urlParams.get('utm_source') || '';
  var prodId = '';
  if (window.location.pathname.indexOf('product.php') !== -1 || window.location.pathname.indexOf('quick_order.php') !== -1) {
    prodId = urlParams.get('id') || '';
  }

  function sendEvent(type, extra) {
    var payload = Object.assign(
      {
        type: type,
        visitor_id: vid,
        product_id: prodId,
        marketer_id: refParam,
        referrer: document.referrer || '',
        utm_source: utmSource,
        page_title: document.title || '',
        uri: window.location.pathname + window.location.search,
      },
      extra || {}
    );
    try {
      fetch(apiBase + 'api_events.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        keepalive: true,
      }).catch(function () {});
    } catch (e) {}
  }

  window.wfTrack = sendEvent;

  // 3. لا نحسب الزيارة إلا بعد تحميل الصفحة فعلياً
  function onPageLoaded() {
    if (window.location.pathname.indexOf('admin.php') !== -1) return;
    sendEvent('page_view');
    if (prodId) {
      sendEvent('product_view', { product_id: prodId });
    }
    // نبضات التواجد اللحظي (Realtime Presence) كل 25 ثانية
    setInterval(function () {
      if (document.visibilityState === 'visible') {
        sendEvent('presence_ping');
      }
    }, 25000);
  }

  if (document.readyState === 'complete') {
    setTimeout(onPageLoaded, 150);
  } else {
    window.addEventListener('load', function () {
      setTimeout(onPageLoaded, 150);
    });
  }

  // =========================================================================
  // 4. نظام البحث بالصور الذكي (Visual Image Search Modal)
  // =========================================================================
  function injectVisualSearchUI() {
    if (document.getElementById('wfVisualSearchModal')) return;

    var modal = document.createElement('div');
    modal.id = 'wfVisualSearchModal';
    modal.style.cssText =
      'display:none;position:fixed;inset:0;z-index:99998;background:rgba(15,23,42,.82);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:16px;direction:rtl;font-family:Cairo,sans-serif';
    modal.innerHTML =
      '<div style="background:#fff;width:100%;max-width:880px;max-height:90vh;border-radius:22px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.45)" onclick="event.stopPropagation()">' +
      '  <div style="display:flex;justify-content:space-between;align-items:center;padding:18px 24px;background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff">' +
      '    <div style="display:flex;align-items:center;gap:10px">' +
      '      <span style="font-size:24px">📷</span>' +
      '      <div><div style="font-size:17px;font-weight:900">البحث الذكي بالصورة في المتجر</div><div style="font-size:12px;color:#94a3b8">ارفع صورة أي منتج أو الصقها هنا لنجد لك المنتجات المطابقة والمشابهة فوراً</div></div>' +
      '    </div>' +
      '    <button type="button" onclick="closeVisualSearchModal()" style="background:#ef4444;color:#fff;border:none;border-radius:99px;padding:8px 18px;font-family:inherit;font-size:13px;font-weight:900;cursor:pointer">✕ إغلاق</button>' +
      '  </div>' +
      '  <div style="padding:20px 24px;overflow-y:auto;flex:1">' +
      '    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;margin-bottom:16px">' +
      '      <label id="wfDropZone" style="border:2px dashed #94a3b8;border-radius:16px;padding:20px;text-align:center;cursor:pointer;background:#f8fafc;transition:all .2s;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:170px">' +
      '        <input type="file" id="wfImgInput" accept="image/*" style="display:none">' +
      '        <img id="wfPreviewImg" src="" style="display:none;max-height:130px;max-width:100%;border-radius:12px;object-fit:contain;margin-bottom:8px;box-shadow:0 4px 12px rgba(0,0,0,.1)">' +
      '        <div id="wfDropPrompt">' +
      '          <div style="font-size:36px;margin-bottom:6px">🖼️</div>' +
      '          <div style="font-weight:800;font-size:14px;color:#0f172a">اضغط لاختيار صورة المنتج أو اسحبها هنا</div>' +
      '          <div style="font-size:11px;color:#64748b;margin-top:4px">يدعم JPG, PNG, WEBP أو لصق الصورة (Ctrl+V)</div>' +
      '        </div>' +
      '      </label>' +
      '      <div style="display:flex;flex-direction:column;justify-content:space-between;gap:10px;background:#f8fafc;padding:16px;border-radius:16px;border:1px solid #e2e8f0">' +
      '        <div>' +
      '          <div style="font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">📂 تحديد القسم (اختياري لنتائج أدق):</div>' +
      '          <select id="wfImgCat" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-family:inherit;font-size:13px;font-weight:700;background:#fff">' +
      '            <option value="all">✨ كل الأقسام (تعرف تلقائي)</option>' +
      '            <option value="أحذية">👟 أحذية</option>' +
      '            <option value="ملابس رجالي">👕 ملابس رجالي</option>' +
      '            <option value="ملابس حريمي">👗 ملابس حريمي</option>' +
      '            <option value="ساعات">⌚ ساعات</option>' +
      '            <option value="شنط ومحافظ">👜 شنط ومحافظ</option>' +
      '            <option value="إلكترونيات">📱 إلكترونيات</option>' +
      '            <option value="المنزل والمطبخ">🏠 المنزل والمطبخ</option>' +
      '            <option value="العناية والتجميل">💄 العناية والتجميل</option>' +
      '            <option value="إكسسوارات">🕶️ إكسسوارات</option>' +
      '            <option value="أطفال وألعاب">🧸 أطفال وألعاب</option>' +
      '            <option value="الرياضة واللياقة">🏋️ الرياضة واللياقة</option>' +
      '          </select>' +
      '        </div>' +
      '        <div>' +
      '          <div style="font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">🔤 كلمة مفتاحية مساعدة (اختياري):</div>' +
      '          <input type="text" id="wfImgKw" placeholder="مثال: كوتشي، ساعة، ترنج، خلاط..." style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:10px;font-family:inherit;font-size:13px">' +
      '        </div>' +
      '        <div id="wfDetectedColors" style="font-size:12px;color:#334155;min-height:22px"></div>' +
      '        <button type="button" id="wfRunImgSearchBtn" onclick="runVisualSearch()" style="background:#2563eb;color:#fff;border:none;border-radius:10px;padding:11px;font-family:inherit;font-size:14px;font-weight:900;cursor:pointer">🔍 ابحث عن المنتجات المشابهة</button>' +
      '      </div>' +
      '    </div>' +
      '    <div id="wfImgResults"></div>' +
      '  </div>' +
      '</div>';

    modal.addEventListener('click', function (e) {
      if (e.target === modal) window.closeVisualSearchModal();
    });
    document.body.appendChild(modal);

    var fileInp = document.getElementById('wfImgInput');
    fileInp.addEventListener('change', function () {
      if (this.files && this.files[0]) processSelectedImage(this.files[0]);
    });
    document.getElementById('wfImgCat').addEventListener('change', function () {
      if (window._wfLastRgbs) window.runVisualSearch();
    });
  }

  function extractDominantRgbs(imgEl) {
    var canvas = document.createElement('canvas');
    var ctx = canvas.getContext('2d');
    var w = 64,
      h = 64;
    canvas.width = w;
    canvas.height = h;
    ctx.drawImage(imgEl, 0, 0, w, h);
    var data = ctx.getImageData(10, 10, 44, 44).data;
    var buckets = {};
    for (var i = 0; i < data.length; i += 4) {
      var r = data[i],
        g = data[i + 1],
        b = data[i + 2],
        a = data[i + 3];
      if (a < 128) continue;
      // تجاهل الخلفيات البيضاء الناصعة جداً حول المنتج
      if (r > 244 && g > 244 && b > 244) continue;
      var qr = Math.round(r / 32) * 32;
      var qg = Math.round(g / 32) * 32;
      var qb = Math.round(b / 32) * 32;
      var key = qr + ',' + qg + ',' + qb;
      if (!buckets[key]) buckets[key] = { count: 0, r: 0, g: 0, b: 0 };
      buckets[key].count++;
      buckets[key].r += r;
      buckets[key].g += g;
      buckets[key].b += b;
    }
    var sorted = Object.keys(buckets)
      .map(function (k) {
        var bk = buckets[k];
        return {
          count: bk.count,
          rgb: [Math.round(bk.r / bk.count), Math.round(bk.g / bk.count), Math.round(bk.b / bk.count)],
        };
      })
      .sort(function (a, b) {
        return b.count - a.count;
      });
    if (!sorted.length) return [[245, 245, 245]];
    return sorted.slice(0, 3).map(function (x) {
      return x.rgb;
    });
  }

  // ميزات بصرية (لون + شكل) — لازم تطابق updater/visual_features.py بالظبط
  // h: هستوجرام 64 خانة (4 مستويات لكل قناة) متطبّع لمجموع 255
  // t: شبكة رمادية 8×8 (الخلفية البيضا = 255)
  function extractVisualFeatures(imgEl) {
    var G = 64,
      C = 8,
      CELL = G / C;
    var canvas = document.createElement('canvas');
    canvas.width = G;
    canvas.height = G;
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, G, G);
    ctx.drawImage(imgEl, 0, 0, G, G);
    var data = ctx.getImageData(0, 0, G, G).data;
    var hist = new Array(64).fill(0);
    var graySum = new Array(C * C).fill(0);
    var total = 0;
    for (var i = 0; i < data.length; i += 4) {
      var px = i / 4;
      var x = px % G,
        y = Math.floor(px / G);
      var cell = Math.floor(y / CELL) * C + Math.floor(x / CELL);
      var r = data[i],
        g = data[i + 1],
        b = data[i + 2];
      if (r > 244 && g > 244 && b > 244) {
        graySum[cell] += 255;
        continue;
      }
      graySum[cell] += 0.299 * r + 0.587 * g + 0.114 * b;
      hist[(r >> 6) * 16 + (g >> 6) * 4 + (b >> 6)] += 1;
      total++;
    }
    var h = hist.map(function (c) {
      return total ? Math.floor((c * 255) / total + 0.5) : 0;
    });
    var perCell = CELL * CELL;
    var t = graySum.map(function (s2) {
      return Math.floor(s2 / perCell + 0.5);
    });
    return { h: h, t: t };
  }

  function processSelectedImage(file) {
    window._wfLastFilename = file.name || '';
    var reader = new FileReader();
    reader.onload = function (e) {
      var prev = document.getElementById('wfPreviewImg');
      var prompt = document.getElementById('wfDropPrompt');
      prev.onload = function () {
        window._wfLastRgbs = extractDominantRgbs(prev);
        window._wfLastFeat = extractVisualFeatures(prev);
        window.runVisualSearch();
      };
      prev.src = e.target.result;
      prev.style.display = 'block';
      if (prompt) prompt.innerHTML = '<div style="font-size:12px;color:#2563eb;font-weight:800">🔄 اضغط لتغيير الصورة</div>';
    };
    reader.readAsDataURL(file);
  }

  window.runVisualSearch = function () {
    var resBox = document.getElementById('wfImgResults');
    if (!resBox) return;
    resBox.innerHTML = '<div style="text-align:center;padding:30px;font-weight:800;color:#2563eb">⏳ جاري تحليل الصورة ومطابقة المنتجات بصرياً...</div>';
    var cat = document.getElementById('wfImgCat') ? document.getElementById('wfImgCat').value : 'all';
    var kw = document.getElementById('wfImgKw') ? document.getElementById('wfImgKw').value : '';
    fetch(apiBase + 'api_image_search.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        rgbs: window._wfLastRgbs || [],
        feat: window._wfLastFeat || null,
        cat: cat,
        filename: window._wfLastFilename || '',
        keyword: kw,
      }),
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (d) {
        var colBox = document.getElementById('wfDetectedColors');
        if (colBox && d.detected_colors && d.detected_colors.length) {
          colBox.innerHTML =
            '🎨 <b>الألوان المكتشفة بالصورة:</b> ' +
            d.detected_colors
              .map(function (c) {
                return '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:99px;font-weight:800;margin:0 3px">' + c + '</span>';
              })
              .join('');
        }
        var items = d.items || [];
        if (!items.length) {
          resBox.innerHTML = '<div style="text-align:center;padding:24px;color:#64748b">لم يتم العثور على تطابق في هذا القسم — جرّب اختيار "كل الأقسام".</div>';
          return;
        }
        var html =
          '<div style="font-size:14px;font-weight:900;margin-bottom:10px;color:#0f172a">✨ تم العثور على ' +
          items.length +
          ' منتج مشابه بصرياً:</div>' +
          '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:12px">';
        items.forEach(function (it) {
          html +=
            '<a href="' +
            it.link +
            '" style="border:1px solid #e2e8f0;border-radius:14px;padding:10px;text-decoration:none;color:#0f172a;background:#fff;display:flex;flex-direction:column;justify-content:space-between;transition:transform .15s;position:relative">' +
            '<span style="position:absolute;top:8px;right:8px;background:#16a34a;color:#fff;font-size:10px;font-weight:900;padding:2px 8px;border-radius:99px">تطابق ' +
            it.match_pct +
            '%</span>' +
            '<img src="' +
            it.img_url +
            '" loading="lazy" referrerpolicy="no-referrer" style="width:100%;height:130px;object-fit:contain;border-radius:8px;background:#f8fafc;margin-bottom:8px">' +
            '<div style="font-size:12px;font-weight:800;line-height:1.4;height:34px;overflow:hidden;margin-bottom:6px">' +
            it.name +
            '</div>' +
            '<div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:14px;font-weight:900;color:#dc2626">' +
            it.price +
            ' <small style="font-size:10px">' +
            it.cur +
            '</small></span><span style="font-size:10px;color:#64748b">' +
            it.cat +
            '</span></div>' +
            '</a>';
        });
        html += '</div>';
        resBox.innerHTML = html;
      })
      .catch(function () {
        resBox.innerHTML = '<div style="text-align:center;padding:20px;color:#dc2626">حدث خطأ أثناء البحث بالصورة.</div>';
      });
  };

  window.openVisualSearchModal = function () {
    injectVisualSearchUI();
    var m = document.getElementById('wfVisualSearchModal');
    if (m) m.style.display = 'flex';
  };

  window.closeVisualSearchModal = function () {
    var m = document.getElementById('wfVisualSearchModal');
    if (m) m.style.display = 'none';
  };

  // أسماء مستعارة موحّدة تُستخدم في كل صفحات الموقع
  window.wfOpenVisualSearch = window.openVisualSearchModal;
  window.wfCloseVisualSearch = window.closeVisualSearchModal;
})();
