/**
 * Cascading selects: จังหวัด → อำเภอ/เขต → ตำบล/แขวง → รหัสไปรษณีย์
 * ข้อมูล: earthchie/jquery.Thailand.js — raw_database.json จาก CDN
 * คู่กับ includes/thai_address_fields.php | อธิบายเต็ม: docs/SYSTEM_PRESENTATION_GUIDE.md
 */
(function (global) {
  'use strict';

  var DB_URL =
    'https://cdn.jsdelivr.net/gh/earthchie/jquery.Thailand.js@master/jquery.Thailand.js/database/raw_database/raw_database.json';

  function sortThai(arr) {
    return arr.slice().sort(function (a, b) {
      return a.localeCompare(b, 'th');
    });
  }

  function buildIndex(rows) {
    var byP = new Map();
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      var p = row.province;
      var a = row.amphoe;
      var d = row.district;
      var z = String(row.zipcode);
      if (!byP.has(p)) byP.set(p, new Map());
      var am = byP.get(p);
      if (!am.has(a)) am.set(a, []);
      am.get(a).push({ tambon: d, zip: z });
    }
    return byP;
  }

  function fillSelect(sel, options, placeholder) {
    sel.innerHTML = '';
    var opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = placeholder;
    sel.appendChild(opt0);
    for (var i = 0; i < options.length; i++) {
      var o = document.createElement('option');
      o.value = options[i].value;
      o.textContent = options[i].label;
      if (options[i].zip) o.setAttribute('data-zip', options[i].zip);
      if (options[i].tambon) o.setAttribute('data-tambon', options[i].tambon);
      sel.appendChild(o);
    }
  }

  function mount(opts) {
    var provinceEl = document.querySelector(opts.province);
    var amphoeEl = document.querySelector(opts.amphoe);
    var tambonEl = document.querySelector(opts.tambon);
    var zipEl = document.querySelector(opts.zip);
    if (!provinceEl || !amphoeEl || !tambonEl || !zipEl) return;

    var index = null;
    var hiddenFull = opts.hiddenFull ? document.querySelector(opts.hiddenFull) : null;

    function syncHidden() {
      if (!hiddenFull) return;
      var p = provinceEl.value;
      var a = amphoeEl.value;
      var tv = tambonEl.value;
      var z = zipEl.value;
      var t = tv;
      if (tv.indexOf('\x1e') !== -1) {
        t = tv.split('\x1e')[1] || tv;
      }
      var optT = tambonEl.options[tambonEl.selectedIndex];
      if (optT && optT.getAttribute('data-tambon')) {
        t = optT.getAttribute('data-tambon');
      }
      if (!p || !a || !t || !z) {
        hiddenFull.value = '';
        return;
      }
      hiddenFull.value = 'ต.' + t + ' อ.' + a + ' จ.' + p + ' ' + z;
    }

    zipEl.addEventListener('change', syncHidden);
    tambonEl.addEventListener('change', function () {
      var opt = tambonEl.options[tambonEl.selectedIndex];
      var z = opt.getAttribute('data-zip') || '';
      fillSelect(zipEl, z ? [{ value: z, label: z }] : [], '— เลขไปรษณีย์ —');
      if (z) {
        zipEl.value = z;
      }
      syncHidden();
    });

    amphoeEl.addEventListener('change', function () {
      fillSelect(tambonEl, [], '— ตำบล / แขวง —');
      fillSelect(zipEl, [], '— เลขไปรษณีย์ —');
      var p = provinceEl.value;
      var a = amphoeEl.value;
      if (!p || !a || !index) return;
      var list = index.get(p).get(a) || [];
      var seen = Object.create(null);
      var opts = [];
      for (var i = 0; i < list.length; i++) {
        var key = list[i].tambon + '\0' + list[i].zip;
        if (seen[key]) continue;
        seen[key] = true;
        var label = list[i].tambon;
        var dup = list.filter(function (x) {
          return x.tambon === list[i].tambon;
        }).length;
        if (dup > 1) label += ' (' + list[i].zip + ')';
        opts.push({
          value: list[i].zip + '\x1e' + list[i].tambon,
          label: label,
          zip: list[i].zip,
          tambon: list[i].tambon,
        });
      }
      opts.sort(function (x, y) {
        return x.label.localeCompare(y.label, 'th');
      });
      fillSelect(tambonEl, opts, '— ตำบล / แขวง —');
      syncHidden();
    });

    provinceEl.addEventListener('change', function () {
      fillSelect(amphoeEl, [], '— อำเภอ / เขต —');
      fillSelect(tambonEl, [], '— ตำบล / แขวง —');
      fillSelect(zipEl, [], '— เลขไปรษณีย์ —');
      var p = provinceEl.value;
      if (!p || !index) return;
      var am = index.get(p);
      var names = sortThai(Array.from(am.keys()));
      var options = names.map(function (n) {
        return { value: n, label: n };
      });
      fillSelect(amphoeEl, options, '— อำเภอ / เขต —');
      syncHidden();
    });

    fetch(DB_URL)
      .then(function (r) {
        if (!r.ok) throw new Error('load fail');
        return r.json();
      })
      .then(function (rows) {
        index = buildIndex(rows);
        var provinces = sortThai(Array.from(index.keys()));
        fillSelect(
          provinceEl,
          provinces.map(function (p) {
            return { value: p, label: p };
          }),
          '— จังหวัด —'
        );

        [provinceEl, amphoeEl, tambonEl, zipEl].forEach(function (el) {
          el.removeAttribute('disabled');
        });

        if (opts.initial && opts.initial.province) {
          var ini = opts.initial;
          provinceEl.value = ini.province;
          provinceEl.dispatchEvent(new Event('change'));
          if (ini.amphoe) {
            amphoeEl.value = ini.amphoe;
            amphoeEl.dispatchEvent(new Event('change'));
          }
          if (ini.tambon && ini.zip) {
            var wantZ = String(ini.zip);
            var wantT = ini.tambon;
            for (var j = 0; j < tambonEl.options.length; j++) {
              var o = tambonEl.options[j];
              if (o.getAttribute('data-zip') === wantZ && o.getAttribute('data-tambon') === wantT) {
                tambonEl.selectedIndex = j;
                break;
              }
            }
            tambonEl.dispatchEvent(new Event('change'));
          }
        }
        syncHidden();
      })
      .catch(function () {
        provinceEl.innerHTML =
          '<option value="">ไม่สามารถโหลดข้อมูลที่อยู่ (ตรวจสอบการเชื่อมต่ออินเทอร์เน็ต)</option>';
      });
  }

  global.ThaiAddressSelect = { mount: mount, DB_URL: DB_URL };
})(typeof window !== 'undefined' ? window : this);
