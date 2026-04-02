/* kompletní frontend JS pro Skladový hospodář
   - opravy/úpravy:
     * Robustní převod hmotnosti: pokud uživatel zadá velké číslo (pravděpodobně gramy)
       a recept obsahuje alespoň jednu surovinu v 'g' nebo 'mg', automaticky převedeme
       na kg (dělení 1000). Tím se odstraní chyby typu "potřeba 31110 místo 31.110".
     * Při přípravě multi-preview vysíláme korektně všechna vybraná data (žádné přepisování).
     * Přidány debug výpisy (console.debug) pro vybrané produkty/odesílaná data a serverovou odpověď.
     * Zachovány ostatní funkce (Prodej, Odpis, preview výroby, potvrzení, AJAX volání).
   - verze: plný soubor
*/

document.addEventListener('DOMContentLoaded', function() {
  const APP_ID = 'skladovy-hospodar-app';
  const el = document.getElementById(APP_ID);
  if (!el) return;

  // AJAX URL předává PHP: window.skladovyHospodarAjax
  const ajaxUrl = (typeof window.skladovyHospodarAjax !== 'undefined')
    ? window.skladovyHospodarAjax
    : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');

  // Data cache
  let products = [];         // products with stock/price/img/cats
  let categories = [];       // categories for Prodej (from WP)
  let stockedProducts = [];  // simplified list for top table
  let itemsMap = {};        // internal items (suroviny) from PHP
  let productMap = {};      // rozpad (recept) map from PHP
  let lowStockProducts = []; // products that reached min threshold

  // Selected sets per mode
  const selected = { prodej: new Set(), vyroba: new Set(), odpis: new Set() };
  // Maintain order of selection for Prodej (array of pid strings)
  const selectedOrder = [];

  // UI state
  let mode = 'prodej'; // 'prodej'|'vyroba'|'odpis'
  let cart = {};       // { productId: { qty, sale } }
  let cart_sale = 0;
  let search = '';
  let openCatId = null;
  let selectedCustomer = null;
  let creatingCustomer = false;

  // Stav zobrazení zásob (toggle)
  let showStock = false;

  // ---------- Utilities ----------
  function toNumber(x) {
    if (x === undefined || x === null || x === '') return 0;
    return Number(String(x).replace(',', '.')) || 0;
  }

  function safeJSONParse(text) {
    try { return JSON.parse(text); } catch (e) { return null; }
  }

  // Robust POST wrapper: always sets credentials, returns parsed JSON or {ok:false, raw, httpStatus}
  function sendPost(action, payload = {}) {
    const bodyPairs = [];
    bodyPairs.push('action=' + encodeURIComponent(action));
    for (const k in payload) {
      if (!Object.prototype.hasOwnProperty.call(payload, k)) continue;
      const v = payload[k];
      // if value is object/array, stringify it
      const value = (typeof v === 'object') ? JSON.stringify(v) : String(v);
      bodyPairs.push(encodeURIComponent(k) + '=' + encodeURIComponent(value));
    }
    const body = bodyPairs.join('&');

    return fetch(ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      credentials: 'same-origin',
      body: body
    })
    .then(async r => {
      const text = await r.text();
      const json = safeJSONParse(text);
      if (json !== null) return json;
      return { ok: false, httpStatus: r.status, raw: text };
    })
    .catch(err => ({ ok: false, networkError: (err && err.message) ? err.message : String(err) }));
  }

  // ---------- Modal ----------
  function ensureModal() {
    if (document.getElementById('sh-modal')) return;
    const modal = document.createElement('div');
    modal.id = 'sh-modal';
    modal.style.display = 'none';
    modal.innerHTML = `
      <div id="sh-modal-bg" style="position:fixed;left:0;top:0;width:100vw;height:100vh;background:rgba(0,0,0,.32);z-index:99998;"></div>
      <div id="sh-modal-box" style="position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);max-width:900px;width:96vw;background:#fff;border-radius:10px;padding:18px;z-index:99999;box-shadow:0 10px 30px rgba(0,0,0,.3);">
        <div id="sh-modal-body" style="max-height:70vh;overflow:auto;"></div>
        <div style="text-align:right;margin-top:10px;">
          <button id="sh-modal-close" class="button">Zavřít</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
    document.getElementById('sh-modal-bg').addEventListener('click', hideModal);
    document.getElementById('sh-modal-close').addEventListener('click', hideModal);
  }

function showModal(html) {
  ensureModal();
  const body = document.getElementById('sh-modal-body');

  // Zaokrouhlení hodnot na celé číslo (bez desetinných míst)
  const roundedHtml = html.replace(/(\d+\.\d+)(g|ks)/g, (match, num, unit) => {
    const roundedValue = Math.round(parseFloat(num)); // zaokrouhlení na celé číslo
    return `${roundedValue} ${unit}`;
  });

  body.innerHTML = roundedHtml;
  document.getElementById('sh-modal').style.display = 'block';

  const scrollBox = document.getElementById('sh-modal-body');
  if (scrollBox) {
    scrollBox.addEventListener('wheel', function(e) {
      const delta = e.deltaY;
      const atTop = scrollBox.scrollTop === 0;
      const atBottom = scrollBox.scrollTop + scrollBox.clientHeight >= scrollBox.scrollHeight;
      if (!(delta < 0 && atTop) && !(delta > 0 && atBottom)) {
        e.preventDefault();
        scrollBox.scrollTop += delta;
      }
    });
  }

  document.querySelector('#sh-modal').addEventListener(
    'wheel',
    (e) => {
      if (scrollBox.contains(e.target)) {
        e.preventDefault();
      }
    },
    { passive: false }
  );
}

  function hideModal() {
    const m = document.getElementById('sh-modal');
    if (m) m.style.display = 'none';
  }

  // Focus numeric and try to trigger numeric keyboard
  function focusAndSelectNumeric(inputEl) {
    if (!inputEl) return;
    try {
      inputEl.setAttribute('inputmode', 'numeric');
      inputEl.setAttribute('pattern', '[0-9]*');
    } catch (e) {}
    try {
      inputEl.focus();
      if (typeof inputEl.select === 'function') inputEl.select();
      else if (typeof inputEl.setSelectionRange === 'function') inputEl.setSelectionRange(0, inputEl.value ? inputEl.value.length : 0);
    } catch (e) {}
  }

  // helpers to manage selectedOrder
  function addToSelectedOrder(pid) {
    pid = String(pid);
    if (!selectedOrder.includes(pid)) selectedOrder.push(pid);
  }
  function removeFromSelectedOrder(pid) {
    pid = String(pid);
    const idx = selectedOrder.indexOf(pid);
    if (idx !== -1) selectedOrder.splice(idx, 1);
  }

  // Helper to infer mode from a recipe part (if mode not set)
  function inferMode(part) {
    if (!part) return 'per_piece';
    if (part.mode && String(part.mode).trim() !== '') return String(part.mode);
    // First check part.unit
    let unit = (part.unit || '').toString().toLowerCase().trim();
    // If not present, try global itemsMap lookup by item_id (or item_<id>)
    if (!unit && part.item_id) {
      const iid = part.item_id;
      if (itemsMap && typeof itemsMap[iid] !== 'undefined' && itemsMap[iid] && itemsMap[iid].unit) unit = String(itemsMap[iid].unit).toLowerCase().trim();
      else if (itemsMap && typeof itemsMap['item_'+iid] !== 'undefined' && itemsMap['item_'+iid] && itemsMap['item_'+iid].unit) unit = String(itemsMap['item_'+iid].unit).toLowerCase().trim();
    }
    if (['g','mg','kg'].includes(unit)) return 'per_kg';
    return 'per_piece';
  }

  // ---------- Data loading ----------
  function loadInitialData() {
    // Const: správné čtení produktů (a fallback)
    itemsMap = (typeof window.skladovyHospodarItems !== 'undefined') ? window.skladovyHospodarItems : {};
    productMap = (typeof window.skladovyHospodarProductMap !== 'undefined') ? window.skladovyHospodarProductMap : {};

    // Správné AJAX volání
    return sendPost('hospodar_get_products', {})
        .then(res => {
            if (res && Array.isArray(res.products)) {
                products = res.products;                     // Načtené produkty
                categories = res.categories || [];           // Kategorie
                stockedProducts = res.stocked_products || [];// Produkty skladem
                lowStockProducts = res.low_stock_products || [];// Nízký stav
            } else {
                // Fallback starší hodnoty (invalid data)
                console.warn('Fallback: invalid products/default state');
                products = (typeof window.skladovyHospodarAllWooProducts !== 'undefined')
                    ? window.skladovyHospodarAllWooProducts : [];
                categories = (typeof window.skladovyHospodarAllWooCategories !== 'undefined')
                    ? window.skladovyHospodarAllWooCategories : [];
                stockedProducts = [];
                lowStockProducts = [];
            }

            // Debug pro logování responze
            console.debug('Sklad data response:', res);
        });
}

  // ---------- Render helpers ----------
  function createEl(tag, attrs = {}, html = '') {
    const e = document.createElement(tag);
    for (const k in attrs) {
      if (k === 'style') {
        for (const s in attrs.style) e.style[s] = attrs.style[s];
      } else if (k.startsWith('on') && typeof attrs[k] === 'function') {
        e.addEventListener(k.substring(2), attrs[k]);
      } else {
        e.setAttribute(k, attrs[k]);
      }
    }
    if (html !== '') e.innerHTML = html;
    return e;
  }

  function formatCurrency(v) { return Math.round(v) + ' Kč'; }

  // ---------- Main render ----------
  function render() {
    el.innerHTML = '';
    // Top: small summary table of tracked products (stockedProducts or lowStockProducts),
    // remove "Min" column and instead color rows by stock thresholds
    const topWrap = createEl('div', { style: { marginBottom: '14px' } });

    // Zásoby toggle - používá stejnou třídu jako ostatní tlačítka, aby byl design konzistentní
    const stockToggleBtn = createEl('button', { class: 'sklad-mode-btn', id: 'sh-stock-toggle' }, showStock ? 'Skrýt zásoby' : 'Zásoby');
    stockToggleBtn.style.marginBottom = '8px';
    stockToggleBtn.addEventListener('click', () => { showStock = !showStock; render(); });
    topWrap.appendChild(stockToggleBtn);

    // Kontejner pro tabulku zásob (vykreslujeme do něj, aby se neprepísal rodič)
    const stockContainer = createEl('div', { id: 'sh-stock-container' });
    topWrap.appendChild(stockContainer);

if (showStock) {
  // Vypiš všechny produkty, u kterých se sleduje stav skladu
  let listToShow = stockedProducts;
  if (listToShow && listToShow.length) {
    // Seřaď podle kategorie a jména produktu
    const sorted = listToShow.slice().sort((a, b) => {
      const ca = (a.category || '').toString().toLowerCase();
      const cb = (b.category || '').toString().toLowerCase();
      if (ca < cb) return -1;
      if (ca > cb) return 1;
      // pokud je kategorie stejná, řaď podle jména
      const na = (a.name || '').toString().toLowerCase();
      const nb = (b.name || '').toString().toLowerCase();
      if (na < nb) return -1;
      if (na > nb) return 1;
      return 0;
    });

    const table = ['<div id="sh-stock-table-wrap"><table style="width:100%;max-width:900px;border-collapse:collapse;background:#fff;"><thead><tr><th style="text-align:left;padding:8px;background:#f6f7ff;border:1px solid #eee">Kategorie</th><th style="text-align:left;padding:8px;background:#f6f7ff;border:1px solid #eee">Produkt</th><th style="text-align:right;padding:8px;background:#f6f7ff;border:1px solid #eee">Skladem</th></tr></thead><tbody>'];
    for (const p of sorted) {
      // Barevné označení na základě stavu skladu: <=5 červená, <=15 oranžová
      let cls = '';
      const stock = (typeof p.stock !== 'undefined' && p.stock !== null) ? Number(p.stock) : null;
      if (stock !== null) {
        if (stock <= 5) cls = 'stock-low-red';
        else if (stock <= 15) cls = 'stock-low-orange';
      }
      table.push(`<tr class="${cls}"><td style="padding:6px;border:1px solid #eee;">${p.category}</td><td style="padding:6px;border:1px solid #eee;">${p.name}</td><td style="padding:6px;border:1px solid #eee;text-align:right">${p.stock} ks</td></tr>`);
    }
    table.push('</tbody></table></div>');
    stockContainer.innerHTML = table.join('');
  } else {
    stockContainer.innerHTML = '<div style="color:#666">Žádné sledované produkty.</div>';
  }
}

    el.appendChild(topWrap);

    // Mode switch + admin button
    const modeRow = createEl('div', { style: { display: 'flex', gap: '8px', marginBottom: '12px', alignItems: 'center' , flexWrap: 'wrap'}});
    const btnProdej = createEl('button', { class: 'sklad-mode-btn', id: 'mode-prodej' }, 'Prodej');
    const btnVyroba = createEl('button', { class: 'sklad-mode-btn', id: 'mode-vyroba' }, 'Výroba');
    const btnOdpis = createEl('button', { class: 'sklad-mode-btn', id: 'mode-odpis' }, 'Odpis');
    const btnAdmin = createEl('button', { class: 'sklad-mode-btn', id: 'mode-admin' }, 'Sklad');
    [btnProdej, btnVyroba, btnOdpis].forEach(b=>{
      if ((b.id === 'mode-prodej' && mode==='prodej') || (b.id==='mode-vyroba' && mode==='vyroba') || (b.id==='mode-odpis' && mode==='odpis')) {
        b.style.background = '#007cba';
        b.style.color = '#fff';
      } else {
        b.style.background = '#f2f2f2';
      }
      b.style.flexShrink = '0';
    });
    btnProdej.onclick = ()=>{ mode='prodej'; render(); };
    btnVyroba.onclick = ()=>{ mode='vyroba'; render(); };
    btnOdpis.onclick = ()=>{ mode='odpis'; render(); };
    // open admin Sklad in new tab
    btnAdmin.onclick = ()=>{ window.open('/wp-admin/admin.php?page=skladovy-hospodar-produkty-list', '_blank'); };
    modeRow.appendChild(btnProdej); modeRow.appendChild(btnVyroba); modeRow.appendChild(btnOdpis);
    // push admin button to the right
    const spacer = createEl('div', { style: { flex: '1 1 auto' } }, '');
    modeRow.appendChild(spacer);
    modeRow.appendChild(btnAdmin);
    el.appendChild(modeRow);

    // Delegation to mode-specific renderer
    if (mode === 'prodej') renderProdej();
    else if (mode === 'vyroba') renderVyroba();
    else if (mode === 'odpis') renderOdpis();
  }

  // ----------------- PRODEJ (ve stylu Výroba, s multi-select) -----------------
  
  // Funkce pro zobrazení vybraných produktů ve spodní části
function renderSelectedPreview() {
  const node = document.getElementById('prodej-selected');
  if (!node) return; // Pokud element neexistuje, ukonči
  if (selectedOrder.length === 0) {
    node.innerHTML = '<div style="color:#666"><i>Žádné produkty vybrané</i></div>';
    return;
  }

  let html = '<div style="font-weight:700;margin-bottom:8px">Vybrané produkty</div>';
  html += '<div style="background:#fff;padding:8px;border:1px solid #eee;border-radius:6px;"><table style="width:100%">';
  html += '<tbody>';
  selectedOrder.forEach(pid => {
    const product = products.find(p => String(p.id) === String(pid)) || {};
    const entry = cart[pid] || { qty: '', sale: '' }; // Default values, if not found
    html += `
      <tr data-pid="${pid}">
        <td style="padding:8px">${product.name || ('ID ' + pid)}</td>
        <td style="width:120px;text-align:right;padding:8px">
          <input inputmode="numeric" pattern="[0-9]*" type="number" class="selected-preview-qty" 
            data-pid="${pid}" value="${entry.qty !== undefined ? entry.qty : ''}" min="0" 
            style="width:84px;padding:6px;border:1px solid #ddd;border-radius:6px">
        </td>
        <td style="width:80px;text-align:right;padding:8px">
          <button class="button selected-remove" data-pid="${pid}">Odebrat</button>
        </td>
      </tr>`;
  });
  html += '</tbody></table></div>';
  node.innerHTML = html;

  // Změny množství produktů
  document.querySelectorAll('.selected-preview-qty').forEach(input => {
    const pid = String(input.dataset.pid);
    input.addEventListener('input', e => {
      const value = Math.max(0, parseInt(e.target.value || '0', 10));
      if (value > 0) {
        if (!cart[pid]) cart[pid] = {};
        cart[pid].qty = value;

        // Přidání produktu, pokud ještě není ve výběru
        selected.prodej.add(pid);
        addToSelectedOrder(pid);

        // Označení checkboxu v hlavní tabulce (pokud existuje)
        const checkbox = document.querySelector(`.prodej-pick[data-pid="${pid}"]`);
        if (checkbox) checkbox.checked = true;
      } else {
        // Pokud je množství 0, odeber ze seznamu
        delete cart[pid];
        selected.prodej.delete(pid);
        removeFromSelectedOrder(pid);

        const checkbox = document.querySelector(`.prodej-pick[data-pid="${pid}"]`);
        if (checkbox) checkbox.checked = false;
      }

      renderSelectedPreview(); // Obnov náhled
    });
  });

  // Odebrání produktů z náhledu
  document.querySelectorAll('.selected-remove').forEach(button => {
    const pid = String(button.dataset.pid);
    button.addEventListener('click', () => {
      // Odstranění produktu z výběru
      selected.prodej.delete(pid);
      removeFromSelectedOrder(pid);
      delete cart[pid];

      // Aktualizace checkboxu v hlavní tabulce
      const checkbox = document.querySelector(`.prodej-pick[data-pid="${pid}"]`);
      if (checkbox) checkbox.checked = false;

      renderSelectedPreview(); // Obnov náhled
    });
  });
}
  
  function renderCustomerSelect(cartItems, totalFinal) {
  const block = document.getElementById('customer-select-block');
  if (!block) return;
  block.innerHTML = `
    <div>
      <label style="font-weight:600">Zákazník:</label>
      <input id="cust-input" placeholder="Hledat zákazníka..." 
        style="width:260px;padding:6px;border:1px solid #ddd;border-radius:6px;margin-top:6px;" 
        value="${selectedCustomer && !creatingCustomer ? selectedCustomer.name : ''}">
      <div id="cust-results" style="margin-top:6px;max-height:160px;overflow:auto;"></div>
      <div style="margin-top:6px;">
        <button id="cust-create" class="button">Vytvořit zákazníka</button>
      </div>
    </div>
  `;

  const input = document.getElementById('cust-input');
  if (input) {
    input.oninput = e => {
      const query = e.target.value.trim();
      selectedCustomer = null; 
      creatingCustomer = false;

      if (!query) {
        document.getElementById('cust-results').innerHTML = '';
        return;
      }

      sendPost('hospodar_find_customer', { q: query }) // Funkce pro hledání zákazníka přes AJAX
        .then(res => {
          const container = document.getElementById('cust-results');
          if (!container) return;

          container.innerHTML = '';
          if (!Array.isArray(res) || res.length === 0) {
            container.innerHTML = '<div style="color:#666">Žádný zákazník nalezen.</div>';
            return;
          }

          res.forEach(user => {
            const row = createEl('div', { style: { padding: '6px', borderBottom: '1px solid #eee', cursor: 'pointer' } }, 
              `${user.name}${user.email ? ` <span style="color:#999">(${user.email})</span>` : ''}`);
            row.addEventListener('click', () => { 
              selectedCustomer = { id: user.id, name: user.name }; 
              render(); 
            });
            container.appendChild(row);
          });
        });
    };
  }

  const btnCreate = document.getElementById('cust-create');
  if (btnCreate) {
    btnCreate.onclick = () => {
      const name = prompt('Zadejte jméno nového zákazníka:');
      if (!name) return;

      sendPost('hospodar_create_customer', { name }) // AJAX volání pro vytvoření zákazníka
        .then(res => {
          if (res && res.ok) {
            selectedCustomer = { id: res.id, name: res.name };
            render(); // Aktualizace stránky
          } else {
            const errorMessage = res && res.msg ? res.msg : 'Neznámá chyba.';
            showModal(`<div style="color:#b00">Chyba při vytváření zákazníka:<br>${errorMessage}</div>`);
          }
        })
        .catch(err => {
          showModal(`<div style="color:#b00">Chyba při vytváření zákazníka:<br>${String(err)}</div>`);
        });
    };
  }
}
  
function renderProdej() {
  const container = createEl('div', { style: { maxWidth: '920px', margin: '0 auto' } });
  container.innerHTML = `
    <h3>Prodej</h3>
    <div style="color:#666;margin-bottom:8px">Vyberte produkty k prodeji, zadejte počet kusů a potvrďte objednávku.</div>
    <div style="margin-bottom:8px;">
      <input id="sh-search" placeholder="Hledat produkt..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px" value="${search || ''}">
    </div>
    <div id="prodej-list"></div>
    <div id="prodej-selected" style="margin-top:10px;"></div>
    <div style="margin-top:10px">
      <label>Sleva na celý nákup (%): 
        <input id="cart-sale-input" type="number" min="0" max="99" value="${cart_sale}" style="width:64px;padding:6px;border:1px solid #ddd;border-radius:6px">
      </label>
      <div id="cart-actions" style="display:inline-block;margin-left:10px"></div>
    </div>
  `;
  el.appendChild(container);

let debounceTimeout; // Proměnná pro debounce
document.getElementById('sh-search')?.addEventListener('input', e => {
    clearTimeout(debounceTimeout); // Zruší předchozí timeout
    const value = e.target.value;
    debounceTimeout = setTimeout(() => { // Nastaví nový timeout
        if (search !== value) {
            search = value;
            renderProdej(); // Zavolá renderProdej až po 300 ms
        }
    }, 300);
});

  const allProducts = products.slice(); // Načítáme produkty z AJAX volání
  const allCats = categories || [];
  const catMap = {};
  allCats.forEach(c => catMap[String(c.id)] = c.name);

  const grouped = {};
  allProducts.forEach(p => {
    const cid = (p.cats && p.cats.length) ? String(p.cats[0].id) : '0';
    if (!grouped[cid]) grouped[cid] = [];
    grouped[cid].push(p);
  });
  const catKeys = Object.keys(grouped).sort();

  let html = '<div class="prodej-scrollbox" style="max-height:520px;overflow:auto;border:1px solid #eee;padding:8px;border-radius:8px;background:#fff">';
  for (const k of catKeys) {
    const categoryName = (k === '0') ? 'Bez kategorie' : (catMap[k] || 'Bez kategorie');
    const productsInCategory = grouped[k].filter(p => {
      if (search) return p.name.toLowerCase().includes(search.toLowerCase());
      return true;
    });

    if (!productsInCategory.length) continue;

    html += `<div style="margin-bottom:10px">
      <div style="font-weight:600;margin-bottom:6px">${categoryName}</div>
      <table style="width:100%; border-collapse:collapse;">
        <tbody>`;
    productsInCategory.forEach(p => {
      const stock = (p.stock !== undefined && p.stock !== null) ? p.stock : 0;
      const curQty = (cart[p.id] && cart[p.id].qty) ? cart[p.id].qty : '';
      const checkedAttr = selected.prodej.has(String(p.id)) ? ' checked' : '';

      html += `
        <tr class="prodej-row" data-pid="${p.id}">
          <td style="width:40px;vertical-align:middle">
            <input type="checkbox" class="prodej-pick" data-pid="${p.id}"${checkedAttr}>
          </td>
          <td style="padding:8px 6px;vertical-align:middle">
            <div style="font-weight:600">${p.name}</div>
            <div style="color:#666;font-size:13px;margin-top:6px">Skladem: <b>${stock}</b> ks</div>
          </td>
          <td style="width:240px;text-align:right;padding:8px;">
            <input inputmode="numeric" type="number" class="prodej-qty" data-pid="${p.id}" value="${curQty}" min="0"
              style="width:80px;padding:6px;border:1px solid #ddd;border-radius:6px;margin-right:8px">
          </td>
        </tr>`;
    });
    html += `</tbody></table></div>`;
  }
  html += `</div>`;

  document.getElementById('prodej-list').innerHTML = html;

  // Scroll handling
  const scrollBox = document.querySelector('.prodej-scrollbox');
  if (scrollBox) {
    scrollBox.addEventListener('wheel', function (e) {
      const delta = e.deltaY;
      const atTop = scrollBox.scrollTop === 0;
      const atBottom = scrollBox.scrollTop + scrollBox.clientHeight >= scrollBox.scrollHeight;
      if (!(delta < 0 && atTop) && !(delta > 0 && atBottom)) {
        e.preventDefault();
        scrollBox.scrollTop += delta;
      }
    }, { passive: false });
  }

  // Event listener pro checkboxy a vstupní pole množství
  document.querySelectorAll('.prodej-row').forEach(tr => {
    const pid = String(tr.dataset.pid);
    const checkbox = tr.querySelector('.prodej-pick');
    const qtyInput = tr.querySelector('.prodej-qty');

    // Kliknutí na řádek
    tr.addEventListener('click', (event) => {
      if (event.target.tagName === 'INPUT') return; // Kliknutí přímo na checkbox ignoruj
      if (checkbox) {
        const newChecked = !checkbox.checked;
        checkbox.checked = newChecked;

        if (newChecked) {
          // Přidání produktu do výběru
          selected.prodej.add(pid);
          addToSelectedOrder(pid);

          // Výchozí množství, pokud není nastavena hodnota
          if (!cart[pid] || !cart[pid].qty) {
            cart[pid] = { qty: 1 }; // Nastaví výchozí množství na 1
            if (qtyInput) qtyInput.value = 1;
          }
        } else {
          // Odebrání produktu z výběru
          selected.prodej.delete(pid);
          removeFromSelectedOrder(pid);
          delete cart[pid]; // Vymazání z košíku
          if (qtyInput) qtyInput.value = ''; // Vymazání vstupu množství
        }

        renderSelectedPreview(); // Obnov náhled
      }
    });

    // Změna přímo na checkboxu
    if (checkbox) {
      checkbox.addEventListener('change', () => {
        if (checkbox.checked) {
          selected.prodej.add(pid);
          addToSelectedOrder(pid);

          // Aktualizace nebo přidání do košíku
          if (!cart[pid] || !cart[pid].qty) {
            cart[pid] = { qty: 1 };
            if (qtyInput) qtyInput.value = 1;
          }
        } else {
          selected.prodej.delete(pid);
          removeFromSelectedOrder(pid);
          delete cart[pid];
          if (qtyInput) qtyInput.value = '';
        }

        renderSelectedPreview(); // Aktualizace náhledu
      });
    }

    // Změna množství
    if (qtyInput) {
      qtyInput.addEventListener('input', (event) => {
        const value = Math.max(0, parseInt(event.target.value || '0', 10)); // Zabezpečení proti záporné hodnotě
        if (value > 0) {
          if (!cart[pid]) cart[pid] = { qty: value };
          else cart[pid].qty = value;

          // Přidání produktu, pokud ještě není vybrán
          selected.prodej.add(pid);
          addToSelectedOrder(pid);
          checkbox.checked = true; // Nastavení checkboxu na aktivní
        } else {
          // Pokud je množství 0, produkt se odebere z výběru
          delete cart[pid];
          selected.prodej.delete(pid);
          removeFromSelectedOrder(pid);
          checkbox.checked = false; // Nastavení checkboxu na neaktivní
        }

        renderSelectedPreview(); // Aktualizace náhledu
      });
    }
  });

  // Akce pro potvrzení a vyčištění
 // Akce pro potvrzení a vyčištění
const actionsBlock = document.getElementById('cart-actions');
if (actionsBlock) {
  actionsBlock.innerHTML = `
    <button id="cart-confirm" class="button button-primary" style="margin-right:6px;">Vytvořit objednávku</button>
    <button id="cart-clear" class="button">Vyčistit</button>
    <div id="customer-select-block" style="display:inline-block;margin-left:12px"></div> <!-- Zákazník -->
  `;
  document.getElementById('cart-clear').onclick = () => {
    cart = {}; cart_sale = 0; selected.prodej.clear(); selectedOrder.length = 0; render();
  };
  document.getElementById('cart-confirm').onclick = onCreateOrder;

  // Zákazník
  setTimeout(() => renderCustomerSelect([], 0), 20);
}

  // Náhled vybraných produktů
  renderSelectedPreview();
}

  // ----------------- VÝROBA (preview + confirm) -----------------
  function renderVyroba() {
    const allProducts = (typeof window.skladovyHospodarAllWooProducts !== 'undefined') ? window.skladovyHospodarAllWooProducts : products;
    const allCats = (typeof window.skladovyHospodarAllWooCategories !== 'undefined') ? window.skladovyHospodarAllWooCategories : categories;
    const catMap = {};
    allCats.forEach(c => catMap[String(c.id)] = c.name);

    const container = createEl('div', { style: { maxWidth: '920px', margin: '0 auto' }});
    container.innerHTML = `
      <h3>Výroba</h3>
      <div style="color:#666;margin-bottom:8px">Vyberte produkty k výrobě, zadejte kusy/hmotnost a spočítejte suroviny.</div>
      <div id="vyroba-list"></div>
<div style="margin-top:10px">
  <button id="vyroba-calc" class="button">Spočítat suroviny</button>
</div>
      <div id="vyroba-result" style="margin-top:12px"></div>
      <button id="vyroba-confirm" class="button button-primary" style="display:none;margin-top:10px">Potvrdit výrobu</button>
    `;
    el.appendChild(container);

    // group by category to display
    const grouped = {};
    allProducts.forEach(p => {
      const cid = (p.cats && p.cats.length) ? String(p.cats[0].id) : '0';
      if (!grouped[cid]) grouped[cid] = [];
      grouped[cid].push(p);
    });
    const catKeys = Object.keys(grouped).sort((a,b) => {
      const na = (a==='0') ? 'Bez kategorie' : (catMap[a] || 'Bez kategorie');
      const nb = (b==='0') ? 'Bez kategorie' : (catMap[b] || 'Bez kategorie');
      return na.localeCompare(nb,'cs',{sensitivity:'base'});
    });

    // build list HTML
    let html = '<div class="vyroba-scrollbox" style="max-height:420px;overflow:auto;border:1px solid #eee;padding:8px;border-radius:8px;background:#fff">';
    for (const k of catKeys) {
      const cname = (k==='0') ? 'Bez kategorie' : (catMap[k] || 'Bez kategorie');
      html += `<div style="margin-bottom:10px"><div style="font-weight:600;margin-bottom:6px">${cname}</div><table style="width:100%"><tbody>`;
      grouped[k].forEach(p=>{
        const rozpad = productMap && productMap[p.id] ? productMap[p.id] : [];
        // decide whether any part requires per_kg (use inference that checks itemsMap)
        const perKg = rozpad.some(r => inferMode(r) === 'per_kg');
        const checkedAttr = selected.vyroba.has(String(p.id)) ? ' checked' : '';
        // Pozn.: hmotnost je text, aby mobilní zařízení nabídlo klasickou klávesnici (s čárkou)
        html += `<tr class="vyroba-row" data-pid="${p.id}">
          <td style="width:40px;vertical-align:middle"><input type="checkbox" class="vyroba-pick" data-pid="${p.id}"${checkedAttr}></td>
          <td style="padding:6px">${p.name}</td>
          <td style="text-align:right;padding-right:6px">
            <input inputmode="numeric" pattern="[0-9]*" type="number" class="vyroba-qty" data-pid="${p.id}" value="1" min="0" style="width:64px;margin-right:6px;padding:6px;border:1px solid #ddd;border-radius:6px">
            ${perKg ? `<input type="text" class="vyroba-hmotnost" data-pid="${p.id}" value="1.00" style="width:86px;padding:6px;border:1px solid #ddd;border-radius:6px">` : ''}
          </td>
        </tr>`;
      });
      html += '</tbody></table></div>';
    }
    html += '</div>';
    document.getElementById('vyroba-list').innerHTML = html;

    // wheel handler for scrollbox
const scrollBox = document.querySelector('.vyroba-scrollbox');
if (scrollBox) {
  scrollBox.addEventListener('wheel', function(e) {
    // Prevent scroll propagation to the outer page if mouse is over scrollBox
    if (scrollBox.contains(e.target)) {
      const delta = e.deltaY;
      const atTop = scrollBox.scrollTop === 0;
      const atBottom = scrollBox.scrollTop + scrollBox.clientHeight >= scrollBox.scrollHeight;
      if (!(delta < 0 && atTop) && !(delta > 0 && atBottom)) {
        e.preventDefault();
        scrollBox.scrollTop += delta;
      }
    }
  }, { passive: false });
}

    // toggle row click + checkbox change behavior and preview handling
    setTimeout(()=> {
      document.querySelectorAll('.vyroba-row').forEach(tr=>{
        const pid = tr.dataset.pid;
        const cb = tr.querySelector('.vyroba-pick');
        const qtyEl = tr.querySelector('.vyroba-qty');
        const hEl = tr.querySelector('.vyroba-hmotnost');
        tr.addEventListener('click', (ev) => {
          if (ev.target.tagName === 'INPUT') return;
          if (cb) {
            const newChecked = !cb.checked;
            cb.checked = newChecked;
            if (newChecked) selected.vyroba.add(String(pid)); else selected.vyroba.delete(String(pid));
            if (newChecked && qtyEl) focusAndSelectNumeric(qtyEl);
          }
        });
        if (cb) {
          cb.addEventListener('change', () => {
            if (cb.checked) selected.vyroba.add(String(pid)); else selected.vyroba.delete(String(pid));
            if (cb.checked && qtyEl) {
              setTimeout(()=> focusAndSelectNumeric(qtyEl), 10);
            }
          });
        }
        if (qtyEl) {
          qtyEl.addEventListener('input', (e)=> {
            const v = Math.max(0, parseInt(e.target.value || 0, 10) || 0);
            if (v > 0) selected.vyroba.add(String(pid));
          });
        }
        // Pozn.: vyroba-hmotnost nesetřídíme na numeric fokus, necháváme klasickou klávesnici
        if (hEl) {
          // we still validate/parse later when reading values (replace ',' with '.')
        }
      });

let debounceTimeout; // Proměnná pro debounce
document.getElementById('sh-search')?.addEventListener('input', e => {
    clearTimeout(debounceTimeout); // Zruší předchozí timeout
    const value = e.target.value;
    debounceTimeout = setTimeout(() => { // Nastaví nový timeout
        if (search !== value) {
            search = value;
            renderVyroba(); // Lokální render pouze sekce Výroba
        }
    }, 300);
});

document.getElementById('vyroba-calc').onclick = () => {
  const selectedList = [];
  // Sbírej vybrané produkty
  document.querySelectorAll('.vyroba-pick:checked').forEach(cb => {
    const pid = cb.dataset.pid;
    const qtyEl = document.querySelector('.vyroba-qty[data-pid="' + pid + '"]');
    const hEl = document.querySelector('.vyroba-hmotnost[data-pid="' + pid + '"]');
    const qty = qtyEl ? parseInt(qtyEl.value || 0, 10) : 0;
    const rawH = hEl ? String(hEl.value || 0).replace(',', '.') : 0;
    let hmot = hEl ? parseFloat(rawH) : 0;

    // Ošetření NaN hodnoty
    if (!isFinite(hmot)) hmot = 0;

    selectedList.push({ pid: parseInt(pid, 10), qty, hmotnost: hmot });
  });

  if (selectedList.length === 0) {
    showModal('<div>Vyberte alespoň 1 produkt</div>');
    return;
  }

  // Volání preview endpointu
  sendPost('sklad_vyroba_multi_preview', { products: JSON.stringify(selectedList), allow_negative: 1 })
    .then(res => {
      console.debug('Výroba preview - odpověď serveru:', res);
      if (!res || !res.ok) {
        showModal(
          '<div style="color:#b00">Chyba při výpočtu:<pre>' +
            (res && res.raw ? res.raw : JSON.stringify(res, null, 2)) +
            '</pre></div>'
        );
        return;
      }

      // Vytvoř náhled potřebných surovin (modal)
      let html = `<div style="font-weight:700">Spočítané suroviny</div>
        <div style="margin-top:8px"><table style="width:100%">
        <thead><tr><th>Položka</th><th style="text-align:right">Potřeba</th>
        <th style="text-align:right">Skladem</th><th style="text-align:right">Zůstane</th></tr></thead><tbody>`;
res.materials_info.forEach(m => {
    html += `<tr ${
      m.shortage
        ? 'style="color:#a10000;font-weight:700"'
        : m.low
        ? 'style="color:#8a4b00"'
        : ''
    }>
      <td>${m.name}</td>
      <td style="text-align:right">${Math.round(m.used)} ${m.unit}</td>
      <td style="text-align:right">${Math.round(m.before)} ${m.unit}</td>
      <td style="text-align:right">${Math.round(m.after)} ${m.unit}</td></tr>`;
});
      html += `</tbody></table></div>`;

      if (res.has_shortage) {
        html += `<div style="color:#a10000;margin-top:10px;font-weight:700">Upozornění: některé suroviny nejsou skladem.</div>`;
      }

      html += `<div style="margin-top:12px;text-align:right">
        <button id="vyroba-confirm-modal" class="button button-primary" style="margin-right:8px">Potvrdit výrobu</button>
      </div>`;

      showModal(html);

      // Bind event pro potvrzení výroby
      const confirmBtn = document.getElementById('vyroba-confirm-modal');
      if (confirmBtn) {
        confirmBtn.addEventListener('click', () => {
          hideModal();
          sendPost('sklad_vyroba_multi_frontend', { products: JSON.stringify(selectedList), allow_negative: 1 })
            .then(res2 => {
              console.debug('Výroba confirm - odpověď serveru:', res2);
              if (res2 && res2.ok) {
                let html2 = `<div style="color:green;font-weight:700">Výroba zapsána</div>`;
                if (res2.resume && Array.isArray(res2.resume)) {
                  html2 += '<div style="margin-top:8px"><ul>';
                  res2.resume.forEach(r => (html2 += '<li>' + r + '</li>'));
                  html2 += '</ul></div>';
                }
                showModal(html2);
                loadInitialData().then(() => render());
              } else {
                showModal(
                  '<div style="color:#b00">Chyba při výrobě:<pre>' +
                    (res2 && res2.raw ? res2.raw : JSON.stringify(res2)) +
                    '</pre></div>'
                );
              }
            });
        });
      }
    });
};
    }, 20);
  }

  // ----------------- ODPIS -----------------
  function renderOdpis() {
    const wrap = createEl('div', { style: { maxWidth: '920px', margin: '0 auto' }});
    wrap.innerHTML = `
      <h3>Odpis</h3>
      <div style="color:#666;margin-bottom:8px">Vyberte produkty k odepsání a zadejte počet kusů.</div>
      <div id="odpis-list"></div>
      <div style="margin-top:10px"><input id="odpis-note" placeholder="Poznámka" style="width:60%;padding:6px;border:1px solid #ddd;border-radius:6px"><button id="odpis-show" class="button" style="margin-left:8px">Zobrazit změny</button></div>
      <div id="odpis-summary" style="margin-top:12px"></div>
      <button id="odpis-confirm" class="button button-primary" style="display:none;margin-top:10px">Potvrdit odpis</button>
    `;
    el.appendChild(wrap);

    // build list grouped by category similar to earlier
    const grouped = {};
    products.forEach(p=>{
      const cid = (p.cats && p.cats.length) ? String(p.cats[0].id) : '0';
      if (!grouped[cid]) grouped[cid] = [];
      grouped[cid].push(p);
    });
    const catKeys = Object.keys(grouped).sort();

    let html = '<div class="odpis-scrollbox" style="max-height:420px;overflow:auto;border:1px solid #eee;padding:8px;border-radius:8px;background:#fff">';
    for (const k of catKeys) {
      const name = (typeof window.skladovyHospodarAllWooCategories !== 'undefined') ? ((window.skladovyHospodarAllWooCategories.find(c=>String(c.id)===k)||{}).name || 'Bez kategorie') : 'Bez kategorie';
      html += `<div style="margin-bottom:8px"><div style="font-weight:600;margin-bottom:6px">${name}</div><table style="width:100%"><tbody>`;
      grouped[k].forEach(p=>{
        const checkedAttr = selected.odpis.has(String(p.id)) ? ' checked' : '';
        html += `<tr class="odpis-row" data-pid="${p.id}">
          <td style="width:40px"><input type="checkbox" class="odpis-pick" data-pid="${p.id}"${checkedAttr}></td>
          <td>${p.name}</td>
          <td style="text-align:right"><input inputmode="numeric" pattern="[0-9]*" type="number" class="odpis-qty" data-pid="${p.id}" value="1" min="0" style="width:78px;margin-right:8px"> Skladem: <b>${p.stock}</b></td>
        </tr>`;
      });
      html += '</tbody></table></div>';
    }
    html += '</div>';
    document.getElementById('odpis-list').innerHTML = html;

    // wheel handler
    const scrollBox = document.querySelector('.odpis-scrollbox');
    if (scrollBox) {
      scrollBox.addEventListener('wheel', function(e) {
        const delta = e.deltaY;
        const atTop = scrollBox.scrollTop === 0;
        const atBottom = scrollBox.scrollTop + scrollBox.clientHeight >= scrollBox.scrollHeight - 1;
        if ((delta < 0 && !atTop) || (delta > 0 && !atBottom)) {
          e.preventDefault(); e.stopPropagation();
          scrollBox.scrollTop += delta;
        }
      }, { passive: false });
    }

    // show summary event
    setTimeout(()=> {
      document.querySelectorAll('.odpis-row').forEach(tr=>{
        const pid = tr.dataset.pid;
        const cb = tr.querySelector('.odpis-pick');
        const qtyEl = tr.querySelector('.odpis-qty');
        tr.addEventListener('click', (ev) => {
          if (ev.target.tagName === 'INPUT') return;
          if (cb) {
            const newChecked = !cb.checked;
            cb.checked = newChecked;
            if (newChecked) selected.odpis.add(String(pid)); else selected.odpis.delete(String(pid));
            if (newChecked && qtyEl) focusAndSelectNumeric(qtyEl);
          }
        });
        if (cb) {
          cb.addEventListener('change', () => {
            if (cb.checked) selected.odpis.add(String(pid)); else selected.odpis.delete(String(pid));
            if (cb.checked && qtyEl) {
              setTimeout(()=> focusAndSelectNumeric(qtyEl), 10);
            }
          });
        }
      });

let debounceTimeout; // Proměnná pro debounce
document.getElementById('sh-search')?.addEventListener('input', e => {
    clearTimeout(debounceTimeout); // Zruší předchozí timeout
    const value = e.target.value;
    debounceTimeout = setTimeout(() => { // Nastaví nový timeout
        if (search !== value) {
            search = value;
            renderOdpis(); // Lokální render pouze sekce Odpis
        }
    }, 300);
});

      document.getElementById('odpis-show').onclick = ()=> {
        const selectedList = [];
        document.querySelectorAll('.odpis-pick:checked').forEach(cb=>{
          const pid = cb.dataset.pid;
          const qtyEl = document.querySelector('.odpis-qty[data-pid="'+pid+'"]');
          const qty = qtyEl ? toNumber(qtyEl.value) : 0;
          const p = products.find(x=>String(x.id)===String(pid)) || {};
          selectedList.push({ id: String(pid), qty: qty, name: p.name || ('ID '+pid) });
        });
        if (selectedList.length === 0) {
          document.getElementById('odpis-summary').innerHTML = '<i>Vyberte produkty.</i>';
          document.getElementById('odpis-confirm').style.display = 'none';
          return;
        }
        let html = '<h4>Přehled odpisu</h4><ul>';
        selectedList.forEach(s => {
          const p = products.find(x=>String(x.id)===String(s.id)) || {};
          const before = toNumber(p.stock);
          const after = Math.max(0, before - toNumber(s.qty));
          html += `<li>${p.name || s.name}: ${before} → ${after} (−${s.qty} ks)</li>`;
        });
        html += '</ul>';
        document.getElementById('odpis-summary').innerHTML = html;
        const confirmBtn = document.getElementById('odpis-confirm');
        confirmBtn.style.display = '';
        confirmBtn.onclick = () => {
          confirmBtn.disabled = true;
          confirmBtn.textContent = 'Probíhá...';
          const note = document.getElementById('odpis-note').value || '';
          sendPost('hospodar_odpis', { items: JSON.stringify(selectedList), note })
          .then(res => {
            confirmBtn.disabled = false; confirmBtn.textContent = 'Potvrdit odpis';
            if (res && res.ok) {
              let html = '<div style="color:green;font-weight:700">Odpis proveden</div>';
              if (res.resume && Array.isArray(res.resume)) {
                html += '<div style="margin-top:8px"><ul>';
                res.resume.forEach(r=> html += '<li>'+r+'</li>');
                html += '</ul></div>';
              }
              showModal(html);
              loadInitialData().then(()=> render());
            } else {
              showModal('<div style="color:#b00">Chyba při odpisu:<pre>' + (res && res.raw ? res.raw : JSON.stringify(res,null,2)) + '</pre></div>');
            }
          });
        };
      };
    }, 10);
  }

  // ---------- Customer select + helpers (shared) ----------
  let customerSearchTimeout = null;
  function renderCustomerSelect(cartItems, totalFinal) {
    const block = document.getElementById('customer-select-block');
    if (!block) return;
    block.innerHTML = `
      <div>
        <label style="font-weight:600">Zákazník:</label>
        <input id="cust-input" placeholder="Hledat zákazníka..." style="width:260px;padding:6px;border:1px solid #ddd;border-radius:6px;margin-top:6px;" value="${selectedCustomer && !creatingCustomer ? selectedCustomer.name : ''}">
        <div id="cust-results" style="margin-top:6px;max-height:160px;overflow:auto;"></div>
        <div style="margin-top:6px;">
          <button id="cust-create" class="button">Vytvořit zákazníka</button>
        </div>
      </div>
    `;
    const input = document.getElementById('cust-input');
    if (input) {
      input.oninput = e => {
        const q = e.target.value.trim();
        selectedCustomer = null;
        creatingCustomer = false;
        if (customerSearchTimeout) clearTimeout(customerSearchTimeout);
        if (!q) { document.getElementById('cust-results').innerHTML = ''; return; }
        customerSearchTimeout = setTimeout(()=> {
          sendPost('hospodar_find_customer', { q })
          .then(res => {
            const container = document.getElementById('cust-results');
            if (!container) return;
            container.innerHTML = '';
            if (!Array.isArray(res) || res.length === 0) {
              container.innerHTML = '<div style="color:#666">Žádný zákazník.</div>';
              return;
            }
            res.forEach(u=>{
              const d = createEl('div', { style: { padding: '6px', borderBottom:'1px solid #eee', cursor:'pointer' } }, `${u.name} ${u.email ? '<span style="color:#999">('+u.email+')</span>' : ''}`);
              d.addEventListener('click', ()=>{ selectedCustomer = { id: u.id, name: u.name }; render(); });
              container.appendChild(d);
            });
          });
        }, 250);
      };
    }
    const btnCreate = document.getElementById('cust-create');
    if (btnCreate) {
     btnCreate.onclick = ()=> {
    const name = prompt('Zadejte jméno nového zákazníka:');
    if (!name) return;
    sendPost('hospodar_create_customer', { name })
    .then(res => {
        if (res && res.ok) {
            selectedCustomer = { id: res.id, name: res.name };
            render();
        } else {
            const errorMessage = res && res.msg ? res.msg : (res.raw || 'Neznámá chyba');
            showModal('<div style="color:#b00">Chyba při vytváření zákazníka:<div style="margin-top:8px;">' + errorMessage + '</div></div>');
        }
    })
    .catch(err => {
        showModal('<div style="color:#b00">Chyba při vytváření zákazníka:<div style="margin-top:8px;">' + String(err) + '</div></div>');
    });
};
    }
  }

  // ---------- Create order flow ----------
  function onCreateOrder() {
    // gather cart items from cart object
    const cartEntries = Object.entries(cart).map(([id,v])=> {
      const p = products.find(x=>String(x.id)===String(id));
      if (!p) return null;
      const qty = toNumber(v.qty);
      if (!qty || qty <= 0) return null;
      const unit_price = toNumber(p.price);
      return {
        id: String(id),
        qty: qty,
        price: unit_price,
        sale: toNumber(v.sale) || 0,
        name: p.name
      };
    }).filter(Boolean);
    if (cartEntries.length === 0) { showModal('<div style="color:#b00">Košík je prázdný</div>'); return; }
    if (!selectedCustomer || !selectedCustomer.id) { showModal('<div style="color:#b00">Vyberte zákazníka</div>'); return; }

    // prepare data: server expects 'data' param JSON with items etc.
    const cart_total = cartEntries.reduce((s,it)=>s + (it.price * it.qty * (1 - (it.sale/100))),0);
    const payloadData = { items: cartEntries, cart_sale, cart_total, mode: 'prodej', customer_id: selectedCustomer.id };

    // create order
    sendPost('hospodar_create_order', { customer_id: selectedCustomer.id, data: JSON.stringify(payloadData) })
    .then(res => {
      if (res && res.ok) {
        // update stock (plugin's history/notes)
        sendPost('hospodar_update_stock', { data: JSON.stringify({ items: cartEntries.map(i=>({ id: i.id, qty: i.qty, name: i.name })), note: 'Prodej přes Skladový hospodář', cart_total, mode: 'prodej', customer_id: selectedCustomer.id, evidence: 1 }) })
        .then(ures => {
          let html = '<div style="color:green;font-weight:bold">Objednávka vytvořena</div>';
          if (ures && ures.resume) {
            html += '<div style="margin-top:8px"><b>Souhrn změn skladu:</b><ul>';
            ures.resume.forEach(r=> html += '<li>'+r+'</li>');
            html += '</ul></div>';
          }
          // If backend returned structured products, show grouped view
          if (ures && Array.isArray(ures.produkty) && ures.produkty.length) {
            const grouped = {};
            ures.produkty.forEach(p => {
              const cat = p.category || 'Bez kategorie';
              if (!grouped[cat]) grouped[cat] = { items: [], qty: 0 };
              grouped[cat].items.push(p);
              grouped[cat].qty += Number(p.qty || p.vyrobeno || 0);
            });
            html += '<div style="margin-top:8px"><b>Prodáno podle kategorií:</b>';
            Object.keys(grouped).sort().forEach(cat => {
              html += `<div style="margin-top:10px"><div style="font-weight:700">${cat} — celkem: ${grouped[cat].qty} ks</div><ul style="margin:6px 0 0 18px">`;
              grouped[cat].items.forEach(it => {
                const qty = it.vyrobeno || it.qty || 0;
                const before = (typeof it.before !== 'undefined' ? it.before : (typeof it.stav_pred !== 'undefined' ? it.stav_pred : ''));
                const after = (typeof it.after !== 'undefined' ? it.after : (typeof it.stav_po !== 'undefined' ? it.stav_po : ''));
                html += `<li>${it.name || ('ID ' + it.pid)}: ${before !== '' ? before + ' → ' + after + ' ' : ''}(−${qty} ks)</li>`;
              });
              html += '</ul></div>';
            });
            html += '</div>';
          }
          showModal(html);
          // reset cart & selection
          cart = {}; cart_sale = 0; selectedCustomer = null; selected.prodej.clear(); selectedOrder.length = 0;
          // reload products (stock may have changed)
          loadInitialData().then(()=> render());
        });
      } else {
        showModal('<div style="color:#b00">Chyba při vytváření objednávky:<pre>' + (res && res.raw ? res.raw : JSON.stringify(res)) + '</pre></div>');
      }
    });
  }

  // ---------- Initialization ----------
  loadInitialData()
    .then(()=> {
      // also take items and productMap from global if present
      if (typeof window.skladovyHospodarItems !== 'undefined') itemsMap = window.skladovyHospodarItems;
      if (typeof window.skladovyHospodarProductMap !== 'undefined') productMap = window.skladovyHospodarProductMap;
      render();
    })
    .catch(err => {
      // show error and still render minimal UI
      showModal('<div style="color:#b00">Chyba při načítání dat: '+String(err)+'</div>');
      render();
    });

  // Expose small debug on window for manual testing
  window._sh_debug = {
    reinit: () => { loadInitialData().then(()=> render()); },
    sendPost,
    productsRef: () => products,
    itemsRef: () => itemsMap,
    productMapRef: () => productMap,
    selected,
    selectedOrder,
    cart
  };
});