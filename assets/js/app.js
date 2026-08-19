// Shared light JS enhancements: confirm dialogs, searchable person-picker dropdowns,
// live grade preview (class_record.php). Print pagination (card_slips.php) is pure CSS.

document.querySelectorAll('[data-confirm]').forEach(function (el) {
  el.addEventListener('submit', function (e) {
    if (!confirm(el.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
  });
});

/**
 * Mirrors a wide table's horizontal scrollbar at the top of the container too, so it's
 * reachable without scrolling all the way down past every row first (class_record.php /
 * headteacher/review.php). The top bar is an empty spacer sized to match the real table's
 * scrollWidth; scrolling either one moves the other in lockstep.
 */
function initTopScrollbar(topId, bottomId, spacerId) {
  var top = document.getElementById(topId);
  var bottom = document.getElementById(bottomId);
  var spacer = document.getElementById(spacerId);
  if (!top || !bottom || !spacer) return;

  function syncWidth() {
    spacer.style.width = bottom.scrollWidth + 'px';
  }
  syncWidth();
  window.addEventListener('resize', syncWidth);

  var syncing = false;
  top.addEventListener('scroll', function () {
    if (syncing) return;
    syncing = true;
    bottom.scrollLeft = top.scrollLeft;
    syncing = false;
  });
  bottom.addEventListener('scroll', function () {
    if (syncing) return;
    syncing = true;
    top.scrollLeft = bottom.scrollLeft;
    syncing = false;
  });
}

/**
 * The top-bar search input (#page-search, in includes/layout.php) filters whatever the
 * current page has tagged with class="searchable-item" — quick-link tiles, class cards,
 * review cards, etc. Matches against data-search if present, else the element's own text.
 * Pages that don't tag anything just get an inert-but-present search box, same as before.
 */
(function initPageSearch() {
  var input = document.getElementById('page-search');
  if (!input) return;
  input.addEventListener('input', function () {
    var q = input.value.trim().toLowerCase();
    document.querySelectorAll('.searchable-item').forEach(function (el) {
      var haystack = (el.dataset.search || el.textContent).toLowerCase();
      el.classList.toggle('hidden', q !== '' && haystack.indexOf(q) === -1);
    });
  });
})();

/**
 * Progressively enhances <select class="js-searchable"> into a searchable combobox —
 * useful once a person-picker (teacher/adviser/head teacher) has enough entries that
 * scrolling a plain <select> gets tedious (e.g. a division-wide deployment). The real
 * <select> stays in the DOM (visually hidden) and still submits normally; if this script
 * fails to load, the native select still works, just without search.
 */
document.querySelectorAll('select.js-searchable').forEach(function (select) {
  var options = Array.prototype.map.call(select.options, function (opt) {
    return { value: opt.value, text: opt.textContent };
  });

  var wrapper = document.createElement('div');
  wrapper.className = 'relative';
  select.parentNode.insertBefore(wrapper, select);
  wrapper.appendChild(select);
  select.classList.add('hidden');

  var input = document.createElement('input');
  input.type = 'text';
  input.className = select.className.replace('js-searchable', '').replace('hidden', '').trim();
  input.placeholder = select.dataset.placeholder || 'Search…';
  input.autocomplete = 'off';
  wrapper.insertBefore(input, select);

  var list = document.createElement('div');
  list.className = 'absolute z-10 mt-1 w-full max-h-56 overflow-y-auto bg-white border border-slate-200 rounded-lg shadow-lg hidden';
  wrapper.appendChild(list);

  function currentOption() {
    for (var i = 0; i < options.length; i++) {
      if (options[i].value === select.value) return options[i];
    }
    return null;
  }

  function setSelected(opt) {
    select.value = opt.value;
    input.value = opt.value === '' ? '' : opt.text;
    list.classList.add('hidden');
  }

  function renderList(filter) {
    list.innerHTML = '';
    var f = filter.trim().toLowerCase();
    var matches = options.filter(function (o) {
      return o.value !== '' && o.text.toLowerCase().indexOf(f) !== -1;
    });
    if (matches.length === 0) {
      var empty = document.createElement('div');
      empty.className = 'px-3 py-2 text-sm text-slate-400';
      empty.textContent = 'No matches';
      list.appendChild(empty);
    }
    matches.forEach(function (o) {
      var item = document.createElement('div');
      item.className = 'px-3 py-2 text-sm text-slate-700 hover:bg-accent-50 cursor-pointer';
      item.textContent = o.text;
      item.addEventListener('mousedown', function (e) {
        e.preventDefault(); // fires before input's blur, so the click registers
        setSelected(o);
      });
      list.appendChild(item);
    });
    list.classList.remove('hidden');
  }

  var initial = currentOption();
  input.value = initial && initial.value !== '' ? initial.text : '';

  input.addEventListener('focus', function () {
    input.select();
    renderList('');
  });
  input.addEventListener('input', function () {
    renderList(input.value);
  });
  input.addEventListener('blur', function () {
    setTimeout(function () {
      list.classList.add('hidden');
      var sel = currentOption();
      input.value = sel && sel.value !== '' ? sel.text : '';
    }, 150);
  });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      list.classList.add('hidden');
      input.blur();
    }
  });
});

/**
 * Live Initial/Transmuted grade preview while a teacher types scores, before saving.
 * Mirrors includes/gradeCalc.php's formula client-side for UX only — PHP recomputes and
 * persists the real values on save, so this preview never has to be authoritative.
 */
function initGradePreview(config) {
  var weights = config.weights;
  var transmutation = config.transmutation;
  var items = config.items;
  var students = config.students;

  function lookupTransmuted(initial) {
    if (initial === null) return null;
    // DepEd Order No. 15 s. 2026's transmutation table uses precise 2-decimal boundaries
    // (e.g. 70.00-71.17 -> 75) — look up the raw value directly, don't round to an integer
    // first, or values near a boundary land in the wrong row (mirrors gradeCalc.php).
    var clamped = Math.max(0, Math.min(100, initial));
    for (var i = 0; i < transmutation.length; i++) {
      var row = transmutation[i];
      if (clamped >= row.min && clamped <= row.max) return row.transmuted;
    }
    return null;
  }

  function scoreInput(itemId, studentId) {
    return document.querySelector('input[name="scores[' + itemId + '][' + studentId + ']"]');
  }

  function recalcStudent(studentId) {
    var totals = { WW: { raw: 0, highest: 0, count: 0, filled: 0 }, PT: { raw: 0, highest: 0, count: 0, filled: 0 }, EX: { raw: 0, highest: 0, count: 0, filled: 0 } };

    items.forEach(function (item) {
      var input = scoreInput(item.id, studentId);
      if (!input) return;
      var c = totals[item.type];
      c.count++;
      c.highest += item.highest;
      if (input.value !== '' && !isNaN(parseFloat(input.value))) {
        c.raw += parseFloat(input.value);
        c.filled++;
      }
    });

    var pct = {};
    ['WW', 'PT', 'EX'].forEach(function (type) {
      var c = totals[type];
      pct[type] = (c.count > 0 && c.filled === c.count && c.highest > 0) ? (c.raw / c.highest * 100) : null;
    });

    var initial = null;
    if (pct.WW !== null && pct.PT !== null && pct.EX !== null) {
      initial = Math.round((pct.WW * weights.ww + pct.PT * weights.pt + pct.EX * weights.ex) / 100 * 100) / 100;
    }
    var transmuted = lookupTransmuted(initial);

    var initEl = document.querySelector('[data-preview-initial="' + studentId + '"]');
    var transEl = document.querySelector('[data-preview-transmuted="' + studentId + '"]');
    if (initEl) initEl.textContent = initial !== null ? initial.toFixed(2) : '—';
    if (transEl) transEl.textContent = transmuted !== null ? transmuted.toFixed(2) : '—';
  }

  students.forEach(function (studentId) {
    items.forEach(function (item) {
      var input = scoreInput(item.id, studentId);
      if (input) {
        input.addEventListener('input', function () { recalcStudent(studentId); });
      }
    });
  });
}

/**
 * Lets a teacher copy a block of cells straight from Excel and paste it into the score
 * grid (class_record.php) — it fills across students (rows) and items (columns) starting
 * from whichever cell was focused when pasting, exactly like pasting into a spreadsheet.
 * Each .js-grade-cell carries data-row/data-col so the target cell for each pasted value
 * can be found directly, without needing to walk the DOM.
 */
function initPasteGrid() {
  document.querySelectorAll('.js-grade-cell').forEach(function (input) {
    input.addEventListener('paste', function (e) {
      var clipboard = e.clipboardData || window.clipboardData;
      var text = clipboard ? clipboard.getData('text') : '';
      if (!text) return;

      // Excel copies a multi-cell selection as tab/newline-delimited text; a lone value has
      // neither, so let the browser's normal single-cell paste handle that case untouched.
      if (text.indexOf('\t') === -1 && text.indexOf('\n') === -1) return;
      e.preventDefault();

      var rows = text.replace(/\r/g, '').split('\n');
      if (rows.length && rows[rows.length - 1] === '') rows.pop(); // trailing newline from Excel

      var startRow = parseInt(input.dataset.row, 10);
      var startCol = parseInt(input.dataset.col, 10);

      rows.forEach(function (rowText, rOffset) {
        rowText.split('\t').forEach(function (rawValue, cOffset) {
          var target = document.querySelector('.js-grade-cell[data-row="' + (startRow + rOffset) + '"][data-col="' + (startCol + cOffset) + '"]');
          if (!target || target.disabled) return;
          target.value = rawValue.trim();
          target.dispatchEvent(new Event('input', { bubbles: true }));
        });
      });
    });
  });
}
