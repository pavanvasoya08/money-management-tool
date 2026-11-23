'use strict';
/* =====================================================================
   PartnerLedger — Multi-Partner Business Finance Management (DEMO)
   ---------------------------------------------------------------------
   Single-page app in vanilla JavaScript. All records are fictional and
   are stored only in this browser's LocalStorage. There is no backend,
   no real bank integration and no real GST / marketplace API.
   ===================================================================== */

const STORE_KEY = 'partnerledger_demo_v1';
const DATA_VERSION = 1;

/* =====================================================================
   1. Generic helpers
   ===================================================================== */
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const inrFmt = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 });
const numFmt = new Intl.NumberFormat('en-IN');
const fmt = n => inrFmt.format(Math.round(n || 0));
const num = n => numFmt.format(n || 0);
const pct = n => (isFinite(n) ? n.toFixed(1) : '0.0') + '%';
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const sum = (arr, f) => arr.reduce((a, x) => a + (f ? f(x) : x), 0);
const round2 = n => Math.round(n * 100) / 100;

function fmtCompact(n) {
  const a = Math.abs(n || 0), s = n < 0 ? '-' : '';
  if (a >= 1e7) return `${s}₹${(a / 1e7).toFixed(2)} Cr`;
  if (a >= 1e5) return `${s}₹${(a / 1e5).toFixed(2)} L`;
  if (a >= 1e3) return `${s}₹${(a / 1e3).toFixed(1)}K`;
  return `${s}₹${Math.round(a)}`;
}
function isoDate(d) { return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; }
function parseISO(s) { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); }
function todayISO() { return isoDate(new Date()); }
function addDays(iso, n) { const d = parseISO(iso); d.setDate(d.getDate() + n); return isoDate(d); }
function fmtDate(s) { if (!s) return '—'; return parseISO(s).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }); }
function fmtDateShort(s) { return parseISO(s).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }); }
function inRange(d, R) { return (!R.from || d >= R.from) && (!R.to || d <= R.to); }
function initials(name) { return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join(''); }
function icons() { if (window.lucide && window.lucide.createIcons) { try { window.lucide.createIcons(); } catch (e) { /* ignore */ } } }
function randDigits(n) { return Array.from({ length: n }, () => Math.floor(Math.random() * 10)).join(''); }

/* =====================================================================
   2. Data store (LocalStorage)
   ===================================================================== */
let DB = null;

function loadDB() {
  try {
    const raw = localStorage.getItem(STORE_KEY);
    if (raw) { const d = JSON.parse(raw); if (d && d.version === DATA_VERSION) return d; }
  } catch (e) { console.warn('Could not read saved demo data', e); }
  const d = buildSeedData();
  persist(d);
  return d;
}
function persist(d = DB) { try { localStorage.setItem(STORE_KEY, JSON.stringify(d)); } catch (e) { console.warn('Could not save demo data', e); } }
function nextId(prefix) { DB.counters[prefix] = (DB.counters[prefix] || 0) + 1; return `${prefix}-${String(DB.counters[prefix]).padStart(3, '0')}`; }
function find(col, id) { return DB[col].find(x => x.id === id); }
function removeById(col, id) { DB[col] = DB[col].filter(x => x.id !== id); }
/** Save + re-render everything that depends on the data. */
function commit(msg) { persist(); renderRoute(true); if (msg) toast(msg); }

/* ---------- Lookups ---------- */
const partnerName = id => find('partners', id)?.name || '—';
const gstOf = id => find('gsts', id);
const gstLabel = id => gstOf(id)?.gstin || '—';
const gstBiz = id => gstOf(id)?.businessName || '—';
const accName = id => find('accounts', id)?.name || '—';
const platName = id => find('platforms', id)?.name || '—';
const prodName = id => find('products', id)?.name || '—';
const accountsOfGst = gid => DB.accounts.filter(a => a.gstId === gid);
const gstAccountId = gid => accountsOfGst(gid)[0]?.id || '';
const platformsOfGst = gid => DB.platforms.filter(p => (p.gstIds || []).includes(gid));

/* =====================================================================
   3. Business calculations
   ===================================================================== */
const saleActive = s => s.status !== 'Cancelled';
const returnActive = r => r.status !== 'Rejected';

/** Selling price is GST-inclusive (standard for Indian marketplaces). */
function saleCalc(s) {
  const gross = s.price * s.qty;
  const gst = gross * s.gstRate / (100 + s.gstRate);
  return { gross, gst, taxable: gross - gst, settlement: gross - (s.commission || 0) - (s.shipping || 0) };
}
function returnCalc(r) {
  const s = find('sales', r.saleId);
  const rate = s ? s.gstRate : 0;
  const gst = r.refund * rate / (100 + rate);
  return { sale: s, gst, taxable: r.refund - gst };
}
/** Purchase unit cost is exclusive of GST (input tax shown separately). */
function purchaseCalc(p) { const amount = p.qty * p.unitCost; const gst = amount * p.gstRate / 100; return { amount, gst, total: amount + gst }; }

function productStats(p) {
  const purchased = sum(DB.purchases.filter(x => x.productId === p.id), x => x.qty);
  const sold = sum(DB.sales.filter(s => s.productId === p.id && saleActive(s)), s => s.qty);
  const rets = DB.returns.filter(r => returnActive(r) && find('sales', r.saleId)?.productId === p.id);
  const returned = sum(rets, r => r.qty);
  const restocked = sum(rets.filter(r => r.restock === 'Restocked'), r => r.qty);
  const stock = p.openingStock + purchased - sold + restocked;
  return { purchased, sold, returned, restocked, stock, value: stock * p.purchasePrice, low: stock <= p.reorderLevel };
}

/** Every money movement through a bank account. Internal transfers are flagged and never count as income/expense. */
function ledgerEntries() {
  const E = [];
  DB.investments.forEach(i => E.push({
    date: i.date, accountId: i.accountId, kind: i.type === 'Contribution' ? 'Partner Contribution' : 'Partner Withdrawal',
    desc: `${partnerName(i.partnerId)} — ${i.desc || i.type}`, ref: i.id,
    credit: i.type === 'Contribution' ? i.amount : 0, debit: i.type === 'Withdrawal' ? i.amount : 0
  }));
  DB.transfers.forEach(t => {
    E.push({ date: t.date, accountId: t.fromId, kind: 'Transfer Out', desc: `To ${accName(t.toId)}${t.desc ? ' — ' + t.desc : ''}`, ref: t.ref || t.id, credit: 0, debit: t.amount, internal: true });
    E.push({ date: t.date, accountId: t.toId, kind: 'Transfer In', desc: `From ${accName(t.fromId)}${t.desc ? ' — ' + t.desc : ''}`, ref: t.ref || t.id, credit: t.amount, debit: 0, internal: true });
  });
  DB.purchases.forEach(p => E.push({ date: p.date, accountId: p.accountId, kind: 'Purchase', desc: `${p.supplier} — ${prodName(p.productId)} × ${p.qty}`, ref: p.id, credit: 0, debit: purchaseCalc(p).total }));
  DB.expenses.forEach(x => E.push({ date: x.date, accountId: x.accountId, kind: 'Expense', desc: `${x.category} — ${x.desc}`, ref: x.id, credit: 0, debit: x.amount }));
  DB.settlements.filter(s => s.status === 'Received').forEach(s => E.push({
    date: s.settledOn || s.to, accountId: s.accountId, kind: 'Settlement', desc: `${platName(s.platformId)} payout (${fmtDateShort(s.from)} – ${fmtDateShort(s.to)})`, ref: s.utr || s.id, credit: s.net, debit: 0
  }));
  return E;
}
function accountStats(a, E = ledgerEntries()) {
  const mine = E.filter(e => e.accountId === a.id);
  const credits = sum(mine, e => e.credit), debits = sum(mine, e => e.debit);
  return { credits, debits, balance: a.opening + credits - debits, entries: mine };
}
function balanceOf(accId) { const a = find('accounts', accId); return a ? accountStats(a).balance : 0; }

/** Marketplace settlement for one platform + GST registration over a period. */
function calcSettlement(platformId, gstId, from, to, db = DB) {
  const sales = db.sales.filter(s => s.platformId === platformId && s.gstId === gstId && saleActive(s) && s.date >= from && s.date <= to);
  const ids = new Set(sales.map(s => s.id));
  const gross = sum(sales, s => s.price * s.qty);
  const returns = sum(db.returns.filter(r => ids.has(r.saleId) && returnActive(r)), r => r.refund);
  const commission = sum(sales, s => s.commission || 0);
  const shipping = sum(sales, s => s.shipping || 0);
  const other = sales.length ? Math.round(gross * 0.005) + sales.length * 10 : 0; // TCS 0.5% + fixed fee per order (demo)
  return { orders: sales.length, gross, returns, commission, shipping, other, net: gross - returns - commission - shipping - other };
}
function isSaleSettled(s) { return DB.settlements.some(t => t.platformId === s.platformId && t.gstId === s.gstId && s.date >= t.from && s.date <= t.to); }

/** Profit & Loss. Inventory purchases are NOT expensed; only the cost of goods actually sold (COGS) is. */
function computePL({ from = '', to = '', gstId = '', platformId = '' } = {}) {
  const R = { from, to };
  const matchSale = s => (!gstId || s.gstId === gstId) && (!platformId || s.platformId === platformId);
  const sales = DB.sales.filter(s => saleActive(s) && inRange(s.date, R) && matchSale(s));
  const grossIncl = sum(sales, s => s.price * s.qty);
  const grossSales = sum(sales, s => saleCalc(s).taxable);
  const outputGst = sum(sales, s => saleCalc(s).gst);
  const rets = DB.returns.filter(r => returnActive(r) && inRange(r.date, R)).filter(r => { const s = find('sales', r.saleId); return s && matchSale(s); });
  const salesReturns = sum(rets, r => returnCalc(r).taxable);
  const netSales = grossSales - salesReturns;
  const costOf = (s, q) => (find('products', s.productId)?.purchasePrice || 0) * q;
  const cogs = sum(sales, s => costOf(s, s.qty)) - sum(rets.filter(r => r.restock === 'Restocked'), r => costOf(find('sales', r.saleId), r.qty));
  const grossProfit = netSales - cogs;
  const commission = sum(sales, s => s.commission || 0), shipping = sum(sales, s => s.shipping || 0);
  const exps = platformId ? [] : DB.expenses.filter(x => inRange(x.date, R) && (!gstId || x.gstId === gstId));
  const mpExpenses = sum(exps.filter(x => x.category === 'Marketplace Commission'), x => x.amount);
  const marketplace = commission + shipping + mpExpenses;
  const opexList = exps.filter(x => x.category !== 'Marketplace Commission' && x.category !== 'Product Purchase');
  const opex = sum(opexList, x => x.amount);
  const opexByCat = {};
  opexList.forEach(x => { opexByCat[x.category] = (opexByCat[x.category] || 0) + x.amount; });
  const inventoryPurchased = sum(DB.purchases.filter(p => inRange(p.date, R) && (!gstId || p.gstId === gstId)), p => purchaseCalc(p).amount)
    + sum(exps.filter(x => x.category === 'Product Purchase'), x => x.amount);
  const net = grossProfit - marketplace - opex;
  return {
    orders: sales.length, grossIncl, grossSales, outputGst, salesReturns, netSales, cogs, grossProfit, commission, shipping, mpExpenses,
    marketplace, opex, opexByCat, inventoryPurchased, net, margin: netSales ? net / netSales * 100 : 0, opexAllocated: !platformId
  };
}

/* ---------- Date ranges ---------- */
const PRESETS = [['today', 'Today'], ['week', 'This Week'], ['month', 'This Month'], ['year', 'This Year'], ['all', 'All Time'], ['custom', 'Custom Range']];
function rangeFor(f) {
  const t = todayISO();
  switch (f.preset) {
    case 'today': return { from: t, to: t };
    case 'week': { const dow = (new Date().getDay() + 6) % 7; return { from: addDays(t, -dow), to: t }; }
    case 'month': return { from: t.slice(0, 8) + '01', to: t };
    case 'year': return { from: t.slice(0, 4) + '-01-01', to: t };
    case 'custom': return { from: f.from || '', to: f.to || '' };
    default: return { from: '', to: '' };
  }
}
function rangeText(R) {
  if (!R.from && !R.to) return 'All time';
  if (R.from === R.to) return fmtDate(R.from);
  return `${R.from ? fmtDate(R.from) : 'Beginning'} – ${R.to ? fmtDate(R.to) : 'Today'}`;
}
function lastMonths(n, endISO = todayISO()) {
  const d = parseISO(endISO), out = [];
  for (let i = n - 1; i >= 0; i--) {
    const m = new Date(d.getFullYear(), d.getMonth() - i, 1);
    out.push({ label: m.toLocaleDateString('en-IN', { month: 'short', year: '2-digit' }), from: isoDate(m), to: isoDate(new Date(m.getFullYear(), m.getMonth() + 1, 0)) });
  }
  return out;
}

/* =====================================================================
   4. UI state, router, rendering infrastructure
   ===================================================================== */
const UI = { route: 'dashboard', filters: {}, tables: {}, pf: {}, plTab: 'monthly', reportType: 'sales' };
const TABLES = {};
let CHARTS = [];
let AFTER = [];

const NAV = [
  { group: 'Overview', items: [['dashboard', 'Dashboard', 'layout-dashboard']] },
  { group: 'Partners & Capital', items: [['partners', 'Partners', 'users'], ['investments', 'Partner Investments', 'piggy-bank']] },
  { group: 'Compliance & Banking', items: [['gst', 'GST Registrations', 'file-badge'], ['banks', 'Bank Accounts', 'landmark'], ['transfers', 'Bank Transfers', 'arrow-left-right']] },
  { group: 'Operations', items: [['platforms', 'Selling Platforms', 'store'], ['products', 'Products & Inventory', 'package'], ['purchases', 'Purchases', 'shopping-cart'], ['sales', 'Sales', 'indian-rupee'], ['returns', 'Returns & Refunds', 'undo-2'], ['expenses', 'Expenses', 'wallet'], ['settlements', 'Settlements', 'handshake']] },
  { group: 'Reports', items: [['pl', 'Profit & Loss', 'scale'], ['reports', 'Financial Reports', 'file-text'], ['settings', 'Settings', 'settings']] }
];
const NAV_FLAT = NAV.flatMap(g => g.items);

function renderNav() {
  $('#nav').innerHTML = NAV.map(g => `<div class="nav-group"><div class="nav-group-title">${g.group}</div>${g.items.map(([id, label, icon]) =>
    `<a href="#${id}" class="nav-link" data-route="${id}"><i data-lucide="${icon}"></i><span>${label}</span></a>`).join('')}</div>`).join('');
}

function renderRoute(keepScroll = false) {
  const r = location.hash.slice(1) || 'dashboard';
  const route = PAGES[r] ? r : 'dashboard';
  const changed = route !== UI.route;
  UI.route = route;
  const y = window.scrollY;
  CHARTS.forEach(c => { try { c.destroy(); } catch (e) { /* ignore */ } });
  CHARTS = []; AFTER = [];
  $$('.nav-link').forEach(a => a.classList.toggle('active', a.dataset.route === route));
  const item = NAV_FLAT.find(i => i[0] === route);
  $('#page-title').textContent = item ? item[1] : 'Dashboard';
  document.title = `${item ? item[1] : 'Dashboard'} · PartnerLedger (Demo)`;
  $('#brand-company').textContent = DB.settings.company;
  $('#demo-banner').classList.toggle('hidden', DB.settings.showBanner === false);
  const content = $('#content');
  content.innerHTML = PAGES[route]();
  AFTER.forEach(fn => { try { fn(); } catch (e) { console.error(e); } });
  icons();
  document.body.classList.remove('nav-open');
  if (keepScroll && !changed) window.scrollTo(0, y); else window.scrollTo(0, 0);
  if (changed) { content.classList.remove('fade-in'); void content.offsetWidth; content.classList.add('fade-in'); }
}
const rerender = () => renderRoute(true);

/* ---------- Small UI builders ---------- */
function pageHead(title, sub, actions = '') {
  return `<div class="page-head"><div><h1>${title}</h1><p>${sub}</p></div><div class="page-actions">${actions}</div></div>`;
}
function btn(label, action, { icon = 'plus', kind = 'primary', id = '', size = '' } = {}) {
  return `<button class="btn btn-${kind} ${size ? 'btn-' + size : ''}" data-action="${action}" ${id ? `data-id="${esc(id)}"` : ''}>${icon ? `<i data-lucide="${icon}"></i>` : ''}<span>${label}</span></button>`;
}
function iconBtn(icon, action, id, title, tone = '') {
  return `<button class="icon-btn ${tone}" title="${title}" aria-label="${title}" data-action="${action}" data-id="${esc(id)}"><i data-lucide="${icon}"></i></button>`;
}
function statCard({ label, value, icon, tone = 'blue', sub = '', go = '', valueTone = '' }) {
  return `<div class="stat-card ${go ? 'clickable' : ''}" ${go ? `data-go="${go}"` : ''}>
    <div class="stat-top"><span class="stat-label">${label}</span><span class="stat-icon tone-${tone}"><i data-lucide="${icon}"></i></span></div>
    <div class="stat-value ${valueTone}">${value}</div>${sub ? `<div class="stat-sub">${sub}</div>` : ''}</div>`;
}
const STATUS_TONE = {
  Delivered: 'green', Shipped: 'blue', Pending: 'amber', Cancelled: 'red', Received: 'green', Refunded: 'green', Requested: 'amber',
  Approved: 'blue', Rejected: 'red', Restocked: 'green', Damaged: 'red', Contribution: 'green', Withdrawal: 'red', Joint: 'navy', Business: 'blue', 'In Transit': 'blue'
};
const badge = (s, tone) => `<span class="badge badge-${tone || STATUS_TONE[s] || 'gray'}">${esc(s)}</span>`;
const money = (n, tone) => `<span class="money ${tone || ''}">${fmt(n)}</span>`;
const moneySigned = n => `<span class="money ${n < 0 ? 'neg' : 'pos'}">${fmt(n)}</span>`;
function panel(title, body, { action = '', icon = '', cls = '' } = {}) {
  return `<section class="panel ${cls}"><div class="panel-head"><h3>${icon ? `<i data-lucide="${icon}"></i>` : ''}${title}</h3>${action}</div><div class="panel-body">${body}</div></section>`;
}
function emptyState(text, icon = 'inbox') { return `<div class="empty"><i data-lucide="${icon}"></i><p>${text}</p></div>`; }
function chips(list) { return list.length ? list.map(x => `<span class="chip">${esc(x)}</span>`).join(' ') : '<span class="muted">—</span>'; }

/* ---------- Page-level filters ---------- */
function getDF(key, def = 'all') { return UI.filters[key] || (UI.filters[key] = { preset: def, from: '', to: '' }); }
function dateFilter(key, def = 'all', pills = false) {
  const f = getDF(key, def);
  const custom = `<span class="df-custom ${f.preset === 'custom' ? '' : 'hidden'}"><input type="date" class="input input-sm" data-df-from="${key}" value="${f.from}"><span class="muted">to</span><input type="date" class="input input-sm" data-df-to="${key}" value="${f.to}"></span>`;
  if (pills) {
    return `<div class="date-filter pills">${PRESETS.filter(p => p[0] !== 'all').map(([v, l]) => `<button class="pill ${f.preset === v ? 'active' : ''}" data-df-pill="${key}" data-v="${v}">${l}</button>`).join('')}${custom}</div>`;
  }
  return `<div class="date-filter"><i data-lucide="calendar"></i><select class="input input-sm" data-df-preset="${key}">${PRESETS.map(([v, l]) => `<option value="${v}" ${f.preset === v ? 'selected' : ''}>${l}</option>`).join('')}</select>${custom}</div>`;
}
function pf(route) { return UI.pf[route] || (UI.pf[route] = {}); }
function filterSelect(route, key, options, placeholder) {
  const v = pf(route)[key] || '';
  return `<select class="input input-sm" data-pf="${route}.${key}"><option value="">${placeholder}</option>${options.map(o => `<option value="${esc(o.value)}" ${o.value === v ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select>`;
}

/* ---------- Option lists ---------- */
const optPartners = () => DB.partners.map(p => ({ value: p.id, label: p.name }));
const optGsts = ids => DB.gsts.filter(g => !ids || ids.includes(g.id)).map(g => ({ value: g.id, label: `${g.businessName} · ${g.gstin}` }));
const optGstsShort = () => DB.gsts.map(g => ({ value: g.id, label: g.businessName }));
const optAccounts = (filter, withBal = true) => { const E = ledgerEntries(); return DB.accounts.filter(filter || (() => true)).map(a => ({ value: a.id, label: withBal ? `${a.name} — ${fmt(accountStats(a, E).balance)}` : a.name })); };
const optPlatforms = () => DB.platforms.map(p => ({ value: p.id, label: p.name }));
const optProducts = () => DB.products.map(p => ({ value: p.id, label: `${p.name} (${p.sku}) — stock ${productStats(p).stock}` }));
const opts = arr => arr.map(x => ({ value: x, label: x }));

/* =====================================================================
   5. Data tables (search, sort, pagination, CSV)
   ===================================================================== */
function table(id, cfg) {
  TABLES[id] = cfg;
  const st = UI.tables[id] || (UI.tables[id] = { q: '', sort: cfg.sort || null, dir: cfg.dir || 'desc', page: 1 });
  const toolbar = cfg.noToolbar ? '' : `<div class="table-toolbar">
      <div class="search"><i data-lucide="search"></i><input type="search" class="input" placeholder="${cfg.placeholder || 'Search…'}" data-tsearch="${id}" value="${esc(st.q)}"></div>
      <div class="tt-filters">${cfg.filters || ''}</div>
      ${cfg.noExport ? '' : `<button class="btn btn-ghost btn-sm" data-action="export-table" data-id="${id}"><i data-lucide="download"></i><span>CSV</span></button>`}
    </div>`;
  return `<div class="table-card ${cfg.cls || ''}">${cfg.title ? `<div class="table-title"><h3>${cfg.title}</h3>${cfg.titleRight || ''}</div>` : ''}${toolbar}<div id="tb-${id}">${tableBody(id)}</div></div>`;
}
const cellVal = (c, r) => (c.val ? c.val(r) : (r[c.key] ?? ''));
function tableRows(id) {
  const cfg = TABLES[id], st = UI.tables[id];
  let rows = cfg.rows.slice();
  if (st.q) {
    const q = st.q.toLowerCase();
    rows = rows.filter(r => cfg.columns.some(c => c.key !== 'actions' && String(c.search ? c.search(r) : cellVal(c, r)).toLowerCase().includes(q)));
  }
  if (st.sort) {
    const c = cfg.columns.find(c => c.key === st.sort);
    if (c) rows.sort((a, b) => {
      const va = cellVal(c, a), vb = cellVal(c, b);
      const r = (typeof va === 'number' && typeof vb === 'number') ? va - vb : String(va).localeCompare(String(vb), 'en', { numeric: true });
      return st.dir === 'asc' ? r : -r;
    });
  }
  return rows;
}
function tableBody(id) {
  const cfg = TABLES[id], st = UI.tables[id];
  const rows = tableRows(id);
  const ps = cfg.pageSize || 10;
  const pages = Math.max(1, Math.ceil(rows.length / ps));
  if (st.page > pages) st.page = pages;
  const slice = cfg.noPaging ? rows : rows.slice((st.page - 1) * ps, st.page * ps);
  const th = cfg.columns.map(c => {
    const sortable = c.key !== 'actions' && c.sort !== false;
    const active = st.sort === c.key;
    return `<th class="${c.cls || ''} ${sortable ? 'sortable' : ''} ${active ? 'sorted' : ''}" ${sortable ? `data-tsort="${id}" data-key="${c.key}"` : ''}>${c.label}${sortable ? `<span class="sort-ind">${active ? (st.dir === 'asc' ? '▲' : '▼') : '↕'}</span>` : ''}</th>`;
  }).join('');
  const body = slice.length ? slice.map(r => `<tr ${cfg.rowAction ? `class="row-click" data-action="${cfg.rowAction}" data-id="${esc(r.id)}"` : ''}>${cfg.columns.map(c =>
    `<td class="${c.cls || ''}" data-label="${esc(c.label)}">${c.html ? c.html(r) : esc(cellVal(c, r))}</td>`).join('')}</tr>`).join('')
    : `<tr><td colspan="${cfg.columns.length}">${emptyState(st.q ? 'No records match your search.' : (cfg.empty || 'No records yet.'))}</td></tr>`;
  const foot = cfg.footer && rows.length ? `<tfoot>${cfg.footer(rows)}</tfoot>` : '';
  const pager = cfg.noPaging ? '' : `<div class="pager"><span>Showing ${rows.length ? (st.page - 1) * ps + 1 : 0}–${Math.min(st.page * ps, rows.length)} of ${rows.length}</span>
    <div class="pager-btns"><button class="icon-btn" data-tpage="${id}" data-p="${st.page - 1}" ${st.page <= 1 ? 'disabled' : ''} aria-label="Previous page"><i data-lucide="chevron-left"></i></button>
    <span class="pager-num">${st.page} / ${pages}</span>
    <button class="icon-btn" data-tpage="${id}" data-p="${st.page + 1}" ${st.page >= pages ? 'disabled' : ''} aria-label="Next page"><i data-lucide="chevron-right"></i></button></div></div>`;
  return `<div class="table-wrap"><table class="data-table"><thead><tr>${th}</tr></thead><tbody>${body}</tbody>${foot}</table></div>${pager}`;
}
function refreshTable(id) { const el = document.getElementById('tb-' + id); if (el) { el.innerHTML = tableBody(id); icons(); } }
function exportTable(id) {
  const cfg = TABLES[id]; if (!cfg) return;
  const cols = cfg.columns.filter(c => c.key !== 'actions');
  const rows = tableRows(id).map(r => cols.map(c => (c.csv ? c.csv(r) : cellVal(c, r))));
  downloadCSV(`${cfg.file || id}-${todayISO()}.csv`, cols.map(c => c.label), rows);
}
function downloadCSV(filename, headers, rows) {
  const cell = v => { const s = typeof v === 'number' ? String(round2(v)) : String(v ?? ''); return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; };
  const csv = [headers, ...rows].map(r => r.map(cell).join(',')).join('\r\n');
  downloadBlob(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }), filename);
  toast(`Exported ${rows.length} rows → ${filename}`);
}
function downloadBlob(blob, filename) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = filename;
  document.body.appendChild(a); a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 800);
}
const T = { // reusable column helpers
  date: (key = 'date') => ({ key, label: 'Date', val: r => r[key] || '', html: r => fmtDate(r[key]) }),
  money: (key, label, fn, tone) => ({ key, label, cls: 'num', val: fn || (r => r[key]), html: r => money((fn || (x => x[key]))(r), typeof tone === 'function' ? tone(r) : tone) }),
  actions: (fn) => ({ key: 'actions', label: '', cls: 'actions', html: fn })
};

/* =====================================================================
   6. Modals, forms, toasts
   ===================================================================== */
function openModal({ title, body, footer = '', size = 'md' }) {
  const root = $('#modal-root');
  root.innerHTML = `<div class="modal-backdrop"><div class="modal modal-${size}" role="dialog" aria-modal="true" aria-label="${esc(title)}">
    <div class="modal-head"><h3>${title}</h3><button class="icon-btn" data-close-modal aria-label="Close"><i data-lucide="x"></i></button></div>
    <div class="modal-body">${body}</div>${footer ? `<div class="modal-foot">${footer}</div>` : ''}</div></div>`;
  document.body.classList.add('modal-open');
  requestAnimationFrame(() => root.querySelector('.modal-backdrop')?.classList.add('show'));
  icons();
  setTimeout(() => root.querySelector('input:not([type=hidden]):not([readonly]), select, textarea')?.focus(), 60);
}
function closeModal() { $('#modal-root').innerHTML = ''; document.body.classList.remove('modal-open'); }

function fieldHTML(f, v) {
  const val = v ?? f.value ?? '';
  const cls = `field ${f.full ? 'full' : ''} ${f.hidden ? 'hidden' : ''}`;
  const req = f.required ? '<em>*</em>' : '';
  const hint = f.hint ? `<small class="hint">${f.hint}</small>` : '';
  const common = `name="${f.name}" id="f-${f.name}" ${f.required ? 'required' : ''} ${f.readonly ? 'readonly' : ''}`;
  let input;
  switch (f.type) {
    case 'select':
      input = `<select class="input" ${common}>${f.placeholder !== false ? `<option value="">${f.placeholder || 'Select…'}</option>` : ''}${(f.options || []).map(o => `<option value="${esc(o.value)}" ${String(o.value) === String(val) ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select>`; break;
    case 'textarea': input = `<textarea class="input" rows="${f.rows || 2}" ${common} placeholder="${esc(f.placeholder || '')}">${esc(val)}</textarea>`; break;
    case 'checks': {
      const arr = Array.isArray(val) ? val : [];
      input = `<div class="check-group">${(f.options || []).map(o => `<label class="check"><input type="checkbox" name="${f.name}" value="${esc(o.value)}" ${arr.includes(o.value) ? 'checked' : ''}><span>${esc(o.label)}</span></label>`).join('') || '<span class="muted">None available</span>'}</div>`; break;
    }
    case 'html': return `<div class="${cls}" id="f-${f.name}">${f.html || ''}</div>`;
    default:
      input = `<input class="input" type="${f.type || 'text'}" ${common} value="${esc(val)}" ${f.min !== undefined ? `min="${f.min}"` : ''} ${f.max !== undefined ? `max="${f.max}"` : ''} ${f.step !== undefined ? `step="${f.step}"` : ''} placeholder="${esc(f.placeholder || '')}" ${f.list ? `list="dl-${f.name}"` : ''}>`
        + (f.list ? `<datalist id="dl-${f.name}">${f.list.map(x => `<option value="${esc(x)}">`).join('')}</datalist>` : '');
  }
  return `<div class="${cls}"><label for="f-${f.name}">${f.label}${req}</label>${f.prefix ? `<div class="input-prefix"><span>${f.prefix}</span>${input}</div>` : input}${hint}<small class="err"></small></div>`;
}
function getFormValues(form, fields) {
  const v = {};
  fields.forEach(f => {
    if (f.type === 'html') return;
    if (f.type === 'checks') { v[f.name] = $$(`input[name="${f.name}"]:checked`, form).map(i => i.value); return; }
    const el = form.elements[f.name]; if (!el) return;
    v[f.name] = f.type === 'number' ? (el.value === '' ? NaN : parseFloat(el.value)) : el.value.trim();
  });
  return v;
}
function setOptions(form, name, options, keep = true, placeholder = 'Select…') {
  const el = form.elements[name]; if (!el) return;
  const cur = el.value;
  el.innerHTML = `<option value="">${placeholder}</option>` + options.map(o => `<option value="${esc(o.value)}">${esc(o.label)}</option>`).join('');
  if (keep && options.some(o => o.value === cur)) el.value = cur;
}
/**
 * Generic modal form. onSubmit(values) may return a string (error shown, modal stays open)
 * or an object {field: message} for field-level errors.
 */
function openForm({ title, fields, values = {}, submitLabel = 'Save', onSubmit, onChange, size = 'md', note = '' }) {
  const body = `<form id="modal-form" class="form-grid" novalidate>${note ? `<div class="form-note full"><i data-lucide="info"></i><span>${note}</span></div>` : ''}${fields.map(f => fieldHTML(f, values[f.name])).join('')}<div class="form-error full hidden"></div></form>`;
  openModal({ title, size, body, footer: `<button class="btn btn-ghost" data-close-modal>Cancel</button><button class="btn btn-primary" type="submit" form="modal-form"><i data-lucide="check"></i><span>${submitLabel}</span></button>` });
  const form = $('#modal-form');
  const handle = e => { if (onChange) onChange(getFormValues(form, fields), form, e?.target?.name); };
  form.addEventListener('input', handle);
  form.addEventListener('change', handle);
  handle();
  form.addEventListener('submit', e => {
    e.preventDefault();
    const v = getFormValues(form, fields);
    $$('.field', form).forEach(el => el.classList.remove('invalid'));
    const errs = {};
    fields.forEach(f => {
      if (f.type === 'html' || f.hidden) return;
      const val = v[f.name];
      if (f.required && (val === '' || (f.type === 'number' && isNaN(val)) || (Array.isArray(val) && !val.length))) errs[f.name] = 'Required';
      else if (f.type === 'number' && !isNaN(val) && f.min !== undefined && val < f.min) errs[f.name] = `Must be at least ${f.min}`;
      else if (f.pattern && val && !f.pattern.test(val)) errs[f.name] = f.patternMsg || 'Invalid format';
    });
    let res = Object.keys(errs).length ? errs : onSubmit(v, form);
    const errBox = $('.form-error', form);
    if (typeof res === 'string') { errBox.textContent = res; errBox.classList.remove('hidden'); return; }
    if (res && typeof res === 'object') {
      Object.entries(res).forEach(([k, m]) => { const el = form.elements[k]; const box = el && (el.closest ? el.closest('.field') : el[0]?.closest('.field')); if (box) { box.classList.add('invalid'); $('.err', box).textContent = m; } });
      errBox.textContent = 'Please fix the highlighted fields.'; errBox.classList.remove('hidden');
      return;
    }
    closeModal();
  });
}
function confirmDialog(title, message, onYes, { label = 'Delete', kind = 'danger' } = {}) {
  openModal({
    title, size: 'sm', body: `<p class="confirm-text">${message}</p>`,
    footer: `<button class="btn btn-ghost" data-close-modal>Cancel</button><button class="btn btn-${kind}" id="confirm-yes">${label}</button>`
  });
  $('#confirm-yes').addEventListener('click', () => { closeModal(); onYes(); });
}
function alertDialog(title, message) {
  openModal({ title, size: 'sm', body: `<p class="confirm-text">${message}</p>`, footer: `<button class="btn btn-primary" data-close-modal>OK</button>` });
}
function toast(msg, type = 'success') {
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.innerHTML = `<i data-lucide="${type === 'error' ? 'circle-alert' : type === 'info' ? 'info' : 'circle-check'}"></i><span>${esc(msg)}</span>`;
  $('#toast-root').appendChild(el); icons();
  requestAnimationFrame(() => el.classList.add('show'));
  setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 300); }, 3200);
}

/* =====================================================================
   7. Charts (Chart.js via CDN, with graceful fallback)
   ===================================================================== */
const COLORS = { navy: '#1e3a8a', blue: '#2563eb', green: '#16a34a', red: '#dc2626', amber: '#f59e0b', violet: '#7c3aed', teal: '#0d9488', slate: '#64748b', pink: '#db2777' };
const SERIES = [COLORS.blue, COLORS.teal, COLORS.violet, COLORS.amber, COLORS.pink, COLORS.navy, COLORS.slate];
function setupChartDefaults() {
  if (!window.Chart) return;
  Chart.defaults.font.family = "'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
  Chart.defaults.font.size = 12;
  Chart.defaults.color = '#64748b';
  Chart.defaults.borderColor = 'rgba(148,163,184,.18)';
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.boxWidth = 8;
  Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
  Chart.defaults.plugins.tooltip.padding = 10;
  Chart.defaults.plugins.tooltip.cornerRadius = 8;
  Chart.defaults.maintainAspectRatio = false;
}
const moneyTooltip = {
  callbacks: {
    label: c => {
      const v = typeof c.parsed === 'object' ? (c.chart.options.indexAxis === 'y' ? c.parsed.x : c.parsed.y) : c.parsed;
      return ` ${c.dataset.label && c.chart.config.type !== 'doughnut' ? c.dataset.label : c.label}: ${fmt(v)}`;
    }
  }
};
const moneyAxis = { ticks: { callback: v => fmtCompact(v) }, grid: { color: 'rgba(148,163,184,.15)' }, border: { display: false } };
const plainAxis = { grid: { display: false }, border: { display: false } };
function chart(canvasId, config) {
  AFTER.push(() => {
    const el = document.getElementById(canvasId); if (!el) return;
    if (!window.Chart) { el.parentElement.innerHTML = `<div class="chart-fallback"><i data-lucide="wifi-off"></i><span>Charts load from the Chart.js CDN — connect to the internet to see this chart.</span></div>`; return; }
    CHARTS.push(new Chart(el, config));
  });
  return `<div class="chart-box"><canvas id="${canvasId}"></canvas></div>`;
}
function chartBar(id, labels, datasets, opt = {}) {
  return chart(id, {
    type: 'bar', data: { labels, datasets: datasets.map(d => ({ borderRadius: 6, maxBarThickness: 34, ...d })) },
    options: { indexAxis: opt.horizontal ? 'y' : 'x', plugins: { tooltip: moneyTooltip, legend: { display: datasets.length > 1, position: 'bottom' } }, scales: opt.horizontal ? { x: moneyAxis, y: plainAxis } : { y: moneyAxis, x: plainAxis } }
  });
}
function chartLine(id, labels, datasets) {
  return chart(id, {
    type: 'line', data: { labels, datasets: datasets.map(d => ({ tension: .35, pointRadius: 3, pointHoverRadius: 5, borderWidth: 2.5, fill: true, ...d })) },
    options: { interaction: { mode: 'index', intersect: false }, plugins: { tooltip: moneyTooltip, legend: { display: datasets.length > 1, position: 'bottom' } }, scales: { y: moneyAxis, x: plainAxis } }
  });
}
function chartDoughnut(id, labels, data, colors) {
  return chart(id, {
    type: 'doughnut', data: { labels, datasets: [{ data, backgroundColor: colors || SERIES, borderWidth: 2, borderColor: '#fff', hoverOffset: 6 }] },
    options: { cutout: '66%', plugins: { tooltip: moneyTooltip, legend: { position: 'bottom' } } }
  });
}
const alpha = (hex, a) => { const n = parseInt(hex.slice(1), 16); return `rgba(${n >> 16},${(n >> 8) & 255},${n & 255},${a})`; };

/* =====================================================================
   8. Pages
   ===================================================================== */
const PAGES = {};

/* ---------------- Dashboard ---------------- */
PAGES.dashboard = () => {
  const f = getDF('dashboard', 'year');
  const R = rangeFor(f);
  const E = ledgerEntries();
  const contrib = sum(DB.investments.filter(i => i.type === 'Contribution'), i => i.amount);
  const withdr = sum(DB.investments.filter(i => i.type === 'Withdrawal'), i => i.amount);
  const accs = DB.accounts.map(a => ({ a, ...accountStats(a, E) }));
  const jointBal = sum(accs.filter(x => x.a.type === 'Joint'), x => x.balance);
  const bizBal = sum(accs.filter(x => x.a.type !== 'Joint'), x => x.balance);
  const sales = DB.sales.filter(s => saleActive(s) && inRange(s.date, R));
  const totalSales = sum(sales, s => s.price * s.qty);
  const totalPurch = sum(DB.purchases.filter(p => inRange(p.date, R)), p => purchaseCalc(p).total);
  const totalExp = sum(DB.expenses.filter(x => inRange(x.date, R)), x => x.amount);
  const totalRet = sum(DB.returns.filter(r => returnActive(r) && inRange(r.date, R)), r => r.refund);
  const pl = computePL(R);

  const cards = [
    statCard({ label: 'Total Partner Investment', value: fmt(contrib - withdr), icon: 'piggy-bank', tone: 'navy', sub: `${fmtCompact(contrib)} contributed · ${fmtCompact(withdr)} withdrawn`, go: 'investments' }),
    statCard({ label: 'Joint Account Balance', value: fmt(jointBal), icon: 'landmark', tone: 'blue', sub: 'Partners’ joint current account', go: 'banks' }),
    statCard({ label: 'Total Business Bank Balance', value: fmt(bizBal), icon: 'building-2', tone: 'teal', sub: `${accs.filter(x => x.a.type !== 'Joint').length} business accounts`, go: 'banks' }),
    statCard({ label: 'Total Sales', value: fmt(totalSales), icon: 'trending-up', tone: 'green', sub: `${sales.length} orders · incl. GST`, go: 'sales', valueTone: 'pos' }),
    statCard({ label: 'Total Purchases', value: fmt(totalPurch), icon: 'shopping-cart', tone: 'violet', sub: 'Inventory bought (incl. GST)', go: 'purchases' }),
    statCard({ label: 'Total Expenses', value: fmt(totalExp), icon: 'wallet', tone: 'red', sub: 'Operating & other expenses', go: 'expenses', valueTone: 'neg' }),
    statCard({ label: 'Total Returns', value: fmt(totalRet), icon: 'undo-2', tone: 'amber', sub: 'Refunds to customers', go: 'returns', valueTone: 'neg' }),
    statCard({ label: pl.net >= 0 ? 'Net Profit' : 'Net Loss', value: fmt(pl.net), icon: pl.net >= 0 ? 'badge-indian-rupee' : 'trending-down', tone: pl.net >= 0 ? 'green' : 'red', sub: `Margin ${pct(pl.margin)} of net sales`, go: 'pl', valueTone: pl.net >= 0 ? 'pos' : 'neg' })
  ].join('');

  // Charts data
  const months = lastMonths(6);
  const mSales = months.map(m => sum(DB.sales.filter(s => saleActive(s) && inRange(s.date, m)), s => s.price * s.qty));
  const mExp = months.map(m => sum(DB.expenses.filter(x => inRange(x.date, m)), x => x.amount));
  const mPL = months.map(m => computePL(m).net);
  const end = R.to || todayISO();
  let start = R.from && R.from > addDays(end, -29) ? R.from : addDays(end, -29);
  if (start > addDays(end, -6)) start = addDays(end, -6);
  const days = []; for (let d = start; d <= end; d = addDays(d, 1)) days.push(d);
  const dRev = days.map(d => sum(DB.sales.filter(s => saleActive(s) && s.date === d), s => s.price * s.qty));
  const byPlat = DB.platforms.map(p => sum(sales.filter(s => s.platformId === p.id), s => s.price * s.qty));
  const byGst = DB.gsts.map(g => sum(sales.filter(s => s.gstId === g.id), s => s.price * s.qty));

  // Side panels
  const bankList = accs.map(x => `<div class="list-row clickable" data-action="statement" data-id="${x.a.id}">
      <div class="lr-icon ${x.a.type === 'Joint' ? 'tone-navy' : 'tone-blue'}"><i data-lucide="${x.a.type === 'Joint' ? 'users' : 'landmark'}"></i></div>
      <div class="lr-main"><b>${esc(x.a.name)}</b><small>${esc(x.a.bank)} · ${x.a.gstId ? esc(gstLabel(x.a.gstId)) : 'Joint account'}</small></div>
      <div class="lr-amt">${moneySigned(x.balance)}<small>${badge(x.a.type)}</small></div></div>`).join('');
  const recentTx = E.slice().sort((a, b) => b.date.localeCompare(a.date)).slice(0, 7).map(e => `<div class="list-row">
      <div class="lr-icon ${e.internal ? 'tone-slate' : e.credit ? 'tone-green' : 'tone-red'}"><i data-lucide="${e.internal ? 'arrow-left-right' : e.credit ? 'arrow-down-left' : 'arrow-up-right'}"></i></div>
      <div class="lr-main"><b title="${esc(e.desc)}">${esc(e.kind)}${e.internal ? ' <span class="tag">Internal</span>' : ''}</b><small>${fmtDateShort(e.date)} · ${esc(accName(e.accountId).split(' –')[0])}</small></div>
      <div class="lr-amt"><span class="money ${e.credit ? 'pos' : 'neg'}">${e.credit ? '+' : '−'}${fmt(e.credit || e.debit)}</span></div></div>`).join('');
  const recentSales = DB.sales.slice().sort((a, b) => b.date.localeCompare(a.date)).slice(0, 6).map(s => `<tr><td><b>${esc(s.orderId)}</b><small class="muted block">${fmtDate(s.date)}</small></td><td>${esc(platName(s.platformId))}</td><td>${esc(prodName(s.productId))} <span class="muted">× ${s.qty}</span></td><td class="num">${money(s.price * s.qty)}</td><td>${badge(s.status)}</td></tr>`).join('');
  const pendingStl = DB.settlements.filter(s => s.status !== 'Received');
  const unsettled = DB.platforms.map(p => ({ p, v: sum(DB.sales.filter(s => s.platformId === p.id && saleActive(s) && !isSaleSettled(s)), s => saleCalc(s).settlement) })).filter(x => x.v > 0);
  const pendingHtml = pendingStl.map(s => `<div class="list-row clickable" data-action="view-settlement" data-id="${s.id}"><div class="lr-icon tone-amber"><i data-lucide="clock"></i></div>
      <div class="lr-main"><b>${esc(s.id)} · ${esc(platName(s.platformId))}</b><small>${esc(gstBiz(s.gstId))} · ${fmtDateShort(s.from)} – ${fmtDateShort(s.to)}</small></div>
      <div class="lr-amt">${money(s.net)}<small>${badge(s.status)}</small></div></div>`).join('')
    + (unsettled.length ? `<div class="mini-title">Not yet in any settlement (estimated)</div>` + unsettled.map(x => `<div class="list-row"><div class="lr-icon" style="background:${alpha(x.p.color, .12)};color:${x.p.color}"><i data-lucide="store"></i></div><div class="lr-main"><b>${esc(x.p.name)}</b><small>Expected payout from open orders</small></div><div class="lr-amt">${money(x.v)}</div></div>`).join('') : '');
  const recentExp = DB.expenses.slice().sort((a, b) => b.date.localeCompare(a.date)).slice(0, 6).map(x => `<div class="list-row"><div class="lr-icon tone-red"><i data-lucide="wallet"></i></div><div class="lr-main"><b>${esc(x.category)}</b><small>${esc(x.desc)}</small></div><div class="lr-amt"><span class="money neg">${fmt(x.amount)}</span><small>${fmtDate(x.date)}</small></div></div>`).join('');
  const low = DB.products.map(p => ({ p, st: productStats(p) })).filter(x => x.st.low);
  const lowHtml = low.length ? low.map(x => `<div class="list-row clickable" data-action="view-product" data-id="${x.p.id}"><div class="lr-icon tone-amber"><i data-lucide="triangle-alert"></i></div><div class="lr-main"><b>${esc(x.p.name)}</b><small>${esc(x.p.sku)} · reorder at ${x.p.reorderLevel}</small></div><div class="lr-amt"><span class="stock-pill ${x.st.stock <= 0 ? 'out' : 'low'}">${x.st.stock} left</span></div></div>`).join('') : emptyState('All products are above reorder level.', 'circle-check');

  return `${pageHead(`Welcome back 👋`, `${esc(DB.settings.company)} · Financial overview for <b>${rangeText(R)}</b>`, `${btn('New Sale', 'add-sale', { kind: 'primary' })}${btn('Add Expense', 'add-expense', { kind: 'ghost', icon: 'wallet' })}`)}
    <div class="toolbar-row">${dateFilter('dashboard', 'year', true)}<span class="muted small">Bank balances are shown as of today.</span></div>
    <div class="stats-grid">${cards}</div>
    <div class="grid g-2-1">
      ${panel('Monthly Sales vs Expenses', chartBar('ch-sve', months.map(m => m.label), [{ label: 'Sales', data: mSales, backgroundColor: COLORS.green }, { label: 'Expenses', data: mExp, backgroundColor: COLORS.red }]), { icon: 'chart-column', action: '<span class="muted small">Last 6 months</span>' })}
      ${panel('Platform-wise Sales', byPlat.some(v => v) ? chartDoughnut('ch-plat', DB.platforms.map(p => p.name), byPlat, DB.platforms.map(p => p.color)) : emptyState('No sales in this period.'), { icon: 'store' })}
    </div>
    <div class="grid g-2-1">
      ${panel('Daily Revenue', chartLine('ch-daily', days.map(fmtDateShort), [{ label: 'Revenue', data: dRev, borderColor: COLORS.blue, backgroundColor: alpha(COLORS.blue, .10) }]), { icon: 'activity', action: `<span class="muted small">${fmtDateShort(start)} – ${fmtDateShort(end)}</span>` })}
      ${panel('GST-wise Sales', byGst.some(v => v) ? chartBar('ch-gst', DB.gsts.map(g => g.businessName), [{ label: 'Sales', data: byGst, backgroundColor: SERIES }], { horizontal: true }) : emptyState('No sales in this period.'), { icon: 'file-badge' })}
    </div>
    <div class="grid g-1">
      ${panel('Profit / Loss Trend', chartLine('ch-pl', months.map(m => m.label), [{ label: 'Net Profit/Loss', data: mPL, borderColor: COLORS.navy, backgroundColor: alpha(COLORS.green, .08), pointBackgroundColor: mPL.map(v => v >= 0 ? COLORS.green : COLORS.red), pointRadius: 5 }]), { icon: 'trending-up', action: '<span class="muted small">Net profit after COGS, marketplace charges & expenses</span>' })}
    </div>
    <div class="grid g-3">
      ${panel('Bank Account Overview', bankList, { icon: 'landmark', action: `<a class="link" href="#banks">All accounts →</a>` })}
      ${panel('Recent Transactions', recentTx || emptyState('No transactions yet.'), { icon: 'arrow-left-right', action: `<a class="link" href="#reports">Reports →</a>` })}
      ${panel('Pending Settlements', pendingHtml || emptyState('Nothing pending.', 'circle-check'), { icon: 'handshake', action: `<a class="link" href="#settlements">Settlements →</a>` })}
    </div>
    <div class="grid g-2-1">
      ${panel('Recent Sales', `<div class="table-wrap"><table class="data-table compact"><thead><tr><th>Order</th><th>Platform</th><th>Product</th><th class="num">Value</th><th>Status</th></tr></thead><tbody>${recentSales}</tbody></table></div>`, { icon: 'indian-rupee', action: `<a class="link" href="#sales">All sales →</a>` })}
      ${panel('Recent Expenses', recentExp || emptyState('No expenses yet.'), { icon: 'wallet', action: `<a class="link" href="#expenses">All expenses →</a>` })}
    </div>
    <div class="grid g-1">${panel(`Low Stock Alerts ${low.length ? `<span class="count-badge">${low.length}</span>` : ''}`, `<div class="list-cols">${lowHtml}</div>`, { icon: 'triangle-alert', action: `<a class="link" href="#products">Inventory →</a>` })}</div>`;
};

/* ---------------- Partners ---------------- */
function partnerStats(p) {
  const inv = DB.investments.filter(i => i.partnerId === p.id);
  const initial = sum(inv.filter(i => i.type === 'Contribution' && i.initial), i => i.amount);
  const contrib = sum(inv.filter(i => i.type === 'Contribution'), i => i.amount);
  const withdr = sum(inv.filter(i => i.type === 'Withdrawal'), i => i.amount);
  return { initial, contrib, withdr, capital: contrib - withdr, count: inv.length };
}
PAGES.partners = () => {
  const plAll = computePL();
  const rows = DB.partners.map(p => ({ ...p, ...partnerStats(p), profitShare: plAll.net * p.share / 100, gstCount: DB.gsts.filter(g => g.partnerId === p.id).length }));
  const totalShare = sum(DB.partners, p => +p.share || 0);
  const totCap = sum(rows, r => r.capital);
  return `${pageHead('Partners', 'Capital accounts and profit-sharing ratios of all partners.', btn('Add Partner', 'add-partner'))}
    <div class="stats-grid s-4">
      ${statCard({ label: 'Partners', value: DB.partners.length, icon: 'users', tone: 'navy', sub: `${DB.gsts.length} GST registrations held` })}
      ${statCard({ label: 'Total Contributions', value: fmt(sum(rows, r => r.contrib)), icon: 'arrow-down-left', tone: 'green', valueTone: 'pos' })}
      ${statCard({ label: 'Total Withdrawals', value: fmt(sum(rows, r => r.withdr)), icon: 'arrow-up-right', tone: 'red', valueTone: 'neg' })}
      ${statCard({ label: 'Profit Sharing Total', value: totalShare + '%', icon: 'percent', tone: totalShare === 100 ? 'green' : 'amber', sub: totalShare === 100 ? 'Ratios add up to 100% ✓' : 'Ratios should add up to 100%' })}
    </div>
    <div class="partner-cards">${rows.map(r => `<div class="partner-card"><div class="pc-top"><div class="avatar lg">${initials(r.name)}</div><div><b>${esc(r.name)}</b><small>${esc(r.role || 'Partner')}</small></div><span class="share-ring" style="--p:${r.share}">${r.share}%</span></div>
      <div class="pc-bar"><span style="width:${totCap ? Math.max(0, r.capital) / totCap * 100 : 0}%"></span></div>
      <div class="pc-row"><span>Capital balance</span><b>${fmt(r.capital)}</b></div><div class="pc-row"><span>Profit share (all time)</span><b class="${r.profitShare >= 0 ? 'pos' : 'neg'}">${fmt(r.profitShare)}</b></div></div>`).join('')}</div>
    ${table('partners', {
      rows, sort: 'name', dir: 'asc', file: 'partners', columns: [
        { key: 'name', label: 'Partner', html: r => `<div class="cell-user"><div class="avatar">${initials(r.name)}</div><div><b>${esc(r.name)}</b><small>${esc(r.email)}</small></div></div>` },
        T.money('initial', 'Investment Amount'),
        T.money('contrib', 'Total Contributions', null, 'pos'),
        T.money('withdr', 'Withdrawals', null, 'neg'),
        T.money('capital', 'Capital Balance'),
        { key: 'share', label: 'Profit Share %', cls: 'num', html: r => `<b>${r.share}%</b>` },
        T.money('profitShare', 'Share of Net Profit', null, r => r.profitShare >= 0 ? 'pos' : 'neg'),
        T.actions(r => iconBtn('eye', 'view-partner', r.id, 'View') + iconBtn('pencil', 'edit-partner', r.id, 'Edit') + iconBtn('trash-2', 'del-partner', r.id, 'Delete', 'danger'))
      ],
      footer: rs => `<tr><td><b>Total</b></td><td class="num">${fmt(sum(rs, r => r.initial))}</td><td class="num">${fmt(sum(rs, r => r.contrib))}</td><td class="num">${fmt(sum(rs, r => r.withdr))}</td><td class="num"><b>${fmt(sum(rs, r => r.capital))}</b></td><td class="num">${sum(rs, r => +r.share)}%</td><td class="num">${fmt(sum(rs, r => r.profitShare))}</td><td></td></tr>`
    })}`;
};
function partnerForm(p) {
  const isNew = !p;
  const fields = [
    { name: 'name', label: 'Partner name', required: true, placeholder: 'e.g. Anita Desai' },
    { name: 'role', label: 'Role', placeholder: 'e.g. Operations Partner' },
    { name: 'phone', label: 'Phone', placeholder: '+91 90000 00000' },
    { name: 'email', label: 'Email', type: 'email', placeholder: 'name@example.com' },
    { name: 'share', label: 'Profit sharing %', type: 'number', min: 0, step: 0.5, required: true, hint: `Other partners currently total ${sum(DB.partners.filter(x => x.id !== p?.id), x => +x.share)}%` }
  ];
  if (isNew) fields.push(
    { name: 'amount', label: 'Initial investment', type: 'number', min: 0, prefix: '₹', placeholder: '0' },
    { name: 'date', label: 'Investment date', type: 'date', value: todayISO() },
    { name: 'accountId', label: 'Deposited into', type: 'select', options: optAccounts(a => a.type === 'Joint'), value: DB.accounts.find(a => a.type === 'Joint')?.id, full: true }
  );
  openForm({
    title: isNew ? 'Add Partner' : `Edit Partner — ${esc(p.name)}`, fields, values: p || {}, submitLabel: isNew ? 'Add Partner' : 'Save Changes',
    onSubmit: v => {
      const others = sum(DB.partners.filter(x => x.id !== p?.id), x => +x.share);
      if (others + v.share > 100) return { share: `Total would be ${others + v.share}% (max 100%)` };
      if (isNew) {
        const np = { id: nextId('P'), name: v.name, role: v.role, phone: v.phone, email: v.email, share: v.share, joined: v.date || todayISO() };
        DB.partners.push(np);
        if (v.amount > 0) {
          if (!v.accountId) return { accountId: 'Choose the account that receives the investment' };
          DB.investments.push({ id: nextId('INV'), partnerId: np.id, type: 'Contribution', amount: v.amount, date: v.date || todayISO(), accountId: v.accountId, desc: 'Initial capital contribution', initial: true });
        }
        commit(`Partner ${v.name} added`);
      } else {
        Object.assign(p, { name: v.name, role: v.role, phone: v.phone, email: v.email, share: v.share });
        commit('Partner updated');
      }
    }
  });
}
function viewPartner(p) {
  const st = partnerStats(p);
  const gsts = DB.gsts.filter(g => g.partnerId === p.id);
  const inv = DB.investments.filter(i => i.partnerId === p.id).sort((a, b) => b.date.localeCompare(a.date));
  openModal({
    title: esc(p.name), size: 'lg', body: `
    <div class="detail-head"><div class="avatar xl">${initials(p.name)}</div><div><h2>${esc(p.name)}</h2><p class="muted">${esc(p.role || 'Partner')} · ${esc(p.email || '')} · ${esc(p.phone || '')}</p></div><span class="share-ring big" style="--p:${p.share}">${p.share}%</span></div>
    <div class="kv-grid"><div><span>Investment amount</span><b>${fmt(st.initial)}</b></div><div><span>Total contributions</span><b class="pos">${fmt(st.contrib)}</b></div><div><span>Withdrawals</span><b class="neg">${fmt(st.withdr)}</b></div><div><span>Capital balance</span><b>${fmt(st.capital)}</b></div></div>
    <h4 class="section-title">GST registrations</h4>${gsts.length ? gsts.map(g => `<div class="list-row"><div class="lr-icon tone-blue"><i data-lucide="file-badge"></i></div><div class="lr-main"><b>${esc(g.businessName)}</b><small>${esc(g.gstin)} · ${esc(g.state)}</small></div><div class="lr-amt">${chips(platformsOfGst(g.id).map(x => x.name))}</div></div>`).join('') : '<p class="muted">No GST registration linked.</p>'}
    <h4 class="section-title">Investment history</h4>
    <div class="table-wrap"><table class="data-table compact"><thead><tr><th>Date</th><th>Type</th><th>Description</th><th class="num">Amount</th></tr></thead><tbody>${inv.map(i => `<tr><td>${fmtDate(i.date)}</td><td>${badge(i.type)}</td><td>${esc(i.desc)}</td><td class="num"><span class="money ${i.type === 'Contribution' ? 'pos' : 'neg'}">${i.type === 'Contribution' ? '+' : '−'}${fmt(i.amount)}</span></td></tr>`).join('') || `<tr><td colspan="4">${emptyState('No investments yet.')}</td></tr>`}</tbody></table></div>`,
    footer: `<button class="btn btn-ghost" data-close-modal>Close</button><button class="btn btn-primary" data-action="add-contribution" data-id="${p.id}"><i data-lucide="plus"></i><span>Add Contribution</span></button>`
  });
}

/* ---------------- Partner investments ---------------- */
PAGES.investments = () => {
  const F = pf('investments'); const R = rangeFor(getDF('investments'));
  const rows = DB.investments.filter(i => inRange(i.date, R) && (!F.partner || i.partnerId === F.partner) && (!F.type || i.type === F.type));
  const contrib = sum(rows.filter(i => i.type === 'Contribution'), i => i.amount), withdr = sum(rows.filter(i => i.type === 'Withdrawal'), i => i.amount);
  const per = DB.partners.map(p => partnerStats(p));
  return `${pageHead('Partner Investments', 'Capital contributions and withdrawals, partner by partner.', btn('Record Withdrawal', 'add-withdrawal', { kind: 'ghost', icon: 'arrow-up-right' }) + btn('Add Contribution', 'add-contribution'))}
    <div class="stats-grid s-4">
      ${statCard({ label: 'Contributions', value: fmt(contrib), icon: 'arrow-down-left', tone: 'green', valueTone: 'pos', sub: 'In the selected filters' })}
      ${statCard({ label: 'Withdrawals', value: fmt(withdr), icon: 'arrow-up-right', tone: 'red', valueTone: 'neg', sub: 'In the selected filters' })}
      ${statCard({ label: 'Net Capital Added', value: fmt(contrib - withdr), icon: 'piggy-bank', tone: 'navy' })}
      ${statCard({ label: 'Transactions', value: rows.length, icon: 'list', tone: 'blue' })}
    </div>
    <div class="grid g-2-1">
      ${panel('Capital by Partner', chartBar('ch-inv', DB.partners.map(p => p.name), [{ label: 'Contributions', data: per.map(x => x.contrib), backgroundColor: COLORS.green }, { label: 'Withdrawals', data: per.map(x => x.withdr), backgroundColor: COLORS.red }]), { icon: 'chart-column' })}
      ${panel('Capital Balances', DB.partners.map((p, i) => `<div class="list-row"><div class="avatar">${initials(p.name)}</div><div class="lr-main"><b>${esc(p.name)}</b><small>${per[i].count} transactions · ${p.share}% share</small></div><div class="lr-amt"><b>${fmt(per[i].capital)}</b></div></div>`).join(''), { icon: 'users' })}
    </div>
    ${table('investments', {
      rows, sort: 'date', file: 'partner-investments',
      filters: filterSelect('investments', 'partner', optPartners(), 'All partners') + filterSelect('investments', 'type', opts(['Contribution', 'Withdrawal']), 'All types') + dateFilter('investments'),
      columns: [
        { key: 'id', label: 'Txn ID', html: r => `<b>${r.id}</b>` }, T.date(),
        { key: 'partner', label: 'Partner', val: r => partnerName(r.partnerId), html: r => `<div class="cell-user"><div class="avatar sm">${initials(partnerName(r.partnerId))}</div>${esc(partnerName(r.partnerId))}</div>` },
        { key: 'type', label: 'Type', html: r => badge(r.type) + (r.initial ? ' <span class="tag">Initial</span>' : '') },
        { key: 'account', label: 'Bank Account', val: r => accName(r.accountId) },
        { key: 'desc', label: 'Description' },
        { key: 'amount', label: 'Amount', cls: 'num', val: r => r.type === 'Withdrawal' ? -r.amount : r.amount, html: r => `<span class="money ${r.type === 'Contribution' ? 'pos' : 'neg'}">${r.type === 'Contribution' ? '+' : '−'}${fmt(r.amount)}</span>` },
        T.actions(r => iconBtn('pencil', 'edit-investment', r.id, 'Edit') + iconBtn('trash-2', 'del-investment', r.id, 'Delete', 'danger'))
      ]
    })}`;
};
function investmentForm(inv, preset = {}) {
  const isNew = !inv;
  const type = inv?.type || preset.type || 'Contribution';
  const jointId = DB.accounts.find(a => a.type === 'Joint')?.id;
  openForm({
    title: isNew ? (type === 'Contribution' ? 'Add Capital Contribution' : 'Record Partner Withdrawal') : `Edit ${inv.id}`,
    values: inv || { type, partnerId: preset.partnerId || '', date: todayISO(), accountId: jointId },
    fields: [
      { name: 'type', label: 'Transaction type', type: 'select', options: opts(['Contribution', 'Withdrawal']), required: true, placeholder: false },
      { name: 'partnerId', label: 'Partner', type: 'select', options: optPartners(), required: true },
      { name: 'amount', label: 'Amount', type: 'number', min: 1, required: true, prefix: '₹' },
      { name: 'date', label: 'Date', type: 'date', required: true },
      { name: 'accountId', label: 'Bank account', type: 'select', options: optAccounts(), required: true, full: true },
      { name: 'desc', label: 'Description', type: 'textarea', full: true, placeholder: 'e.g. Additional capital for festive stock' },
      { name: 'preview', type: 'html', full: true }
    ],
    onChange: (v, form) => {
      const p = find('partners', v.partnerId);
      const box = $('#f-preview', form);
      if (!p) { box.innerHTML = ''; return; }
      const st = partnerStats(p);
      const old = inv && inv.partnerId === p.id ? (inv.type === 'Contribution' ? inv.amount : -inv.amount) : 0;
      const delta = isNaN(v.amount) ? 0 : (v.type === 'Contribution' ? v.amount : -v.amount);
      box.innerHTML = `<div class="preview"><div><span>Current capital</span><b>${fmt(st.capital)}</b></div><div><span>After this entry</span><b class="${delta >= 0 ? 'pos' : 'neg'}">${fmt(st.capital - old + delta)}</b></div><div><span>Account balance</span><b>${fmt(balanceOf(v.accountId))}</b></div></div>`;
    },
    onSubmit: v => {
      if (v.type === 'Withdrawal') {
        let bal = balanceOf(v.accountId);
        if (inv && inv.accountId === v.accountId) bal += inv.type === 'Withdrawal' ? inv.amount : -inv.amount;
        if (v.amount > bal) return { amount: `Insufficient balance — account has ${fmt(bal)}` };
      }
      if (isNew) DB.investments.push({ id: nextId('INV'), ...v, initial: false });
      else Object.assign(inv, v);
      commit(isNew ? `${v.type} of ${fmt(v.amount)} recorded for ${partnerName(v.partnerId)}` : 'Investment updated');
    }
  });
}

/* ---------------- GST registrations ---------------- */
const STATES = ['Andhra Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Odisha', 'Punjab', 'Rajasthan', 'Tamil Nadu', 'Telangana', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal'];
const STATE_CODE = { 'Andhra Pradesh': '37', Assam: '18', Bihar: '10', Chhattisgarh: '22', Delhi: '07', Goa: '30', Gujarat: '24', Haryana: '06', 'Himachal Pradesh': '02', Jharkhand: '20', Karnataka: '29', Kerala: '32', 'Madhya Pradesh': '23', Maharashtra: '27', Odisha: '21', Punjab: '03', Rajasthan: '08', 'Tamil Nadu': '33', Telangana: '36', 'Uttar Pradesh': '09', Uttarakhand: '05', 'West Bengal': '19' };
PAGES.gst = () => {
  const rows = DB.gsts.map(g => {
    const sales = DB.sales.filter(s => s.gstId === g.id && saleActive(s));
    return { ...g, partner: partnerName(g.partnerId), accounts: accountsOfGst(g.id).map(a => a.name), platforms: platformsOfGst(g.id).map(p => p.name), sales: sum(sales, s => s.price * s.qty), outGst: sum(sales, s => saleCalc(s).gst) };
  });
  return `${pageHead('GST Registrations', 'Each partner’s GST registration with its bank account and marketplaces. <span class="tag">Fictional GSTINs</span>', btn('Add GST Registration', 'add-gst'))}
    <div class="gst-cards">${rows.map(g => `<div class="gst-card clickable" data-action="view-gst" data-id="${g.id}"><div class="gst-top"><span class="gst-state">${esc(STATE_CODE[g.state] || g.gstin.slice(0, 2))}</span><div><b>${esc(g.businessName)}</b><small>${esc(g.state)}</small></div></div>
      <div class="gstin">${esc(g.gstin)}</div><div class="gst-meta"><span><i data-lucide="user"></i>${esc(g.partner)}</span><span><i data-lucide="store"></i>${g.platforms.length} platforms</span></div><div class="gst-foot"><span>Sales</span><b>${fmt(g.sales)}</b></div></div>`).join('')}</div>
    ${table('gst', {
      rows, sort: 'businessName', dir: 'asc', file: 'gst-registrations', columns: [
        { key: 'gstin', label: 'GST Number', html: r => `<code class="mono">${esc(r.gstin)}</code>` },
        { key: 'partner', label: 'Partner Name' },
        { key: 'businessName', label: 'Business Name', html: r => `<b>${esc(r.businessName)}</b>` },
        { key: 'state', label: 'State' },
        { key: 'accounts', label: 'Associated Bank Account', val: r => r.accounts.join('; '), html: r => r.accounts.map(esc).join('<br>') || '<span class="muted">—</span>' },
        { key: 'platforms', label: 'Selling Platforms', val: r => r.platforms.join('; '), html: r => chips(r.platforms) },
        T.money('sales', 'Total Sales'),
        T.actions(r => iconBtn('eye', 'view-gst', r.id, 'View') + iconBtn('pencil', 'edit-gst', r.id, 'Edit') + iconBtn('trash-2', 'del-gst', r.id, 'Delete', 'danger'))
      ]
    })}`;
};
function gstForm(g) {
  const isNew = !g;
  const curAcc = g ? gstAccountId(g.id) : '';
  const values = g ? { ...g, accountId: curAcc, platformIds: platformsOfGst(g.id).map(p => p.id) } : { regDate: todayISO(), platformIds: [] };
  openForm({
    title: isNew ? 'Add GST Registration' : `Edit GST — ${esc(g.businessName)}`, values, size: 'lg',
    note: 'Use fictional GST numbers only. This demo does not connect to the GST portal.',
    fields: [
      { name: 'gstin', label: 'GST number (GSTIN)', required: true, placeholder: '24ABCDE1234F1Z5', pattern: /^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/, patternMsg: '15 characters, e.g. 24ABCDE1234F1Z5' },
      { name: 'businessName', label: 'Business / trade name', required: true },
      { name: 'partnerId', label: 'Partner', type: 'select', options: optPartners(), required: true },
      { name: 'state', label: 'State', type: 'select', options: opts(STATES), required: true },
      { name: 'accountId', label: 'Associated business bank account', type: 'select', options: optAccounts(a => a.type === 'Business' && (!a.gstId || a.gstId === g?.id), false), hint: 'Settlements for this GST are paid into this account' },
      { name: 'regDate', label: 'Registration date', type: 'date' },
      { name: 'platformIds', label: 'Associated selling platforms', type: 'checks', options: optPlatforms(), full: true }
    ],
    onChange: (v, form, changed) => {
      if (changed === 'state' && STATE_CODE[v.state] && !v.gstin) form.elements.gstin.value = STATE_CODE[v.state];
      if (changed === 'gstin') form.elements.gstin.value = form.elements.gstin.value.toUpperCase();
    },
    onSubmit: v => {
      v.gstin = v.gstin.toUpperCase();
      if (DB.gsts.some(x => x.gstin === v.gstin && x.id !== g?.id)) return { gstin: 'This GSTIN already exists' };
      const rec = g || { id: nextId('GST') };
      Object.assign(rec, { gstin: v.gstin, businessName: v.businessName, partnerId: v.partnerId, state: v.state, regDate: v.regDate });
      if (isNew) DB.gsts.push(rec);
      DB.accounts.forEach(a => { if (a.gstId === rec.id && a.id !== v.accountId) a.gstId = ''; });
      if (v.accountId) find('accounts', v.accountId).gstId = rec.id;
      DB.platforms.forEach(p => {
        const has = p.gstIds.includes(rec.id), want = v.platformIds.includes(p.id);
        if (want && !has) p.gstIds.push(rec.id);
        if (!want && has) p.gstIds = p.gstIds.filter(x => x !== rec.id);
      });
      commit(isNew ? 'GST registration added' : 'GST registration updated');
    }
  });
}
function viewGst(g) {
  const pl = computePL({ gstId: g.id });
  const purch = sum(DB.purchases.filter(p => p.gstId === g.id), p => purchaseCalc(p).total);
  const inGst = sum(DB.purchases.filter(p => p.gstId === g.id), p => purchaseCalc(p).gst);
  openModal({
    title: esc(g.businessName), size: 'lg', body: `
    <div class="detail-head"><div class="gst-state big">${esc(STATE_CODE[g.state] || '')}</div><div><h2>${esc(g.businessName)}</h2><p class="muted"><code class="mono">${esc(g.gstin)}</code> · ${esc(g.state)} · Partner: ${esc(partnerName(g.partnerId))}</p></div></div>
    <div class="kv-grid"><div><span>Net sales (excl. GST)</span><b>${fmt(pl.netSales)}</b></div><div><span>Output GST collected</span><b>${fmt(pl.outputGst)}</b></div><div><span>Input GST on purchases</span><b>${fmt(inGst)}</b></div><div><span>Net profit / loss</span><b class="${pl.net >= 0 ? 'pos' : 'neg'}">${fmt(pl.net)}</b></div></div>
    <div class="two-col"><div><h4 class="section-title">Bank account</h4>${accountsOfGst(g.id).map(a => `<div class="list-row clickable" data-action="statement" data-id="${a.id}"><div class="lr-icon tone-blue"><i data-lucide="landmark"></i></div><div class="lr-main"><b>${esc(a.name)}</b><small>${esc(a.bank)} · ${esc(a.accNo)}</small></div><div class="lr-amt">${money(balanceOf(a.id))}</div></div>`).join('') || '<p class="muted">No account linked.</p>'}</div>
    <div><h4 class="section-title">Selling platforms</h4>${platformsOfGst(g.id).map(p => `<div class="list-row"><div class="lr-icon" style="background:${alpha(p.color, .12)};color:${p.color}"><i data-lucide="store"></i></div><div class="lr-main"><b>${esc(p.name)}</b><small>${p.commission}% commission</small></div><div class="lr-amt">${money(sum(DB.sales.filter(s => s.platformId === p.id && s.gstId === g.id && saleActive(s)), s => s.price * s.qty))}</div></div>`).join('') || '<p class="muted">No platforms linked.</p>'}</div></div>
    <p class="muted small">GST figures are indicative demo calculations, not a GST return. Purchases recorded: ${fmt(purch)}.</p>`,
    footer: `<button class="btn btn-ghost" data-close-modal>Close</button><button class="btn btn-primary" data-action="edit-gst" data-id="${g.id}"><i data-lucide="pencil"></i><span>Edit</span></button>`
  });
}

/* ---------------- Bank accounts ---------------- */
PAGES.banks = () => {
  const E = ledgerEntries();
  const rows = DB.accounts.map(a => ({ ...a, ...accountStats(a, E), gst: a.gstId ? gstLabel(a.gstId) : '' }));
  return `${pageHead('Bank Accounts', 'Joint and business accounts. Click any account to open its statement. <span class="tag">Fictional account numbers</span>', btn('Add Bank Account', 'add-account'))}
    <div class="bank-cards">${rows.map(a => `<div class="bank-card ${a.type === 'Joint' ? 'joint' : ''} clickable" data-action="statement" data-id="${a.id}">
      <div class="bc-top"><span>${esc(a.bank)}</span>${badge(a.type, a.type === 'Joint' ? 'gold' : 'glass')}</div>
      <div class="bc-name">${esc(a.name)}</div><div class="bc-no">${esc(a.accNo)}</div>
      <div class="bc-bal"><small>Current balance</small><b>${fmt(a.balance)}</b></div>
      <div class="bc-foot"><span>▲ ${fmtCompact(a.credits)}</span><span>▼ ${fmtCompact(a.debits)}</span><span>${a.gst ? esc(a.gst) : 'Joint'}</span></div></div>`).join('')}</div>
    ${table('banks', {
      rows, sort: 'name', dir: 'asc', rowAction: 'statement', file: 'bank-accounts', columns: [
        { key: 'name', label: 'Account Name', html: r => `<b>${esc(r.name)}</b><small class="muted block">${esc(r.bank)} · ${esc(r.accNo)}</small>` },
        { key: 'type', label: 'Account Type', html: r => badge(r.type) },
        { key: 'gst', label: 'Associated GST Number', html: r => r.gst ? `<code class="mono">${esc(r.gst)}</code>` : '<span class="muted">—</span>' },
        T.money('opening', 'Opening Balance'), T.money('credits', 'Total Credits', null, 'pos'), T.money('debits', 'Total Debits', null, 'neg'),
        { key: 'balance', label: 'Current Balance', cls: 'num', html: r => `<b>${moneySigned(r.balance)}</b>` },
        T.actions(r => iconBtn('file-text', 'statement', r.id, 'Statement') + iconBtn('pencil', 'edit-account', r.id, 'Edit') + iconBtn('trash-2', 'del-account', r.id, 'Delete', 'danger'))
      ],
      footer: rs => `<tr><td colspan="3"><b>Total</b></td><td class="num">${fmt(sum(rs, r => r.opening))}</td><td class="num">${fmt(sum(rs, r => r.credits))}</td><td class="num">${fmt(sum(rs, r => r.debits))}</td><td class="num"><b>${fmt(sum(rs, r => r.balance))}</b></td><td></td></tr>`
    })}`;
};
function accountForm(a) {
  const isNew = !a;
  openForm({
    title: isNew ? 'Add Bank Account' : `Edit — ${esc(a.name)}`, values: a || { type: 'Business', opening: 0, openedOn: todayISO() },
    note: 'Demo only — use fictional account details. No real bank connection is made.',
    fields: [
      { name: 'name', label: 'Account name', required: true, full: true, placeholder: 'e.g. Desai Retail – Current A/c' },
      { name: 'type', label: 'Account type', type: 'select', options: opts(['Business', 'Joint']), required: true, placeholder: false },
      { name: 'bank', label: 'Bank name', required: true, placeholder: 'e.g. Demo National Bank' },
      { name: 'accNo', label: 'Account number (fictional)', required: true, placeholder: 'DEMO-XXXX-0000' },
      { name: 'ifsc', label: 'IFSC (fictional)', placeholder: 'DEMO0000000' },
      { name: 'gstId', label: 'Associated GST number', type: 'select', options: optGsts(), placeholder: 'None' },
      { name: 'opening', label: 'Opening balance', type: 'number', min: 0, prefix: '₹', required: true, readonly: !isNew && accountStats(a).entries.length > 0, hint: !isNew && accountStats(a).entries.length ? 'Locked: account already has transactions' : '' },
      { name: 'openedOn', label: 'Opened on', type: 'date' }
    ],
    onSubmit: v => {
      if (v.type === 'Joint') v.gstId = '';
      if (isNew) DB.accounts.push({ id: nextId('ACC'), ...v });
      else Object.assign(a, v);
      commit(isNew ? 'Bank account added' : 'Bank account updated');
    }
  });
}
function statementRows(a) {
  const st = accountStats(a);
  const rows = st.entries.slice().sort((x, y) => x.date.localeCompare(y.date) || (y.credit - x.credit));
  let bal = a.opening;
  return rows.map(e => { bal += e.credit - e.debit; return { ...e, balance: bal }; });
}
function openStatement(a) {
  const rows = statementRows(a);
  const st = accountStats(a);
  openModal({
    title: `Statement — ${esc(a.name)}`, size: 'xl', body: `
    <div class="statement-head"><div><span class="muted small">${esc(a.bank)} · ${esc(a.accNo)} · IFSC ${esc(a.ifsc || '—')}</span><div>${badge(a.type)} ${a.gstId ? `<code class="mono">${esc(gstLabel(a.gstId))}</code>` : ''}</div></div>
    <div class="kv-grid inline"><div><span>Opening</span><b>${fmt(a.opening)}</b></div><div><span>Credits</span><b class="pos">${fmt(st.credits)}</b></div><div><span>Debits</span><b class="neg">${fmt(st.debits)}</b></div><div><span>Closing balance</span><b>${fmt(st.balance)}</b></div></div></div>
    <div class="table-wrap statement"><table class="data-table compact"><thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Reference</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
    <tbody><tr class="opening-row"><td>${fmtDate(a.openedOn)}</td><td colspan="5"><b>Opening balance</b></td><td class="num"><b>${fmt(a.opening)}</b></td></tr>
    ${rows.map(e => `<tr><td>${fmtDate(e.date)}</td><td>${esc(e.kind)}${e.internal ? ' <span class="tag">Internal</span>' : ''}</td><td>${esc(e.desc)}</td><td class="muted mono small">${esc(e.ref)}</td><td class="num">${e.debit ? `<span class="money neg">${fmt(e.debit)}</span>` : ''}</td><td class="num">${e.credit ? `<span class="money pos">${fmt(e.credit)}</span>` : ''}</td><td class="num"><b>${fmt(e.balance)}</b></td></tr>`).join('')}</tbody></table></div>
    <p class="muted small">Internal transfers move money between your own accounts and are never counted as business income or expense.</p>`,
    footer: `<button class="btn btn-ghost" data-close-modal>Close</button><button class="btn btn-primary" data-action="export-statement" data-id="${a.id}"><i data-lucide="download"></i><span>Export CSV</span></button>`
  });
}

/* ---------------- Bank transfers ---------------- */
PAGES.transfers = () => {
  const R = rangeFor(getDF('transfers'));
  const rows = DB.transfers.filter(t => inRange(t.date, R));
  const E = ledgerEntries();
  const accOpts = DB.accounts.map(a => `<option value="${a.id}">${esc(a.name)} — ${fmt(accountStats(a, E).balance)}</option>`).join('');
  const joint = DB.accounts.find(a => a.type === 'Joint');
  AFTER.push(() => setupTransferForm());
  return `${pageHead('Bank Transfers', 'Move money between the joint account and business accounts.')}
    <div class="grid g-1-1">
      <section class="panel"><div class="panel-head"><h3><i data-lucide="send"></i>New Transfer</h3>${badge('Internal', 'gray')}</div><div class="panel-body">
        <form id="transfer-form" class="form-grid" novalidate>
          <div class="field"><label for="tf-from">Transfer from<em>*</em></label><select class="input" id="tf-from" name="fromId" required><option value="">Select…</option>${accOpts}</select><small class="err"></small></div>
          <div class="field swap-field"><button type="button" class="icon-btn swap-btn" id="tf-swap" title="Swap accounts" aria-label="Swap accounts"><i data-lucide="arrow-left-right"></i></button></div>
          <div class="field"><label for="tf-to">Transfer to<em>*</em></label><select class="input" id="tf-to" name="toId" required><option value="">Select…</option>${accOpts}</select><small class="err"></small></div>
          <div class="field"><label for="tf-amount">Amount<em>*</em></label><div class="input-prefix"><span>₹</span><input class="input" type="number" min="1" id="tf-amount" name="amount" required placeholder="0"></div><small class="err"></small></div>
          <div class="field"><label for="tf-date">Date<em>*</em></label><input class="input" type="date" id="tf-date" name="date" value="${todayISO()}" required><small class="err"></small></div>
          <div class="field"><label for="tf-ref">Transaction reference</label><input class="input" id="tf-ref" name="ref" value="UTRDEMO${randDigits(8)}"></div>
          <div class="field"><label for="tf-desc">Description</label><input class="input" id="tf-desc" name="desc" placeholder="e.g. Working capital top-up"></div>
          <div class="full" id="tf-preview"></div>
          <div class="form-error full hidden"></div>
          <div class="full form-actions"><button type="reset" class="btn btn-ghost">Clear</button><button type="submit" class="btn btn-primary"><i data-lucide="send"></i><span>Complete Transfer</span></button></div>
        </form></div></section>
      <section class="panel info-panel"><div class="panel-head"><h3><i data-lucide="info"></i>How transfers work</h3></div><div class="panel-body">
        <ul class="ticks"><li>The amount is <b class="neg">deducted</b> from the source account and <b class="pos">added</b> to the destination account immediately.</li>
        <li>Transfers are <b>internal movements</b>: they are never counted as income, sales or expenses in Profit & Loss.</li>
        <li>A transfer is blocked if the source account doesn’t have enough balance.</li><li>Deleting a transfer reverses both sides.</li></ul>
        <div class="mini-title">Current balances</div>
        ${DB.accounts.map(a => `<div class="list-row"><div class="lr-icon ${a.type === 'Joint' ? 'tone-navy' : 'tone-blue'}"><i data-lucide="landmark"></i></div><div class="lr-main"><b>${esc(a.name)}</b></div><div class="lr-amt"><b>${fmt(accountStats(a, E).balance)}</b></div></div>`).join('')}
        ${joint ? '' : ''}</div></section>
    </div>
    ${table('transfers', {
      rows, sort: 'date', file: 'bank-transfers', title: 'Transfer history', filters: dateFilter('transfers'), columns: [
        { key: 'id', label: 'ID', html: r => `<b>${r.id}</b>` }, T.date(),
        { key: 'from', label: 'From', val: r => accName(r.fromId) }, { key: 'to', label: 'To', val: r => accName(r.toId) },
        { key: 'ref', label: 'Reference', html: r => `<span class="mono small">${esc(r.ref)}</span>` }, { key: 'desc', label: 'Description' },
        T.money('amount', 'Amount'),
        T.actions(r => iconBtn('rotate-ccw', 'del-transfer', r.id, 'Reverse / delete', 'danger'))
      ],
      footer: rs => `<tr><td colspan="6"><b>Total moved</b></td><td class="num"><b>${fmt(sum(rs, r => r.amount))}</b></td><td></td></tr>`
    })}`;
};
function setupTransferForm() {
  const form = $('#transfer-form'); if (!form) return;
  const joint = DB.accounts.find(a => a.type === 'Joint');
  if (joint) form.elements.fromId.value = joint.id;
  const preview = () => {
    const f = form.elements.fromId.value, t = form.elements.toId.value, amt = parseFloat(form.elements.amount.value) || 0;
    const box = $('#tf-preview');
    if (!f && !t) { box.innerHTML = ''; return; }
    const fb = f ? balanceOf(f) : 0, tb = t ? balanceOf(t) : 0;
    box.innerHTML = `<div class="transfer-preview">
      <div class="tp-side"><small>From</small><b>${f ? esc(accName(f)) : '—'}</b><span>${fmt(fb)} → <b class="${fb - amt < 0 ? 'neg' : ''}">${fmt(fb - amt)}</b></span></div>
      <div class="tp-arrow"><i data-lucide="arrow-right"></i><b>${fmt(amt)}</b></div>
      <div class="tp-side"><small>To</small><b>${t ? esc(accName(t)) : '—'}</b><span>${fmt(tb)} → <b class="pos">${fmt(tb + amt)}</b></span></div></div>`;
    icons();
  };
  form.addEventListener('input', preview); form.addEventListener('change', preview);
  form.addEventListener('reset', () => setTimeout(() => { form.elements.ref.value = 'UTRDEMO' + randDigits(8); form.elements.date.value = todayISO(); preview(); }, 0));
  $('#tf-swap').addEventListener('click', () => { const a = form.elements.fromId.value; form.elements.fromId.value = form.elements.toId.value; form.elements.toId.value = a; preview(); });
  form.addEventListener('submit', e => {
    e.preventDefault();
    const v = { fromId: form.elements.fromId.value, toId: form.elements.toId.value, amount: parseFloat(form.elements.amount.value), date: form.elements.date.value, ref: form.elements.ref.value.trim(), desc: form.elements.desc.value.trim() };
    const err = $('.form-error', form);
    const fail = m => { err.textContent = m; err.classList.remove('hidden'); };
    if (!v.fromId || !v.toId) return fail('Choose both accounts.');
    if (v.fromId === v.toId) return fail('Source and destination must be different accounts.');
    if (!(v.amount > 0)) return fail('Enter an amount greater than zero.');
    if (!v.date) return fail('Choose a date.');
    const bal = balanceOf(v.fromId);
    if (v.amount > bal) return fail(`Insufficient balance: ${accName(v.fromId)} has only ${fmt(bal)}.`);
    DB.transfers.push({ id: nextId('TRF'), ...v, ref: v.ref || 'UTRDEMO' + randDigits(8) });
    commit(`Transferred ${fmt(v.amount)} to ${accName(v.toId)}`);
  });
  preview();
}

/* ---------------- Selling platforms ---------------- */
function platformStats(p) {
  const sales = DB.sales.filter(s => s.platformId === p.id && saleActive(s));
  const ids = new Set(sales.map(s => s.id));
  const stl = DB.settlements.filter(s => s.platformId === p.id);
  return {
    orders: sales.length, sales: sum(sales, s => s.price * s.qty), returns: sum(DB.returns.filter(r => ids.has(r.saleId) && returnActive(r)), r => r.refund),
    charges: sum(sales, s => (s.commission || 0) + (s.shipping || 0)) + sum(stl, s => s.other), settled: sum(stl.filter(s => s.status === 'Received'), s => s.net), pending: sum(stl.filter(s => s.status !== 'Received'), s => s.net)
  };
}
PAGES.platforms = () => {
  const rows = DB.platforms.map(p => ({ ...p, ...platformStats(p) }));
  return `${pageHead('Selling Platforms', 'Marketplaces, their GST registrations and payout accounts.', btn('Add Platform', 'add-platform'))}
    <div class="platform-cards">${rows.map(p => `<div class="platform-card" style="--pc:${p.color}"><div class="plc-top"><span class="plc-logo">${esc(p.name[0])}</span><div><b>${esc(p.name)}</b><small>${p.commission}% commission · ₹${p.shipPerUnit}/unit shipping</small></div>${iconBtn('pencil', 'edit-platform', p.id, 'Edit')}</div>
      <div class="plc-stats"><div><small>Sales</small><b>${fmtCompact(p.sales)}</b></div><div><small>Returns</small><b class="neg">${fmtCompact(p.returns)}</b></div><div><small>Charges</small><b class="neg">${fmtCompact(p.charges)}</b></div><div><small>Settled</small><b class="pos">${fmtCompact(p.settled)}</b></div></div>
      <div class="plc-foot">${chips(p.gstIds.map(gstBiz))}</div></div>`).join('')}</div>
    ${table('platforms', {
      rows, sort: 'name', dir: 'asc', file: 'selling-platforms', columns: [
        { key: 'name', label: 'Platform Name', html: r => `<div class="cell-user"><span class="plc-logo sm" style="--pc:${r.color}">${esc(r.name[0])}</span><b>${esc(r.name)}</b></div>` },
        { key: 'gsts', label: 'Associated GST Registration', val: r => r.gstIds.map(gstLabel).join('; '), html: r => r.gstIds.map(g => `<code class="mono">${esc(gstLabel(g))}</code>`).join('<br>') || '<span class="muted">—</span>' },
        { key: 'accs', label: 'Associated Business Bank Account', val: r => r.gstIds.map(g => accName(gstAccountId(g))).join('; '), html: r => r.gstIds.map(g => esc(accName(gstAccountId(g)))).join('<br>') || '<span class="muted">—</span>' },
        T.money('sales', 'Total Sales', null, 'pos'), T.money('returns', 'Total Returns', null, 'neg'), T.money('charges', 'Platform Charges', null, 'neg'),
        T.money('settled', 'Net Settlements'),
        T.actions(r => iconBtn('pencil', 'edit-platform', r.id, 'Edit') + iconBtn('trash-2', 'del-platform', r.id, 'Delete', 'danger'))
      ]
    })}`;
};
function platformForm(p) {
  const isNew = !p;
  openForm({
    title: isNew ? 'Add Selling Platform' : `Edit — ${esc(p.name)}`, values: p || { commission: 10, shipPerUnit: 15, color: '#0d9488', gstIds: [] },
    fields: [
      { name: 'name', label: 'Platform name', required: true, placeholder: 'e.g. JioMart, Myntra, Own website' },
      { name: 'color', label: 'Brand colour', type: 'color' },
      { name: 'commission', label: 'Commission %', type: 'number', min: 0, step: 0.5, required: true },
      { name: 'shipPerUnit', label: 'Shipping fee per unit', type: 'number', min: 0, prefix: '₹', required: true },
      { name: 'gstIds', label: 'Associated GST registrations', type: 'checks', options: optGsts(), full: true, required: true, hint: 'Payouts go to the bank account linked with each GST registration.' }
    ],
    onSubmit: v => {
      if (DB.platforms.some(x => x.name.toLowerCase() === v.name.toLowerCase() && x.id !== p?.id)) return { name: 'Platform already exists' };
      if (isNew) DB.platforms.push({ id: nextId('PLT'), ...v }); else Object.assign(p, v);
      commit(isNew ? `${v.name} added` : 'Platform updated');
    }
  });
}

/* ---------------- Products & inventory ---------------- */
const CATEGORIES = ['Apparel', 'Electronics', 'Home & Kitchen', 'Fitness', 'Accessories', 'Bags', 'Beauty', 'Toys', 'Other'];
PAGES.products = () => {
  const F = pf('products');
  const all = DB.products.map(p => ({ ...p, ...productStats(p) }));
  const rows = all.filter(p => (!F.category || p.category === F.category) && (!F.stock || (F.stock === 'low' ? p.low && p.stock > 0 : F.stock === 'out' ? p.stock <= 0 : !p.low)));
  return `${pageHead('Products & Inventory', 'Stock is calculated automatically from opening stock, purchases, sales and restocked returns.', btn('Add Product', 'add-product'))}
    <div class="stats-grid s-4">
      ${statCard({ label: 'Products', value: all.length, icon: 'package', tone: 'navy', sub: `${new Set(all.map(p => p.category)).size} categories` })}
      ${statCard({ label: 'Units in Stock', value: num(sum(all, p => p.stock)), icon: 'boxes', tone: 'blue' })}
      ${statCard({ label: 'Stock Value (at cost)', value: fmt(sum(all, p => p.value)), icon: 'indian-rupee', tone: 'green' })}
      ${statCard({ label: 'Low Stock Items', value: all.filter(p => p.low).length, icon: 'triangle-alert', tone: 'amber', sub: 'At or below reorder level' })}
    </div>
    ${table('products', {
      rows, sort: 'name', dir: 'asc', file: 'products-inventory',
      filters: filterSelect('products', 'category', opts(CATEGORIES.filter(c => all.some(p => p.category === c))), 'All categories') + filterSelect('products', 'stock', [{ value: 'ok', label: 'In stock' }, { value: 'low', label: 'Low stock' }, { value: 'out', label: 'Out of stock' }], 'All stock levels'),
      columns: [
        { key: 'name', label: 'Product Name', html: r => `<b>${esc(r.name)}</b><small class="muted block">${esc(r.category)}</small>` },
        { key: 'sku', label: 'SKU', html: r => `<code class="mono">${esc(r.sku)}</code>` },
        T.money('purchasePrice', 'Purchase Price'), T.money('sellingPrice', 'Selling Price'),
        { key: 'stock', label: 'Available Stock', cls: 'num', html: r => `<span class="stock-pill ${r.stock <= 0 ? 'out' : r.low ? 'low' : 'ok'}">${num(r.stock)}</span>` },
        { key: 'sold', label: 'Sold Qty', cls: 'num', html: r => num(r.sold) },
        { key: 'returned', label: 'Returned Qty', cls: 'num', html: r => num(r.returned) },
        T.money('value', 'Stock Value'),
        T.actions(r => iconBtn('eye', 'view-product', r.id, 'View') + iconBtn('pencil', 'edit-product', r.id, 'Edit') + iconBtn('trash-2', 'del-product', r.id, 'Delete', 'danger'))
      ],
      footer: rs => `<tr><td colspan="4"><b>Total</b></td><td class="num"><b>${num(sum(rs, r => r.stock))}</b></td><td class="num">${num(sum(rs, r => r.sold))}</td><td class="num">${num(sum(rs, r => r.returned))}</td><td class="num"><b>${fmt(sum(rs, r => r.value))}</b></td><td></td></tr>`
    })}`;
};
function productForm(p) {
  const isNew = !p;
  openForm({
    title: isNew ? 'Add Product' : `Edit — ${esc(p.name)}`, values: p || { gstRate: 18, openingStock: 0, reorderLevel: 10, category: 'Other' }, size: 'lg',
    fields: [
      { name: 'name', label: 'Product name', required: true, full: true },
      { name: 'sku', label: 'SKU', required: true, placeholder: 'e.g. KRT-011' },
      { name: 'category', label: 'Category', type: 'select', options: opts(CATEGORIES), required: true },
      { name: 'purchasePrice', label: 'Purchase price (excl. GST)', type: 'number', min: 0, prefix: '₹', required: true },
      { name: 'sellingPrice', label: 'Selling price (incl. GST)', type: 'number', min: 0, prefix: '₹', required: true },
      { name: 'gstRate', label: 'GST rate', type: 'select', options: [0, 5, 12, 18, 28].map(x => ({ value: x, label: x + '%' })), required: true, placeholder: false },
      { name: 'openingStock', label: 'Opening stock (units)', type: 'number', min: 0, required: true },
      { name: 'reorderLevel', label: 'Reorder level', type: 'number', min: 0, required: true },
      { name: 'margin', type: 'html', full: true }
    ],
    onChange: (v, form) => {
      const rate = +v.gstRate || 0;
      const taxable = (v.sellingPrice || 0) * 100 / (100 + rate);
      const m = taxable - (v.purchasePrice || 0);
      $('#f-margin', form).innerHTML = v.sellingPrice > 0 ? `<div class="preview"><div><span>Selling price excl. GST</span><b>${fmt(taxable)}</b></div><div><span>Gross margin / unit</span><b class="${m >= 0 ? 'pos' : 'neg'}">${fmt(m)}</b></div><div><span>Margin %</span><b>${pct(taxable ? m / taxable * 100 : 0)}</b></div></div>` : '';
    },
    onSubmit: v => {
      v.gstRate = +v.gstRate;
      if (DB.products.some(x => x.sku.toLowerCase() === v.sku.toLowerCase() && x.id !== p?.id)) return { sku: 'SKU already used' };
      if (isNew) DB.products.push({ id: nextId('PRD'), ...v }); else Object.assign(p, v);
      commit(isNew ? `${v.name} added` : 'Product updated');
    }
  });
}
function viewProduct(p) {
  const st = productStats(p);
  const taxable = p.sellingPrice * 100 / (100 + p.gstRate);
  const moves = [
    ...DB.purchases.filter(x => x.productId === p.id).map(x => ({ date: x.date, type: 'Purchase', ref: x.id, qty: x.qty, note: x.supplier })),
    ...DB.sales.filter(x => x.productId === p.id).map(x => ({ date: x.date, type: x.status === 'Cancelled' ? 'Sale (cancelled)' : 'Sale', ref: x.orderId, qty: x.status === 'Cancelled' ? 0 : -x.qty, note: platName(x.platformId) })),
    ...DB.returns.filter(r => find('sales', r.saleId)?.productId === p.id).map(r => ({ date: r.date, type: 'Return', ref: r.id, qty: r.restock === 'Restocked' && returnActive(r) ? r.qty : 0, note: `${r.status} · ${r.restock}` }))
  ].sort((a, b) => b.date.localeCompare(a.date));
  openModal({
    title: esc(p.name), size: 'lg', body: `
    <div class="detail-head"><div class="lr-icon big tone-blue"><i data-lucide="package"></i></div><div><h2>${esc(p.name)}</h2><p class="muted"><code class="mono">${esc(p.sku)}</code> · ${esc(p.category)} · GST ${p.gstRate}%</p></div><span class="stock-pill big ${st.stock <= 0 ? 'out' : st.low ? 'low' : 'ok'}">${num(st.stock)} in stock</span></div>
    <div class="kv-grid"><div><span>Purchase price</span><b>${fmt(p.purchasePrice)}</b></div><div><span>Selling price</span><b>${fmt(p.sellingPrice)}</b></div><div><span>Margin / unit (excl. GST)</span><b class="pos">${fmt(taxable - p.purchasePrice)}</b></div><div><span>Stock value</span><b>${fmt(st.value)}</b></div>
    <div><span>Opening stock</span><b>${num(p.openingStock)}</b></div><div><span>Purchased</span><b>${num(st.purchased)}</b></div><div><span>Sold</span><b>${num(st.sold)}</b></div><div><span>Returned / restocked</span><b>${num(st.returned)} / ${num(st.restocked)}</b></div></div>
    <h4 class="section-title">Stock movements</h4>
    <div class="table-wrap"><table class="data-table compact"><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Details</th><th class="num">Qty</th></tr></thead><tbody>
    ${moves.map(m => `<tr><td>${fmtDate(m.date)}</td><td>${esc(m.type)}</td><td class="mono small">${esc(m.ref)}</td><td>${esc(m.note)}</td><td class="num"><b class="${m.qty > 0 ? 'pos' : m.qty < 0 ? 'neg' : 'muted'}">${m.qty > 0 ? '+' : ''}${m.qty}</b></td></tr>`).join('') || `<tr><td colspan="5">${emptyState('No movements yet.')}</td></tr>`}</tbody></table></div>`,
    footer: `<button class="btn btn-ghost" data-close-modal>Close</button><button class="btn btn-ghost" data-action="add-purchase" data-id="${p.id}"><i data-lucide="shopping-cart"></i><span>Purchase Stock</span></button><button class="btn btn-primary" data-action="edit-product" data-id="${p.id}"><i data-lucide="pencil"></i><span>Edit</span></button>`
  });
}

/* ---------------- Purchases ---------------- */
PAGES.purchases = () => {
  const F = pf('purchases'); const R = rangeFor(getDF('purchases'));
  const rows = DB.purchases.filter(p => inRange(p.date, R) && (!F.gst || p.gstId === F.gst) && (!F.product || p.productId === F.product)).map(p => ({ ...p, ...purchaseCalc(p) }));
  return `${pageHead('Purchases', 'Inventory bought from suppliers. Purchases add stock — they are expensed only when the goods are sold (COGS).', btn('Add Purchase', 'add-purchase'))}
    <div class="stats-grid s-4">
      ${statCard({ label: 'Taxable Value', value: fmt(sum(rows, r => r.amount)), icon: 'shopping-cart', tone: 'violet' })}
      ${statCard({ label: 'Input GST', value: fmt(sum(rows, r => r.gst)), icon: 'percent', tone: 'blue', sub: 'Claimable input tax (demo)' })}
      ${statCard({ label: 'Total Paid', value: fmt(sum(rows, r => r.total)), icon: 'banknote', tone: 'red', valueTone: 'neg' })}
      ${statCard({ label: 'Units Purchased', value: num(sum(rows, r => r.qty)), icon: 'boxes', tone: 'teal', sub: `${rows.length} purchase records` })}
    </div>
    ${table('purchases', {
      rows, sort: 'date', file: 'purchases',
      filters: filterSelect('purchases', 'gst', optGstsShort(), 'All GST registrations') + filterSelect('purchases', 'product', DB.products.map(p => ({ value: p.id, label: p.name })), 'All products') + dateFilter('purchases'),
      columns: [
        { key: 'id', label: 'Purchase ID', html: r => `<b>${r.id}</b><small class="muted block">${esc(r.invoiceNo || '')}</small>` },
        { key: 'supplier', label: 'Supplier' },
        { key: 'product', label: 'Product', val: r => prodName(r.productId) },
        { key: 'qty', label: 'Qty', cls: 'num' },
        T.money('amount', 'Purchase Amount'),
        { key: 'gst', label: 'GST', cls: 'num', val: r => r.gst, html: r => `${fmt(r.gst)}<small class="muted block">@ ${r.gstRate}%</small>` },
        T.money('total', 'Total', null, 'neg'),
        { key: 'gstReg', label: 'GST Registration', val: r => gstBiz(r.gstId), html: r => `${esc(gstBiz(r.gstId))}<small class="muted block mono">${esc(gstLabel(r.gstId))}</small>` },
        { key: 'acc', label: 'Payment Bank Account', val: r => accName(r.accountId) },
        T.date(),
        T.actions(r => iconBtn('pencil', 'edit-purchase', r.id, 'Edit') + iconBtn('trash-2', 'del-purchase', r.id, 'Delete', 'danger'))
      ],
      footer: rs => `<tr><td colspan="3"><b>Total</b></td><td class="num">${num(sum(rs, r => r.qty))}</td><td class="num">${fmt(sum(rs, r => r.amount))}</td><td class="num">${fmt(sum(rs, r => r.gst))}</td><td class="num"><b>${fmt(sum(rs, r => r.total))}</b></td><td colspan="4"></td></tr>`
    })}`;
};
function purchaseForm(pu, preset = {}) {
  const isNew = !pu;
  const suppliers = [...new Set(DB.purchases.map(p => p.supplier))];
  const prod0 = find('products', preset.productId);
  openForm({
    title: isNew ? 'Add Purchase' : `Edit ${pu.id}`, size: 'lg',
    values: pu || { date: todayISO(), productId: preset.productId || '', unitCost: prod0?.purchasePrice ?? '', gstRate: prod0?.gstRate ?? 18, invoiceNo: 'INV/' + randDigits(4) },
    fields: [
      { name: 'supplier', label: 'Supplier', required: true, list: suppliers, placeholder: 'Type or choose a supplier' },
      { name: 'invoiceNo', label: 'Supplier invoice no.' },
      { name: 'productId', label: 'Product', type: 'select', options: optProducts(), required: true, full: true },
      { name: 'qty', label: 'Quantity', type: 'number', min: 1, required: true },
      { name: 'unitCost', label: 'Unit cost (excl. GST)', type: 'number', min: 0, prefix: '₹', required: true },
      { name: 'gstRate', label: 'GST rate', type: 'select', options: [0, 5, 12, 18, 28].map(x => ({ value: x, label: x + '%' })), placeholder: false },
      { name: 'gstId', label: 'GST registration', type: 'select', options: optGsts(), required: true },
      { name: 'accountId', label: 'Payment bank account', type: 'select', options: optAccounts(), required: true },
      { name: 'date', label: 'Purchase date', type: 'date', required: true },
      { name: 'preview', type: 'html', full: true }
    ],
    onChange: (v, form, changed) => {
      if (changed === 'productId') { const p = find('products', v.productId); if (p) { form.elements.unitCost.value = p.purchasePrice; form.elements.gstRate.value = p.gstRate; v.unitCost = p.purchasePrice; v.gstRate = String(p.gstRate); } }
      if (changed === 'gstId' && v.gstId) { const a = gstAccountId(v.gstId); if (a) { form.elements.accountId.value = a; v.accountId = a; } }
      const c = purchaseCalc({ qty: v.qty || 0, unitCost: v.unitCost || 0, gstRate: +v.gstRate || 0 });
      let bal = v.accountId ? balanceOf(v.accountId) : 0;
      if (pu && pu.accountId === v.accountId) bal += purchaseCalc(pu).total;
      $('#f-preview', form).innerHTML = `<div class="preview"><div><span>Purchase amount</span><b>${fmt(c.amount)}</b></div><div><span>GST</span><b>${fmt(c.gst)}</b></div><div><span>Total payable</span><b class="neg">${fmt(c.total)}</b></div>${v.accountId ? `<div><span>Balance after payment</span><b class="${bal - c.total < 0 ? 'neg' : ''}">${fmt(bal - c.total)}</b></div>` : ''}</div>`;
    },
    onSubmit: v => {
      v.gstRate = +v.gstRate;
      let bal = balanceOf(v.accountId);
      if (pu && pu.accountId === v.accountId) bal += purchaseCalc(pu).total;
      const total = purchaseCalc(v).total;
      if (total > bal) return { accountId: `Insufficient balance (${fmt(bal)} available)` };
      if (isNew) DB.purchases.push({ id: nextId('PUR'), ...v }); else Object.assign(pu, v);
      commit(isNew ? `Purchase recorded — stock of ${prodName(v.productId)} updated` : 'Purchase updated');
    }
  });
}

/* ---------------- Sales ---------------- */
const SALE_STATUSES = ['Pending', 'Shipped', 'Delivered', 'Cancelled'];
function genOrderId(platformName) {
  if (platformName === 'Flipkart') return 'OD' + randDigits(15);
  if (platformName === 'Amazon') return `${randDigits(3)}-${randDigits(7)}-${randDigits(7)}`;
  if (platformName === 'Meesho') return randDigits(9) + '_1';
  return (platformName || 'ORD').slice(0, 3).toUpperCase() + '-' + randDigits(8);
}
PAGES.sales = () => {
  const F = pf('sales'); const R = rangeFor(getDF('sales'));
  const rows = DB.sales.filter(s => inRange(s.date, R) && (!F.platform || s.platformId === F.platform) && (!F.gst || s.gstId === F.gst) && (!F.status || s.status === F.status)).map(s => ({ ...s, ...saleCalc(s) }));
  const act = rows.filter(saleActive);
  return `${pageHead('Sales', 'Marketplace orders. Selling prices include GST; commission and shipping are deducted at settlement.', btn('Add Sale', 'add-sale'))}
    <div class="stats-grid s-5">
      ${statCard({ label: 'Order Value', value: fmt(sum(act, r => r.gross)), icon: 'trending-up', tone: 'green', valueTone: 'pos', sub: `${act.length} active orders` })}
      ${statCard({ label: 'GST Collected', value: fmt(sum(act, r => r.gst)), icon: 'percent', tone: 'blue' })}
      ${statCard({ label: 'Commission', value: fmt(sum(act, r => r.commission)), icon: 'store', tone: 'amber', valueTone: 'neg' })}
      ${statCard({ label: 'Shipping Fees', value: fmt(sum(act, r => r.shipping)), icon: 'truck', tone: 'violet', valueTone: 'neg' })}
      ${statCard({ label: 'Settlement Value', value: fmt(sum(act, r => r.settlement)), icon: 'handshake', tone: 'teal' })}
    </div>
    ${table('sales', {
      rows, sort: 'date', file: 'sales',
      filters: filterSelect('sales', 'platform', optPlatforms(), 'All platforms') + filterSelect('sales', 'gst', optGstsShort(), 'All GST registrations') + filterSelect('sales', 'status', opts(SALE_STATUSES), 'All statuses') + dateFilter('sales'),
      columns: [
        { key: 'orderId', label: 'Order ID', html: r => `<b class="mono small">${esc(r.orderId)}</b><small class="muted block">${fmtDate(r.date)}</small>` },
        { key: 'date', label: 'Date', val: r => r.date, html: r => fmtDateShort(r.date) },
        { key: 'platform', label: 'Selling Platform', val: r => platName(r.platformId), html: r => `<span class="dot" style="--c:${find('platforms', r.platformId)?.color || '#999'}"></span>${esc(platName(r.platformId))}` },
        { key: 'gstReg', label: 'GST Registration', val: r => gstBiz(r.gstId) },
        { key: 'product', label: 'Product', val: r => prodName(r.productId) },
        { key: 'qty', label: 'Qty', cls: 'num' },
        T.money('price', 'Selling Price'),
        T.money('gross', 'Order Value'),
        T.money('gst', 'GST Amount'),
        T.money('commission', 'Commission', null, 'neg'),
        T.money('settlement', 'Settlement Amount', null, 'pos'),
        { key: 'status', label: 'Order Status', html: r => badge(r.status) },
        T.actions(r => iconBtn('pencil', 'edit-sale', r.id, 'Edit') + iconBtn('trash-2', 'del-sale', r.id, 'Delete', 'danger'))
      ],
      footer: rs => { const a = rs.filter(saleActive); return `<tr><td colspan="5"><b>Total (excl. cancelled)</b></td><td class="num">${num(sum(a, r => r.qty))}</td><td></td><td class="num"><b>${fmt(sum(a, r => r.gross))}</b></td><td class="num">${fmt(sum(a, r => r.gst))}</td><td class="num">${fmt(sum(a, r => r.commission))}</td><td class="num"><b>${fmt(sum(a, r => r.settlement))}</b></td><td colspan="2"></td></tr>`; }
    })}`;
};
function saleForm(s) {
  const isNew = !s;
  const pl0 = DB.platforms[0];
  let orderTouched = !isNew;
  openForm({
    title: isNew ? 'Add Sale' : `Edit Sale — ${esc(s.orderId)}`, size: 'lg',
    values: s || { date: todayISO(), platformId: pl0?.id, gstId: pl0?.gstIds[0], orderId: genOrderId(pl0?.name), status: 'Pending', qty: 1 },
    fields: [
      { name: 'platformId', label: 'Selling platform', type: 'select', options: optPlatforms(), required: true },
      { name: 'gstId', label: 'GST registration', type: 'select', options: optGsts(s ? find('platforms', s.platformId)?.gstIds : pl0?.gstIds), required: true },
      { name: 'orderId', label: 'Order ID', required: true },
      { name: 'date', label: 'Order date', type: 'date', required: true },
      { name: 'productId', label: 'Product', type: 'select', options: optProducts(), required: true, full: true },
      { name: 'qty', label: 'Quantity', type: 'number', min: 1, required: true },
      { name: 'price', label: 'Selling price / unit (incl. GST)', type: 'number', min: 0, prefix: '₹', required: true },
      { name: 'commission', label: 'Commission', type: 'number', min: 0, prefix: '₹', required: true, hint: 'Auto-calculated — you can override' },
      { name: 'shipping', label: 'Shipping fee', type: 'number', min: 0, prefix: '₹', required: true, hint: 'Auto-calculated — you can override' },
      { name: 'status', label: 'Order status', type: 'select', options: opts(SALE_STATUSES), placeholder: false },
      { name: 'preview', type: 'html', full: true }
    ],
    onChange: (v, form, changed) => {
      const pl = find('platforms', v.platformId);
      if (changed === 'orderId') orderTouched = true;
      if (changed === 'platformId') {
        setOptions(form, 'gstId', optGsts(pl?.gstIds || []));
        if (!form.elements.gstId.value && pl?.gstIds[0]) form.elements.gstId.value = pl.gstIds[0];
        if (!orderTouched) form.elements.orderId.value = genOrderId(pl?.name);
      }
      const p = find('products', v.productId);
      if (changed === 'productId' && p) { form.elements.price.value = p.sellingPrice; v.price = p.sellingPrice; }
      const gross = (v.qty || 0) * (v.price || 0);
      if (pl && ['platformId', 'productId', 'qty', 'price', undefined].includes(changed) && !(changed === undefined && !isNew)) {
        form.elements.commission.value = Math.round(gross * pl.commission / 100);
        form.elements.shipping.value = (v.qty || 0) * pl.shipPerUnit;
      }
      const c = saleCalc({ qty: v.qty || 0, price: v.price || 0, gstRate: p?.gstRate || 0, commission: parseFloat(form.elements.commission.value) || 0, shipping: parseFloat(form.elements.shipping.value) || 0 });
      let stock = p ? productStats(p).stock : 0;
      if (s && p && s.productId === p.id && saleActive(s)) stock += s.qty;
      $('#f-preview', form).innerHTML = p ? `<div class="preview"><div><span>Order value</span><b>${fmt(c.gross)}</b></div><div><span>GST @ ${p.gstRate}%</span><b>${fmt(c.gst)}</b></div><div><span>Settlement amount</span><b class="pos">${fmt(c.settlement)}</b></div><div><span>Stock after sale</span><b class="${stock - (v.qty || 0) < 0 ? 'neg' : ''}">${stock - (v.qty || 0)}</b></div></div>` : '';
    },
    onSubmit: v => {
      const p = find('products', v.productId);
      let stock = productStats(p).stock;
      if (s && s.productId === p.id && saleActive(s)) stock += s.qty;
      if (v.status !== 'Cancelled' && v.qty > stock) return { qty: `Only ${stock} units in stock` };
      const pl = find('platforms', v.platformId);
      if (!pl.gstIds.includes(v.gstId)) return { gstId: `${pl.name} is not linked to this GST registration` };
      const rec = { ...v, gstRate: p.gstRate };
      if (isNew) DB.sales.push({ id: nextId('SAL'), ...rec }); else Object.assign(s, rec);
      commit(isNew ? `Sale ${v.orderId} added — stock & dashboard updated` : 'Sale updated');
    }
  });
}

/* ---------------- Returns & refunds ---------------- */
const RET_STATUSES = ['Requested', 'Approved', 'Received', 'Refunded', 'Rejected'];
const RESTOCK = ['Pending', 'Restocked', 'Damaged'];
PAGES.returns = () => {
  const F = pf('returns'); const R = rangeFor(getDF('returns'));
  const rows = DB.returns.filter(r => inRange(r.date, R)).map(r => { const s = find('sales', r.saleId) || {}; return { ...r, orderId: s.orderId, platformId: s.platformId, gstId: s.gstId, productId: s.productId }; })
    .filter(r => (!F.platform || r.platformId === F.platform) && (!F.status || r.status === F.status));
  const act = rows.filter(returnActive);
  return `${pageHead('Returns & Refunds', 'Customer returns linked to their original orders. Restocked items go back into inventory.', btn('Add Return', 'add-return'))}
    <div class="stats-grid s-4">
      ${statCard({ label: 'Returns', value: act.length, icon: 'undo-2', tone: 'amber', sub: `${num(sum(act, r => r.qty))} units` })}
      ${statCard({ label: 'Refund Value', value: fmt(sum(act, r => r.refund)), icon: 'indian-rupee', tone: 'red', valueTone: 'neg' })}
      ${statCard({ label: 'Units Restocked', value: num(sum(act.filter(r => r.restock === 'Restocked'), r => r.qty)), icon: 'package-check', tone: 'green' })}
      ${statCard({ label: 'Awaiting Action', value: act.filter(r => r.restock === 'Pending' || r.status === 'Requested').length, icon: 'clock', tone: 'blue', sub: 'Pending restock or approval' })}
    </div>
    ${table('returns', {
      rows, sort: 'date', file: 'returns-refunds',
      filters: filterSelect('returns', 'platform', optPlatforms(), 'All platforms') + filterSelect('returns', 'status', opts(RET_STATUSES), 'All statuses') + dateFilter('returns'),
      columns: [
        { key: 'id', label: 'Return ID', html: r => `<b>${r.id}</b><small class="muted block">${fmtDate(r.date)}</small>` },
        { key: 'orderId', label: 'Original Order ID', html: r => `<span class="mono small">${esc(r.orderId)}</span>` },
        { key: 'platform', label: 'Platform', val: r => platName(r.platformId) },
        { key: 'gstReg', label: 'GST Registration', val: r => gstBiz(r.gstId) },
        { key: 'product', label: 'Product', val: r => prodName(r.productId), html: r => `${esc(prodName(r.productId))}<small class="muted block">${esc(r.reason || '')}</small>` },
        { key: 'qty', label: 'Qty', cls: 'num' },
        T.money('refund', 'Refund Amount', null, 'neg'),
        { key: 'status', label: 'Return Status', html: r => badge(r.status) },
        { key: 'restock', label: 'Restock Status', html: r => badge(r.restock) },
        T.actions(r => (r.restock === 'Pending' && returnActive(r) ? iconBtn('package-check', 'restock-return', r.id, 'Mark restocked', 'success') : '') + iconBtn('pencil', 'edit-return', r.id, 'Edit') + iconBtn('trash-2', 'del-return', r.id, 'Delete', 'danger'))
      ]
    })}`;
};
function returnForm(r) {
  const isNew = !r;
  const saleOpts = DB.sales.filter(saleActive).sort((a, b) => b.date.localeCompare(a.date)).map(s => ({ value: s.id, label: `${s.orderId} · ${platName(s.platformId)} · ${prodName(s.productId)} × ${s.qty}` }));
  const returnedQty = (saleId) => sum(DB.returns.filter(x => x.saleId === saleId && returnActive(x) && x.id !== r?.id), x => x.qty);
  let refundTouched = !isNew;
  openForm({
    title: isNew ? 'Add Return' : `Edit ${r.id}`, size: 'lg', values: r || { date: todayISO(), status: 'Requested', restock: 'Pending', qty: 1 },
    fields: [
      { name: 'saleId', label: 'Original order', type: 'select', options: saleOpts, required: true, full: true },
      { name: 'info', type: 'html', full: true },
      { name: 'qty', label: 'Return quantity', type: 'number', min: 1, required: true },
      { name: 'refund', label: 'Refund amount', type: 'number', min: 0, prefix: '₹', required: true },
      { name: 'reason', label: 'Reason', type: 'select', options: opts(['Size issue', 'Defective item', 'Wrong item delivered', 'Wrong size delivered', 'Colour mismatch', 'Customer changed mind', 'Damaged in transit', 'Other']) },
      { name: 'date', label: 'Return date', type: 'date', required: true },
      { name: 'status', label: 'Return status', type: 'select', options: opts(RET_STATUSES), placeholder: false },
      { name: 'restock', label: 'Restock status', type: 'select', options: opts(RESTOCK), placeholder: false }
    ],
    onChange: (v, form, changed) => {
      if (changed === 'refund') refundTouched = true;
      const s = find('sales', v.saleId);
      $('#f-info', form).innerHTML = s ? `<div class="preview"><div><span>Platform</span><b>${esc(platName(s.platformId))}</b></div><div><span>GST registration</span><b>${esc(gstBiz(s.gstId))}</b></div><div><span>Product</span><b>${esc(prodName(s.productId))}</b></div><div><span>Returnable qty</span><b>${s.qty - returnedQty(s.id)} of ${s.qty}</b></div></div>` : '';
      if (s && !refundTouched) form.elements.refund.value = s.price * (v.qty || 0);
    },
    onSubmit: v => {
      const s = find('sales', v.saleId);
      const max = s.qty - returnedQty(s.id);
      if (v.qty > max) return { qty: `Only ${max} units can still be returned for this order` };
      if (v.refund > s.price * v.qty) return { refund: `Refund can't exceed ${fmt(s.price * v.qty)}` };
      if (isNew) DB.returns.push({ id: nextId('RET'), ...v }); else Object.assign(r, v);
      commit(isNew ? 'Return recorded' : 'Return updated');
    }
  });
}

/* ---------------- Expenses ---------------- */
const EXP_CATEGORIES = ['Product Purchase', 'Shipping', 'Packaging', 'Marketplace Commission', 'Advertising', 'Marketing', 'Salary', 'Rent', 'Electricity', 'Transportation', 'Bank Charges', 'Miscellaneous'];
PAGES.expenses = () => {
  const F = pf('expenses'); const R = rangeFor(getDF('expenses'));
  const rows = DB.expenses.filter(x => inRange(x.date, R) && (!F.category || x.category === F.category) && (!F.gst || x.gstId === F.gst));
  const byCat = EXP_CATEGORIES.map(c => ({ c, v: sum(rows.filter(x => x.category === c), x => x.amount) })).filter(x => x.v > 0).sort((a, b) => b.v - a.v);
  const m = rangeFor({ preset: 'month' });
  return `${pageHead('Expenses', 'Business expenses paid from bank accounts, by category and GST registration.', btn('Add Expense', 'add-expense'))}
    <div class="stats-grid s-4">
      ${statCard({ label: 'Total Expenses', value: fmt(sum(rows, x => x.amount)), icon: 'wallet', tone: 'red', valueTone: 'neg', sub: `${rows.length} records in filter` })}
      ${statCard({ label: 'This Month', value: fmt(sum(DB.expenses.filter(x => inRange(x.date, m)), x => x.amount)), icon: 'calendar', tone: 'amber' })}
      ${statCard({ label: 'Top Category', value: byCat[0] ? esc(byCat[0].c) : '—', icon: 'tag', tone: 'violet', sub: byCat[0] ? fmt(byCat[0].v) : '' })}
      ${statCard({ label: 'Average Expense', value: fmt(rows.length ? sum(rows, x => x.amount) / rows.length : 0), icon: 'divide', tone: 'blue' })}
    </div>
    <div class="grid g-1-2">
      ${panel('By Category', byCat.length ? chartDoughnut('ch-expcat', byCat.map(x => x.c), byCat.map(x => x.v), [COLORS.red, COLORS.amber, COLORS.violet, COLORS.blue, COLORS.teal, COLORS.pink, COLORS.navy, COLORS.slate, '#ea580c', '#65a30d', '#0891b2', '#9333ea']) : emptyState('No expenses in this filter.'), { icon: 'chart-pie' })}
      ${panel('Monthly Expenses', (() => { const ms = lastMonths(6); return chartBar('ch-expm', ms.map(x => x.label), [{ label: 'Expenses', data: ms.map(mm => sum(DB.expenses.filter(x => inRange(x.date, mm) && (!F.category || x.category === F.category) && (!F.gst || x.gstId === F.gst)), x => x.amount)), backgroundColor: COLORS.red }]); })(), { icon: 'chart-column' })}
    </div>
    ${table('expenses', {
      rows, sort: 'date', file: 'expenses',
      filters: filterSelect('expenses', 'category', opts(EXP_CATEGORIES), 'All categories') + filterSelect('expenses', 'gst', optGstsShort(), 'All GST registrations') + dateFilter('expenses'),
      columns: [
        { key: 'id', label: 'Expense ID', html: r => `<b>${r.id}</b>` },
        { key: 'category', label: 'Category', html: r => `<span class="chip">${esc(r.category)}</span>` },
        { key: 'desc', label: 'Description' },
        { key: 'gstReg', label: 'GST Registration', val: r => gstBiz(r.gstId) },
        { key: 'acc', label: 'Paid From Bank Account', val: r => accName(r.accountId) },
        T.money('amount', 'Amount', null, 'neg'),
        T.date(),
        T.actions(r => iconBtn('pencil', 'edit-expense', r.id, 'Edit') + iconBtn('trash-2', 'del-expense', r.id, 'Delete', 'danger'))
      ],
      footer: rs => `<tr><td colspan="5"><b>Total</b></td><td class="num"><b>${fmt(sum(rs, r => r.amount))}</b></td><td colspan="2"></td></tr>`
    })}`;
};
function expenseForm(x) {
  const isNew = !x;
  openForm({
    title: isNew ? 'Add Expense' : `Edit ${x.id}`, values: x || { date: todayISO(), category: 'Shipping' },
    fields: [
      { name: 'category', label: 'Category', type: 'select', options: opts(EXP_CATEGORIES), required: true },
      { name: 'amount', label: 'Amount', type: 'number', min: 1, prefix: '₹', required: true },
      { name: 'desc', label: 'Description', required: true, full: true, placeholder: 'e.g. Courier pickup charges' },
      { name: 'gstId', label: 'GST registration', type: 'select', options: optGsts(), required: true },
      { name: 'accountId', label: 'Paid from bank account', type: 'select', options: optAccounts(), required: true },
      { name: 'date', label: 'Date', type: 'date', required: true },
      { name: 'note', type: 'html', full: true }
    ],
    onChange: (v, form, changed) => {
      if (changed === 'gstId' && v.gstId) { const a = gstAccountId(v.gstId); if (a) form.elements.accountId.value = a; }
      $('#f-note', form).innerHTML = v.category === 'Product Purchase' ? `<div class="form-note"><i data-lucide="info"></i><span>“Product Purchase” is treated as inventory, so it is excluded from operating expenses in P&L. For stock items, prefer the Purchases page so inventory updates.</span></div>`
        : v.category === 'Marketplace Commission' ? `<div class="form-note"><i data-lucide="info"></i><span>Shown under Marketplace Charges in P&L (not operating expenses).</span></div>` : '';
      icons();
    },
    onSubmit: v => {
      let bal = balanceOf(v.accountId);
      if (x && x.accountId === v.accountId) bal += x.amount;
      if (v.amount > bal) return { amount: `Insufficient balance — account has ${fmt(bal)}` };
      if (isNew) DB.expenses.push({ id: nextId('EXP'), ...v }); else Object.assign(x, v);
      commit(isNew ? `Expense of ${fmt(v.amount)} added` : 'Expense updated');
    }
  });
}

/* ---------------- Settlements ---------------- */
PAGES.settlements = () => {
  const F = pf('settlements');
  const rows = DB.settlements.filter(s => (!F.platform || s.platformId === F.platform) && (!F.status || s.status === F.status));
  const recv = DB.settlements.filter(s => s.status === 'Received'), pend = DB.settlements.filter(s => s.status !== 'Received');
  const unsettled = [];
  DB.platforms.forEach(p => p.gstIds.forEach(g => {
    const ss = DB.sales.filter(s => s.platformId === p.id && s.gstId === g && saleActive(s) && !isSaleSettled(s));
    if (ss.length) unsettled.push({ p, g, n: ss.length, v: sum(ss, s => saleCalc(s).settlement), from: ss.map(s => s.date).sort()[0], to: ss.map(s => s.date).sort().pop() });
  }));
  return `${pageHead('Settlements', 'Marketplace payouts: gross sales less returns, commission, shipping and other deductions.', btn('New Settlement', 'add-settlement'))}
    <div class="stats-grid s-4">
      ${statCard({ label: 'Received', value: fmt(sum(recv, s => s.net)), icon: 'circle-check', tone: 'green', valueTone: 'pos', sub: `${recv.length} payouts credited to bank` })}
      ${statCard({ label: 'Pending', value: fmt(sum(pend, s => s.net)), icon: 'clock', tone: 'amber', sub: `${pend.length} awaiting payout` })}
      ${statCard({ label: 'Total Deductions', value: fmt(sum(DB.settlements, s => s.commission + s.shipping + s.other)), icon: 'scissors', tone: 'red', valueTone: 'neg', sub: 'Commission, shipping & other' })}
      ${statCard({ label: 'Unsettled Orders', value: fmt(sum(unsettled, u => u.v)), icon: 'hourglass', tone: 'blue', sub: `${sum(unsettled, u => u.n)} orders not yet in a settlement` })}
    </div>
    ${unsettled.length ? panel('Ready to settle', `<div class="list-cols">${unsettled.map(u => `<div class="list-row"><div class="lr-icon" style="background:${alpha(u.p.color, .12)};color:${u.p.color}"><i data-lucide="store"></i></div><div class="lr-main"><b>${esc(u.p.name)} · ${esc(gstBiz(u.g))}</b><small>${u.n} orders · ${fmtDateShort(u.from)} – ${fmtDateShort(u.to)}</small></div><div class="lr-amt">${money(u.v)}<button class="btn btn-ghost btn-xs" data-action="settle-now" data-id="${u.p.id}|${u.g}|${u.from}|${u.to}">Create</button></div></div>`).join('')}</div>`, { icon: 'hourglass' }) : ''}
    ${table('settlements', {
      rows, sort: 'to', file: 'settlements',
      filters: filterSelect('settlements', 'platform', optPlatforms(), 'All platforms') + filterSelect('settlements', 'status', opts(['Received', 'Pending']), 'All statuses'),
      columns: [
        { key: 'id', label: 'Settlement ID', html: r => `<b>${r.id}</b><small class="muted block">${fmtDateShort(r.from)} – ${fmtDate(r.to)}</small>` },
        { key: 'to', label: 'Period End', val: r => r.to, html: r => fmtDate(r.to) },
        { key: 'platform', label: 'Platform', val: r => platName(r.platformId) },
        { key: 'gstReg', label: 'GST Registration', val: r => gstBiz(r.gstId) },
        T.money('gross', 'Gross Sales'), T.money('returns', 'Returns', null, 'neg'), T.money('commission', 'Commission', null, 'neg'),
        T.money('shipping', 'Shipping Charges', null, 'neg'), T.money('other', 'Other Deductions', null, 'neg'),
        { key: 'net', label: 'Net Settlement', cls: 'num', val: r => r.net, html: r => `<b>${money(r.net, 'pos')}</b>` },
        { key: 'acc', label: 'Bank Account', val: r => accName(r.accountId) },
        { key: 'status', label: 'Status', html: r => badge(r.status) + (r.settledOn ? `<small class="muted block">${fmtDate(r.settledOn)}</small>` : '') },
        T.actions(r => (r.status !== 'Received' ? iconBtn('circle-check', 'receive-settlement', r.id, 'Mark received', 'success') : '') + iconBtn('eye', 'view-settlement', r.id, 'View') + iconBtn('trash-2', 'del-settlement', r.id, 'Delete', 'danger'))
      ],
      footer: rs => `<tr><td colspan="4"><b>Total</b></td><td class="num">${fmt(sum(rs, r => r.gross))}</td><td class="num">${fmt(sum(rs, r => r.returns))}</td><td class="num">${fmt(sum(rs, r => r.commission))}</td><td class="num">${fmt(sum(rs, r => r.shipping))}</td><td class="num">${fmt(sum(rs, r => r.other))}</td><td class="num"><b>${fmt(sum(rs, r => r.net))}</b></td><td colspan="3"></td></tr>`
    })}`;
};
function settlementForm(preset = {}) {
  const pl0 = find('platforms', preset.platformId) || DB.platforms[0];
  openForm({
    title: 'New Settlement', size: 'lg',
    note: 'Figures are calculated from the sales and returns recorded for the chosen platform, GST registration and period.',
    values: { platformId: pl0?.id, gstId: preset.gstId || pl0?.gstIds[0], from: preset.from || addDays(todayISO(), -30), to: preset.to || todayISO(), status: 'Pending', settledOn: todayISO(), utr: 'SETLDEMO' + randDigits(8) },
    fields: [
      { name: 'platformId', label: 'Platform', type: 'select', options: optPlatforms(), required: true },
      { name: 'gstId', label: 'GST registration', type: 'select', options: optGsts(pl0?.gstIds), required: true },
      { name: 'from', label: 'Period from', type: 'date', required: true },
      { name: 'to', label: 'Period to', type: 'date', required: true },
      { name: 'calc', type: 'html', full: true },
      { name: 'other', label: 'Other deductions (TCS, fees)', type: 'number', min: 0, prefix: '₹', hint: 'Auto: 0.5% TCS + ₹10 per order' },
      { name: 'accountId', label: 'Credit to bank account', type: 'select', options: optAccounts(a => a.type === 'Business'), required: true },
      { name: 'status', label: 'Status', type: 'select', options: opts(['Pending', 'Received']), placeholder: false },
      { name: 'settledOn', label: 'Payout date', type: 'date' },
      { name: 'utr', label: 'Payout UTR / reference' }
    ],
    onChange: (v, form, changed) => {
      const pl = find('platforms', v.platformId);
      if (changed === 'platformId') { setOptions(form, 'gstId', optGsts(pl?.gstIds || [])); if (!form.elements.gstId.value && pl?.gstIds[0]) form.elements.gstId.value = pl.gstIds[0]; v.gstId = form.elements.gstId.value; }
      if ((changed === 'gstId' || changed === 'platformId' || changed === undefined) && v.gstId) { const a = gstAccountId(v.gstId); if (a) form.elements.accountId.value = a; }
      const c = v.platformId && v.gstId && v.from && v.to ? calcSettlement(v.platformId, v.gstId, v.from, v.to) : null;
      if (c && changed !== 'other') form.elements.other.value = c.other;
      const other = parseFloat(form.elements.other.value) || 0;
      $('#f-calc', form).innerHTML = c ? `<div class="settle-calc"><div><span>Orders</span><b>${c.orders}</b></div><div><span>Gross sales</span><b>${fmt(c.gross)}</b></div><div><span>− Returns</span><b class="neg">${fmt(c.returns)}</b></div><div><span>− Commission</span><b class="neg">${fmt(c.commission)}</b></div><div><span>− Shipping</span><b class="neg">${fmt(c.shipping)}</b></div><div><span>− Other</span><b class="neg">${fmt(other)}</b></div><div class="total"><span>Net settlement</span><b class="pos">${fmt(c.gross - c.returns - c.commission - c.shipping - other)}</b></div></div>`
        : '<p class="muted">Choose a platform, GST registration and period.</p>';
    },
    onSubmit: v => {
      if (v.from > v.to) return { to: 'End date must be after start date' };
      const c = calcSettlement(v.platformId, v.gstId, v.from, v.to);
      if (!c.orders) return 'No active orders found for this platform, GST registration and period.';
      const overlap = DB.settlements.find(s => s.platformId === v.platformId && s.gstId === v.gstId && !(v.to < s.from || v.from > s.to));
      if (overlap) return `This period overlaps ${overlap.id} (${fmtDate(overlap.from)} – ${fmtDate(overlap.to)}).`;
      const other = isNaN(v.other) ? c.other : v.other;
      DB.settlements.push({ id: nextId('STL'), platformId: v.platformId, gstId: v.gstId, from: v.from, to: v.to, gross: c.gross, returns: c.returns, commission: c.commission, shipping: c.shipping, other, net: c.gross - c.returns - c.commission - c.shipping - other, accountId: v.accountId, status: v.status, settledOn: v.status === 'Received' ? (v.settledOn || todayISO()) : '', utr: v.status === 'Received' ? v.utr : '' });
      commit(v.status === 'Received' ? 'Settlement received — bank balance updated' : 'Settlement created');
    }
  });
}
function viewSettlement(s) {
  const sales = DB.sales.filter(x => x.platformId === s.platformId && x.gstId === s.gstId && saleActive(x) && x.date >= s.from && x.date <= s.to);
  openModal({
    title: `Settlement ${s.id}`, size: 'lg', body: `
    <div class="detail-head"><div class="lr-icon big tone-teal"><i data-lucide="handshake"></i></div><div><h2>${esc(platName(s.platformId))} payout</h2><p class="muted">${esc(gstBiz(s.gstId))} · ${fmtDate(s.from)} – ${fmtDate(s.to)}</p></div>${badge(s.status)}</div>
    <div class="settle-calc"><div><span>Gross sales</span><b>${fmt(s.gross)}</b></div><div><span>− Returns</span><b class="neg">${fmt(s.returns)}</b></div><div><span>− Commission</span><b class="neg">${fmt(s.commission)}</b></div><div><span>− Shipping</span><b class="neg">${fmt(s.shipping)}</b></div><div><span>− Other deductions</span><b class="neg">${fmt(s.other)}</b></div><div class="total"><span>Net settlement</span><b class="pos">${fmt(s.net)}</b></div></div>
    <p class="muted small">Bank account: <b>${esc(accName(s.accountId))}</b>${s.settledOn ? ` · Paid on ${fmtDate(s.settledOn)} · UTR <span class="mono">${esc(s.utr)}</span>` : ''}</p>
    <h4 class="section-title">Orders in this settlement (${sales.length})</h4>
    <div class="table-wrap"><table class="data-table compact"><thead><tr><th>Order</th><th>Date</th><th>Product</th><th class="num">Value</th><th class="num">Commission</th><th class="num">Shipping</th></tr></thead><tbody>${sales.map(x => `<tr><td class="mono small">${esc(x.orderId)}</td><td>${fmtDate(x.date)}</td><td>${esc(prodName(x.productId))} × ${x.qty}</td><td class="num">${fmt(x.price * x.qty)}</td><td class="num">${fmt(x.commission)}</td><td class="num">${fmt(x.shipping)}</td></tr>`).join('') || `<tr><td colspan="6">${emptyState('No orders.')}</td></tr>`}</tbody></table></div>`,
    footer: `<button class="btn btn-ghost" data-close-modal>Close</button>${s.status !== 'Received' ? `<button class="btn btn-success" data-action="receive-settlement" data-id="${s.id}"><i data-lucide="circle-check"></i><span>Mark as Received</span></button>` : ''}`
  });
}

/* ---------------- Profit & Loss ---------------- */
function plRows(tab, R, F) {
  const end = R.to || todayISO();
  if (tab === 'daily') return Array.from({ length: 14 }, (_, i) => { const d = addDays(end, i - 13); return { label: fmtDate(d), ...computePL({ from: d, to: d, ...F }) }; });
  if (tab === 'weekly') {
    const dow = (parseISO(end).getDay() + 6) % 7; const thisMon = addDays(end, -dow);
    return Array.from({ length: 8 }, (_, i) => { const from = addDays(thisMon, (i - 7) * 7); const to = addDays(from, 6); return { label: `${fmtDateShort(from)} – ${fmtDateShort(to)}`, ...computePL({ from, to, ...F }) }; });
  }
  if (tab === 'monthly') return lastMonths(12, end).map(m => ({ label: m.label, ...computePL({ from: m.from, to: m.to, ...F }) }));
  if (tab === 'gst') return DB.gsts.filter(g => !F.gstId || g.id === F.gstId).map(g => ({ label: g.businessName, sub: g.gstin, ...computePL({ ...R, gstId: g.id, platformId: F.platformId }) }));
  return DB.platforms.filter(p => !F.platformId || p.id === F.platformId).map(p => ({ label: p.name, ...computePL({ ...R, platformId: p.id, gstId: F.gstId }) }));
}
const PL_TABS = [['daily', 'Daily'], ['weekly', 'Weekly'], ['monthly', 'Monthly'], ['gst', 'GST-wise'], ['platform', 'Platform-wise']];
PAGES.pl = () => {
  const R = rangeFor(getDF('pl', 'year')); const F0 = pf('pl'); const F = { gstId: F0.gst || '', platformId: F0.platform || '' };
  const pl = computePL({ ...R, ...F });
  const line = (label, v, cls = '', sub = '') => `<div class="pl-line ${cls}"><span>${label}${sub ? `<small>${sub}</small>` : ''}</span><b class="${v < 0 ? 'neg' : ''}">${fmt(v)}</b></div>`;
  const rows = plRows(UI.plTab, R, F);
  const periodTab = ['daily', 'weekly', 'monthly'].includes(UI.plTab);
  return `${pageHead('Profit & Loss', `Statement for <b>${rangeText(R)}</b>${F.gstId ? ` · ${esc(gstBiz(F.gstId))}` : ''}${F.platformId ? ` · ${esc(platName(F.platformId))}` : ''}`, btn('Export CSV', 'export-pl', { kind: 'ghost', icon: 'download' }))}
    <div class="toolbar-row">${dateFilter('pl', 'year', true)}${filterSelect('pl', 'gst', optGstsShort(), 'All GST registrations')}${filterSelect('pl', 'platform', optPlatforms(), 'All platforms')}</div>
    <div class="stats-grid s-4">
      ${statCard({ label: 'Net Sales', value: fmt(pl.netSales), icon: 'trending-up', tone: 'green', sub: `${pl.orders} orders · excl. GST` })}
      ${statCard({ label: 'Gross Profit', value: fmt(pl.grossProfit), icon: 'layers', tone: 'blue', sub: `${pct(pl.netSales ? pl.grossProfit / pl.netSales * 100 : 0)} gross margin`, valueTone: pl.grossProfit >= 0 ? 'pos' : 'neg' })}
      ${statCard({ label: 'Total Charges & Expenses', value: fmt(pl.marketplace + pl.opex), icon: 'receipt', tone: 'red', valueTone: 'neg' })}
      ${statCard({ label: pl.net >= 0 ? 'Net Profit' : 'Net Loss', value: fmt(pl.net), icon: pl.net >= 0 ? 'badge-indian-rupee' : 'trending-down', tone: pl.net >= 0 ? 'green' : 'red', valueTone: pl.net >= 0 ? 'pos' : 'neg', sub: `${pct(pl.margin)} net margin` })}
    </div>
    <div class="grid g-1-1">
      <section class="panel"><div class="panel-head"><h3><i data-lucide="scale"></i>Profit & Loss Statement</h3><span class="muted small">${rangeText(R)}</span></div><div class="panel-body pl-statement">
        ${line('Gross Sales', pl.grossSales, '', 'excl. GST of ' + fmt(pl.outputGst))}
        ${line('Less: Sales Returns', -pl.salesReturns, 'indent')}
        ${line('Net Sales', pl.netSales, 'subtotal')}
        ${line('Less: Cost of Goods Sold', -pl.cogs, 'indent', 'purchase cost of units sold, net of restocked returns')}
        ${line('Gross Profit', pl.grossProfit, 'subtotal')}
        ${line('Less: Marketplace Charges', -pl.marketplace, 'indent', `commission ${fmt(pl.commission)} · shipping ${fmt(pl.shipping)}${pl.mpExpenses ? ' · other ' + fmt(pl.mpExpenses) : ''}`)}
        ${line('Less: Operational Expenses', -pl.opex, 'indent', pl.opexAllocated ? '' : 'not allocated to platforms')}
        ${Object.entries(pl.opexByCat).sort((a, b) => b[1] - a[1]).map(([c, v]) => line(c, -v, 'indent2')).join('')}
        ${line(pl.net >= 0 ? 'Net Profit' : 'Net Loss', pl.net, 'total ' + (pl.net >= 0 ? 'profit' : 'loss'))}
        <div class="form-note"><i data-lucide="info"></i><span>Inventory purchased in this period: <b>${fmt(pl.inventoryPurchased)}</b>. It sits in stock and is <b>not</b> expensed — only the cost of goods actually sold (<b>${fmt(pl.cogs)}</b>) reduces profit. Internal bank transfers and partner capital are excluded.</span></div>
      </div></section>
      ${panel('Where the money went', chartBar('ch-plw', ['Net Sales', 'COGS', 'Marketplace', 'Operating Exp.', pl.net >= 0 ? 'Net Profit' : 'Net Loss'], [{ label: 'Amount', data: [pl.netSales, pl.cogs, pl.marketplace, pl.opex, pl.net], backgroundColor: [COLORS.blue, COLORS.violet, COLORS.amber, COLORS.red, pl.net >= 0 ? COLORS.green : COLORS.red] }]), { icon: 'chart-column' })}
    </div>
    <div class="tabs">${PL_TABS.map(([v, l]) => `<button class="tab ${UI.plTab === v ? 'active' : ''}" data-action="pl-tab" data-id="${v}">${l} Report</button>`).join('')}</div>
    ${periodTab ? panel(`${PL_TABS.find(t => t[0] === UI.plTab)[1]} trend`, chartBar('ch-pltrend', rows.map(r => r.label), [{ label: 'Net Sales', data: rows.map(r => r.netSales), backgroundColor: alpha(COLORS.blue, .75) }, { label: 'Net Profit/Loss', data: rows.map(r => r.net), backgroundColor: rows.map(r => r.net >= 0 ? COLORS.green : COLORS.red) }]), { icon: 'activity' }) : ''}
    ${table('pl-' + UI.plTab, {
      rows: rows.map((r, i) => ({ ...r, id: String(i), order: i })), sort: 'order', dir: 'asc', noPaging: true, file: `pl-${UI.plTab}`, columns: [
        { key: 'order', label: UI.plTab === 'gst' ? 'GST Registration' : UI.plTab === 'platform' ? 'Platform' : 'Period', val: r => r.order, csv: r => r.label, search: r => r.label, html: r => `<b>${esc(r.label)}</b>${r.sub ? `<small class="muted block mono">${esc(r.sub)}</small>` : ''}` },
        { key: 'orders', label: 'Orders', cls: 'num' },
        T.money('grossSales', 'Gross Sales'), T.money('salesReturns', 'Returns', null, 'neg'), T.money('netSales', 'Net Sales'), T.money('cogs', 'COGS'),
        T.money('grossProfit', 'Gross Profit'), T.money('marketplace', 'Marketplace Charges', null, 'neg'),
        { key: 'opex', label: 'Operational Exp.', cls: 'num', val: r => r.opexAllocated ? r.opex : 0, html: r => r.opexAllocated ? money(r.opex, 'neg') : '<span class="muted" title="Expenses are not allocated to platforms">n/a</span>' },
        { key: 'net', label: UI.plTab === 'platform' ? 'Contribution' : 'Net Profit/Loss', cls: 'num', val: r => r.net, html: r => `<b>${moneySigned(r.net)}</b>` },
        { key: 'margin', label: 'Margin', cls: 'num', val: r => round2(r.margin), html: r => pct(r.margin) }
      ],
      footer: rs => `<tr><td><b>Total</b></td><td class="num">${sum(rs, r => r.orders)}</td><td class="num">${fmt(sum(rs, r => r.grossSales))}</td><td class="num">${fmt(sum(rs, r => r.salesReturns))}</td><td class="num">${fmt(sum(rs, r => r.netSales))}</td><td class="num">${fmt(sum(rs, r => r.cogs))}</td><td class="num">${fmt(sum(rs, r => r.grossProfit))}</td><td class="num">${fmt(sum(rs, r => r.marketplace))}</td><td class="num">${UI.plTab === 'platform' ? '—' : fmt(sum(rs, r => r.opex))}</td><td class="num"><b>${moneySigned(sum(rs, r => r.net))}</b></td><td></td></tr>`
    })}
    ${UI.plTab === 'platform' ? '<p class="muted small">Platform-wise figures show the contribution of each marketplace before operating expenses, which are shared across the business.</p>' : ''}`;
};

/* ---------------- Financial reports ---------------- */
const REPORTS = {
  sales: { label: 'Sales Register', icon: 'indian-rupee', applies: ['date', 'gst', 'partner', 'account', 'platform'] },
  purchases: { label: 'Purchase Register', icon: 'shopping-cart', applies: ['date', 'gst', 'partner', 'account'] },
  expenses: { label: 'Expense Register', icon: 'wallet', applies: ['date', 'gst', 'partner', 'account'] },
  returns: { label: 'Returns Register', icon: 'undo-2', applies: ['date', 'gst', 'partner', 'platform'] },
  bank: { label: 'Bank Transactions', icon: 'landmark', applies: ['date', 'gst', 'partner', 'account'] },
  settlements: { label: 'Settlement Register', icon: 'handshake', applies: ['date', 'gst', 'partner', 'account', 'platform'] },
  capital: { label: 'Partner Capital', icon: 'users', applies: ['date', 'partner'] },
  gstsum: { label: 'GST Summary', icon: 'file-badge', applies: ['date', 'gst', 'partner'] }
};
function reportData(type, F, R) {
  const ok = (o) => {
    if (o.date !== undefined && !inRange(o.date, R)) return false;
    if (F.gst && o.gstId !== undefined && o.gstId !== F.gst) return false;
    if (F.partner) { const pid = o.partnerId !== undefined ? o.partnerId : gstOf(o.gstId)?.partnerId; if (pid !== F.partner) return false; }
    if (F.account && o.accountId !== undefined && o.accountId !== F.account) return false;
    if (F.platform && o.platformId !== undefined && o.platformId !== F.platform) return false;
    return true;
  };
  switch (type) {
    case 'sales': {
      const rows = DB.sales.filter(s => ok({ date: s.date, gstId: s.gstId, accountId: gstAccountId(s.gstId), platformId: s.platformId })).map(s => ({ ...s, ...saleCalc(s) }));
      const a = rows.filter(saleActive);
      return {
        rows, summary: [['Orders', a.length], ['Order value', fmt(sum(a, r => r.gross))], ['Taxable value', fmt(sum(a, r => r.taxable))], ['GST', fmt(sum(a, r => r.gst))], ['Settlement value', fmt(sum(a, r => r.settlement))]], columns: [
          T.date(), { key: 'orderId', label: 'Order ID' }, { key: 'pl', label: 'Platform', val: r => platName(r.platformId) }, { key: 'gstin', label: 'GSTIN', val: r => gstLabel(r.gstId) },
          { key: 'pr', label: 'Product', val: r => prodName(r.productId) }, { key: 'qty', label: 'Qty', cls: 'num' }, T.money('taxable', 'Taxable'), T.money('gst', 'GST'), T.money('gross', 'Order Value'),
          T.money('commission', 'Commission'), T.money('shipping', 'Shipping'), T.money('settlement', 'Settlement'), { key: 'status', label: 'Status', html: r => badge(r.status) }]
      };
    }
    case 'purchases': {
      const rows = DB.purchases.filter(p => ok({ date: p.date, gstId: p.gstId, accountId: p.accountId })).map(p => ({ ...p, ...purchaseCalc(p) }));
      return {
        rows, summary: [['Records', rows.length], ['Taxable', fmt(sum(rows, r => r.amount))], ['Input GST', fmt(sum(rows, r => r.gst))], ['Total paid', fmt(sum(rows, r => r.total))]], columns: [
          T.date(), { key: 'id', label: 'Purchase ID' }, { key: 'supplier', label: 'Supplier' }, { key: 'pr', label: 'Product', val: r => prodName(r.productId) }, { key: 'qty', label: 'Qty', cls: 'num' },
          T.money('amount', 'Taxable'), T.money('gst', 'GST'), T.money('total', 'Total'), { key: 'gstin', label: 'GSTIN', val: r => gstLabel(r.gstId) }, { key: 'acc', label: 'Paid From', val: r => accName(r.accountId) }]
      };
    }
    case 'expenses': {
      const rows = DB.expenses.filter(x => ok({ date: x.date, gstId: x.gstId, accountId: x.accountId }));
      return {
        rows, summary: [['Records', rows.length], ['Total', fmt(sum(rows, r => r.amount))], ['Categories', new Set(rows.map(r => r.category)).size]], columns: [
          T.date(), { key: 'id', label: 'Expense ID' }, { key: 'category', label: 'Category' }, { key: 'desc', label: 'Description' }, { key: 'gstin', label: 'GST Registration', val: r => gstBiz(r.gstId) },
          { key: 'acc', label: 'Paid From', val: r => accName(r.accountId) }, T.money('amount', 'Amount')]
      };
    }
    case 'returns': {
      const rows = DB.returns.map(r => ({ ...r, s: find('sales', r.saleId) || {} })).filter(r => ok({ date: r.date, gstId: r.s.gstId, platformId: r.s.platformId }));
      return {
        rows, summary: [['Returns', rows.length], ['Units', num(sum(rows, r => r.qty))], ['Refunds', fmt(sum(rows.filter(returnActive), r => r.refund))]], columns: [
          T.date(), { key: 'id', label: 'Return ID' }, { key: 'order', label: 'Order ID', val: r => r.s.orderId }, { key: 'pl', label: 'Platform', val: r => platName(r.s.platformId) },
          { key: 'gstin', label: 'GSTIN', val: r => gstLabel(r.s.gstId) }, { key: 'pr', label: 'Product', val: r => prodName(r.s.productId) }, { key: 'qty', label: 'Qty', cls: 'num' }, T.money('refund', 'Refund'),
          { key: 'status', label: 'Status' }, { key: 'restock', label: 'Restock' }]
      };
    }
    case 'bank': {
      const rows = ledgerEntries().map((e, i) => ({ ...e, id: 'e' + i, gstId: find('accounts', e.accountId)?.gstId || '' }))
        .filter(e => ok({ date: e.date, accountId: e.accountId, ...(F.gst || F.partner ? { gstId: e.gstId } : {}) }));
      return {
        rows, summary: [['Entries', rows.length], ['Credits', fmt(sum(rows, r => r.credit))], ['Debits', fmt(sum(rows, r => r.debit))], ['Internal transfers', fmt(sum(rows.filter(r => r.internal), r => r.credit + r.debit))]], columns: [
          T.date(), { key: 'acc', label: 'Account', val: r => accName(r.accountId) }, { key: 'kind', label: 'Type', html: r => esc(r.kind) + (r.internal ? ' <span class="tag">Internal</span>' : '') }, { key: 'desc', label: 'Description' },
          { key: 'ref', label: 'Reference' }, T.money('debit', 'Debit', null, 'neg'), T.money('credit', 'Credit', null, 'pos')]
      };
    }
    case 'settlements': {
      const rows = DB.settlements.filter(s => ok({ date: s.to, gstId: s.gstId, accountId: s.accountId, platformId: s.platformId }));
      return {
        rows, summary: [['Settlements', rows.length], ['Gross', fmt(sum(rows, r => r.gross))], ['Deductions', fmt(sum(rows, r => r.returns + r.commission + r.shipping + r.other))], ['Net', fmt(sum(rows, r => r.net))]], columns: [
          { key: 'id', label: 'ID' }, { key: 'pl', label: 'Platform', val: r => platName(r.platformId) }, { key: 'gstin', label: 'GSTIN', val: r => gstLabel(r.gstId) }, { key: 'from', label: 'From', html: r => fmtDate(r.from) }, { key: 'to', label: 'To', html: r => fmtDate(r.to) },
          T.money('gross', 'Gross'), T.money('returns', 'Returns'), T.money('commission', 'Commission'), T.money('shipping', 'Shipping'), T.money('other', 'Other'), T.money('net', 'Net'), { key: 'status', label: 'Status' }]
      };
    }
    case 'capital': {
      const plR = computePL(R);
      const rows = DB.partners.filter(p => !F.partner || p.id === F.partner).map(p => {
        const inv = DB.investments.filter(i => i.partnerId === p.id);
        const opening = sum(inv.filter(i => R.from && i.date < R.from), i => i.type === 'Contribution' ? i.amount : -i.amount);
        const inR = inv.filter(i => inRange(i.date, R));
        const c = sum(inR.filter(i => i.type === 'Contribution'), i => i.amount), w = sum(inR.filter(i => i.type === 'Withdrawal'), i => i.amount);
        return { id: p.id, name: p.name, share: p.share, opening, contrib: c, withdr: w, closing: opening + c - w, profit: plR.net * p.share / 100 };
      });
      return {
        rows, summary: [['Partners', rows.length], ['Contributions', fmt(sum(rows, r => r.contrib))], ['Withdrawals', fmt(sum(rows, r => r.withdr))], ['Net profit shared', fmt(sum(rows, r => r.profit))]], columns: [
          { key: 'name', label: 'Partner' }, { key: 'share', label: 'Share %', cls: 'num' }, T.money('opening', 'Opening Capital'), T.money('contrib', 'Contributions', null, 'pos'), T.money('withdr', 'Withdrawals', null, 'neg'),
          T.money('closing', 'Closing Capital'), { key: 'profit', label: 'Profit Share', cls: 'num', val: r => r.profit, html: r => moneySigned(r.profit) }]
      };
    }
    case 'gstsum': {
      const rows = DB.gsts.filter(g => (!F.gst || g.id === F.gst) && (!F.partner || g.partnerId === F.partner)).map(g => {
        const pl = computePL({ ...R, gstId: g.id });
        const purch = DB.purchases.filter(p => p.gstId === g.id && inRange(p.date, R));
        const rets = DB.returns.filter(r => returnActive(r) && inRange(r.date, R) && find('sales', r.saleId)?.gstId === g.id);
        const outGst = pl.outputGst - sum(rets, r => returnCalc(r).gst), inGst = sum(purch, p => purchaseCalc(p).gst);
        return { id: g.id, gstin: g.gstin, biz: g.businessName, state: g.state, taxable: pl.netSales, outGst, inGst, payable: outGst - inGst };
      });
      return {
        rows, summary: [['Registrations', rows.length], ['Output GST', fmt(sum(rows, r => r.outGst))], ['Input GST', fmt(sum(rows, r => r.inGst))], ['Net (demo)', fmt(sum(rows, r => r.payable))]], columns: [
          { key: 'gstin', label: 'GSTIN' }, { key: 'biz', label: 'Business' }, { key: 'state', label: 'State' }, T.money('taxable', 'Net Taxable Sales'), T.money('outGst', 'Output GST'), T.money('inGst', 'Input GST (ITC)'),
          { key: 'payable', label: 'Net GST (Payable / Credit)', cls: 'num', val: r => r.payable, html: r => `<b class="${r.payable >= 0 ? 'neg' : 'pos'}">${fmt(r.payable)}</b><small class="muted block">${r.payable >= 0 ? 'payable' : 'credit c/f'}</small>` }]
      };
    }
  }
}
PAGES.reports = () => {
  const F = pf('reports'); const R = rangeFor(getDF('reports', 'year'));
  const type = UI.reportType, def = REPORTS[type];
  const d = reportData(type, F, R);
  const dis = k => def.applies.includes(k) ? '' : 'disabled';
  const sel = (key, options, ph) => `<label class="rf"><span>${ph}</span><select class="input input-sm" data-pf="reports.${key}" ${dis(key)}><option value="">All</option>${options.map(o => `<option value="${esc(o.value)}" ${o.value === (F[key] || '') ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select></label>`;
  return `${pageHead('Financial Reports', 'Filter any register and export it to CSV.', `<button class="btn btn-primary" data-action="export-report"><i data-lucide="download"></i><span>Export ${def.label} CSV</span></button>`)}
    <div class="report-types">${Object.entries(REPORTS).map(([k, r]) => `<button class="rtype ${k === type ? 'active' : ''}" data-action="report-type" data-id="${k}"><i data-lucide="${r.icon}"></i><span>${r.label}</span></button>`).join('')}</div>
    <section class="panel report-filters"><div class="panel-body">
      <label class="rf"><span>Date range</span>${dateFilter('reports', 'year')}</label>
      ${sel('gst', optGstsShort(), 'GST Registration')}${sel('partner', optPartners(), 'Partner')}${sel('account', optAccounts(null, false), 'Bank Account')}${sel('platform', optPlatforms(), 'Selling Platform')}
      <button class="btn btn-ghost btn-sm" data-action="clear-report-filters"><i data-lucide="x"></i><span>Clear</span></button>
    </div></section>
    <div class="summary-strip">${d.summary.map(([l, v]) => `<div><span>${l}</span><b>${v}</b></div>`).join('')}</div>
    ${table('rep-' + type, { rows: d.rows, columns: d.columns, file: def.label.toLowerCase().replace(/\s+/g, '-'), sort: d.columns[0].key, dir: type === 'capital' || type === 'gstsum' ? 'asc' : 'desc', pageSize: 15, title: `${def.label} <span class="muted small">· ${rangeText(R)}</span>`, noExport: true })}
    <p class="muted small">Filters that don't apply to this report are greyed out. All figures are demo calculations on sample data — not for statutory filing.</p>`;
};

/* ---------------- Settings ---------------- */
PAGES.settings = () => {
  const s = DB.settings;
  const counts = [['Partners', 'partners'], ['Investments', 'investments'], ['GST registrations', 'gsts'], ['Bank accounts', 'accounts'], ['Transfers', 'transfers'], ['Platforms', 'platforms'], ['Products', 'products'], ['Purchases', 'purchases'], ['Sales', 'sales'], ['Returns', 'returns'], ['Expenses', 'expenses'], ['Settlements', 'settlements']];
  const size = (() => { try { return (localStorage.getItem(STORE_KEY) || '').length; } catch (e) { return 0; } })();
  AFTER.push(() => {
    $('#settings-form')?.addEventListener('submit', e => {
      e.preventDefault(); const f = e.target;
      DB.settings.company = f.company.value.trim() || 'Demo Traders LLP';
      DB.settings.tagline = f.tagline.value.trim();
      DB.settings.showBanner = f.showBanner.checked;
      commit('Settings saved');
    });
    $('#import-file')?.addEventListener('change', e => importJSON(e.target.files[0]));
  });
  return `${pageHead('Settings', 'Business profile and demo data management.')}
    <div class="grid g-1-1">
      <section class="panel"><div class="panel-head"><h3><i data-lucide="building-2"></i>Business profile</h3></div><div class="panel-body">
        <form id="settings-form" class="form-grid">
          <div class="field full"><label for="s-company">Business / firm name</label><input class="input" id="s-company" name="company" value="${esc(s.company)}"></div>
          <div class="field full"><label for="s-tag">Tagline</label><input class="input" id="s-tag" name="tagline" value="${esc(s.tagline || '')}"></div>
          <div class="field"><label>Currency</label><input class="input" value="Indian Rupee (₹ INR)" readonly></div>
          <div class="field"><label>Number format</label><input class="input" value="Indian (1,00,000)" readonly></div>
          <label class="check full"><input type="checkbox" name="showBanner" ${s.showBanner !== false ? 'checked' : ''}><span>Show the “Demo / sample data” banner</span></label>
          <div class="full form-actions"><button class="btn btn-primary" type="submit"><i data-lucide="check"></i><span>Save Settings</span></button></div>
        </form></div></section>
      <section class="panel"><div class="panel-head"><h3><i data-lucide="database"></i>Demo data</h3><span class="muted small">${(size / 1024).toFixed(1)} KB in this browser</span></div><div class="panel-body">
        <div class="count-grid">${counts.map(([l, k]) => `<div><b>${DB[k].length}</b><span>${l}</span></div>`).join('')}</div>
        <div class="btn-row">
          <button class="btn btn-ghost" data-action="export-json"><i data-lucide="download"></i><span>Export backup (JSON)</span></button>
          <label class="btn btn-ghost"><i data-lucide="upload"></i><span>Import backup</span><input type="file" id="import-file" accept="application/json,.json" hidden></label>
          <button class="btn btn-danger" data-action="reset-demo"><i data-lucide="rotate-ccw"></i><span>Reset to sample data</span></button>
        </div>
        <p class="muted small">Data is saved in this browser’s LocalStorage only. Clearing site data or using another browser starts fresh with sample data.</p>
      </div></section>
    </div>
    <section class="panel about"><div class="panel-head"><h3><i data-lucide="info"></i>About this demo</h3></div><div class="panel-body">
      <p><b>PartnerLedger</b> is a front-end <b>DEMO</b> of a multi-partner business finance system, built with plain HTML, CSS and JavaScript. All partners, GST numbers, bank accounts and orders are <b>fictional sample data</b>.</p>
      <ul class="ticks"><li>No backend, database or login server — nothing leaves this browser.</li><li>No real bank, GST portal or marketplace integration.</li><li>Figures are illustrative and must not be used for tax filing or accounting.</li></ul>
    </div></section>`;
};
function importJSON(file) {
  if (!file) return;
  const rd = new FileReader();
  rd.onload = () => {
    try {
      const d = JSON.parse(rd.result);
      const need = ['partners', 'investments', 'gsts', 'accounts', 'transfers', 'platforms', 'products', 'purchases', 'sales', 'returns', 'expenses', 'settlements'];
      if (!d || d.version !== DATA_VERSION || need.some(k => !Array.isArray(d[k]))) throw new Error('Not a PartnerLedger backup file');
      DB = d; commit('Backup imported');
    } catch (e) { toast('Import failed: ' + e.message, 'error'); }
  };
  rd.readAsText(file);
}

/* =====================================================================
   9. Actions (button handlers)
   ===================================================================== */
function blocked(msg) { alertDialog('Can’t delete this record', msg); }
const ACTIONS = {
  // partners
  'add-partner': () => partnerForm(),
  'edit-partner': id => partnerForm(find('partners', id)),
  'view-partner': id => viewPartner(find('partners', id)),
  'del-partner': id => {
    const p = find('partners', id);
    const g = DB.gsts.filter(x => x.partnerId === id);
    if (g.length) return blocked(`${esc(p.name)} holds ${g.length} GST registration(s). Reassign or delete them first.`);
    const n = DB.investments.filter(i => i.partnerId === id).length;
    confirmDialog('Delete partner?', `Delete <b>${esc(p.name)}</b>${n ? ` and their ${n} investment record(s)` : ''}? Bank balances will be recalculated.`, () => {
      removeById('partners', id); DB.investments = DB.investments.filter(i => i.partnerId !== id); commit('Partner deleted');
    });
  },
  // investments
  'add-contribution': id => { closeModal(); investmentForm(null, { type: 'Contribution', partnerId: id && find('partners', id) ? id : '' }); },
  'add-withdrawal': () => investmentForm(null, { type: 'Withdrawal' }),
  'edit-investment': id => investmentForm(find('investments', id)),
  'del-investment': id => confirmDialog('Delete transaction?', `Delete investment <b>${id}</b>? Partner capital and bank balance will be recalculated.`, () => { removeById('investments', id); commit('Investment deleted'); }),
  // gst
  'add-gst': () => gstForm(),
  'edit-gst': id => { closeModal(); gstForm(find('gsts', id)); },
  'view-gst': id => viewGst(find('gsts', id)),
  'del-gst': id => {
    const used = ['sales', 'purchases', 'expenses', 'settlements'].filter(c => DB[c].some(x => x.gstId === id));
    if (used.length) return blocked(`This GST registration is used in ${used.join(', ')}. Delete those records first.`);
    confirmDialog('Delete GST registration?', `Delete <b>${esc(gstLabel(id))}</b>? It will be unlinked from bank accounts and platforms.`, () => {
      removeById('gsts', id); DB.accounts.forEach(a => { if (a.gstId === id) a.gstId = ''; }); DB.platforms.forEach(p => { p.gstIds = p.gstIds.filter(g => g !== id); }); commit('GST registration deleted');
    });
  },
  // bank
  'add-account': () => accountForm(),
  'edit-account': id => accountForm(find('accounts', id)),
  'statement': id => openStatement(find('accounts', id)),
  'del-account': id => {
    const a = find('accounts', id);
    if (accountStats(a).entries.length) return blocked(`<b>${esc(a.name)}</b> has transactions. Only accounts without any transactions can be deleted.`);
    confirmDialog('Delete bank account?', `Delete <b>${esc(a.name)}</b>?`, () => { removeById('accounts', id); commit('Bank account deleted'); });
  },
  'export-statement': id => { const a = find('accounts', id); downloadCSV(`statement-${a.accNo}-${todayISO()}.csv`, ['Date', 'Type', 'Description', 'Reference', 'Debit', 'Credit', 'Balance'], [[a.openedOn, 'Opening balance', '', '', '', '', a.opening], ...statementRows(a).map(e => [e.date, e.kind, e.desc, e.ref, e.debit || '', e.credit || '', e.balance])]); },
  // transfers
  'del-transfer': id => {
    const t = find('transfers', id);
    const bal = balanceOf(t.toId);
    if (bal < t.amount) return blocked(`Reversing this transfer would make ${esc(accName(t.toId))} negative (balance ${fmt(bal)}).`);
    confirmDialog('Reverse transfer?', `Reverse <b>${fmt(t.amount)}</b> from ${esc(accName(t.fromId))} to ${esc(accName(t.toId))}? Both balances will be restored.`, () => { removeById('transfers', id); commit('Transfer reversed'); }, { label: 'Reverse' });
  },
  // platforms
  'add-platform': () => platformForm(),
  'edit-platform': id => platformForm(find('platforms', id)),
  'del-platform': id => {
    const p = find('platforms', id);
    if (DB.sales.some(s => s.platformId === id)) return blocked(`${esc(p.name)} has sales recorded. Platforms with sales can't be deleted.`);
    confirmDialog('Delete platform?', `Delete <b>${esc(p.name)}</b>?`, () => { removeById('platforms', id); commit('Platform deleted'); });
  },
  // products
  'add-product': () => productForm(),
  'edit-product': id => { closeModal(); productForm(find('products', id)); },
  'view-product': id => viewProduct(find('products', id)),
  'del-product': id => {
    const p = find('products', id);
    if (DB.sales.some(s => s.productId === id) || DB.purchases.some(x => x.productId === id)) return blocked(`${esc(p.name)} has purchases or sales. Products with history can't be deleted.`);
    confirmDialog('Delete product?', `Delete <b>${esc(p.name)}</b>?`, () => { removeById('products', id); commit('Product deleted'); });
  },
  // purchases
  'add-purchase': id => { closeModal(); purchaseForm(null, { productId: find('products', id) ? id : '' }); },
  'edit-purchase': id => purchaseForm(find('purchases', id)),
  'del-purchase': id => {
    const pu = find('purchases', id); const p = find('products', pu.productId);
    if (p && productStats(p).stock < pu.qty) return blocked(`Deleting this purchase would make stock of ${esc(p.name)} negative.`);
    confirmDialog('Delete purchase?', `Delete <b>${id}</b> (${esc(prodName(pu.productId))} × ${pu.qty})? Stock and bank balance will be recalculated.`, () => { removeById('purchases', id); commit('Purchase deleted'); });
  },
  // sales
  'add-sale': () => saleForm(),
  'edit-sale': id => saleForm(find('sales', id)),
  'del-sale': id => {
    const s = find('sales', id);
    if (DB.returns.some(r => r.saleId === id)) return blocked(`Order ${esc(s.orderId)} has returns linked. Delete the returns first.`);
    confirmDialog('Delete sale?', `Delete order <b>${esc(s.orderId)}</b>? Stock and dashboard will update.`, () => { removeById('sales', id); commit('Sale deleted'); });
  },
  // returns
  'add-return': () => returnForm(),
  'edit-return': id => returnForm(find('returns', id)),
  'restock-return': id => { const r = find('returns', id); r.restock = 'Restocked'; if (r.status === 'Requested' || r.status === 'Approved') r.status = 'Received'; commit(`${r.qty} unit(s) restocked`); },
  'del-return': id => confirmDialog('Delete return?', `Delete return <b>${id}</b>?`, () => { removeById('returns', id); commit('Return deleted'); }),
  // expenses
  'add-expense': () => expenseForm(),
  'edit-expense': id => expenseForm(find('expenses', id)),
  'del-expense': id => confirmDialog('Delete expense?', `Delete expense <b>${id}</b>? The bank balance will be restored.`, () => { removeById('expenses', id); commit('Expense deleted'); }),
  // settlements
  'add-settlement': () => settlementForm(),
  'settle-now': key => { const [platformId, gstId, from, to] = key.split('|'); settlementForm({ platformId, gstId, from, to }); },
  'view-settlement': id => viewSettlement(find('settlements', id)),
  'receive-settlement': id => {
    const s = find('settlements', id);
    openForm({
      title: `Mark ${id} as received`, size: 'sm', values: { settledOn: todayISO(), utr: 'SETLDEMO' + randDigits(8), accountId: s.accountId }, submitLabel: 'Mark Received',
      fields: [{ name: 'settledOn', label: 'Payout date', type: 'date', required: true, full: true }, { name: 'utr', label: 'UTR / reference', full: true }, { name: 'accountId', label: 'Credited to', type: 'select', options: optAccounts(a => a.type === 'Business'), required: true, full: true }],
      note: `${fmt(s.net)} will be credited to the bank account.`,
      onSubmit: v => { Object.assign(s, v, { status: 'Received' }); commit(`${fmt(s.net)} credited to ${accName(v.accountId)}`); }
    });
  },
  'del-settlement': id => confirmDialog('Delete settlement?', `Delete <b>${id}</b>? ${find('settlements', id).status === 'Received' ? 'The credited amount will be removed from the bank balance.' : ''}`, () => { removeById('settlements', id); commit('Settlement deleted'); }),
  // P&L / reports
  'pl-tab': id => { UI.plTab = id; rerender(); },
  'export-pl': () => exportTable('pl-' + UI.plTab),
  'report-type': id => { UI.reportType = id; rerender(); },
  'export-report': () => exportTable('rep-' + UI.reportType),
  'clear-report-filters': () => { UI.pf.reports = {}; UI.filters.reports = { preset: 'all', from: '', to: '' }; rerender(); },
  'export-table': id => exportTable(id),
  // settings
  'export-json': () => { downloadBlob(new Blob([JSON.stringify(DB, null, 2)], { type: 'application/json' }), `partnerledger-demo-backup-${todayISO()}.json`); toast('Backup downloaded'); },
  'reset-demo': () => confirmDialog('Reset demo data?', 'All your changes will be erased and the original sample data restored.', () => { DB = buildSeedData(); persist(); UI.pf = {}; UI.filters = {}; UI.tables = {}; renderRoute(); toast('Sample data restored'); }, { label: 'Reset' })
};

/* =====================================================================
   10. Global event wiring
   ===================================================================== */
function wireEvents() {
  document.addEventListener('click', e => {
    const t = e.target;
    if (t.closest('[data-close-modal]') || t.classList.contains('modal-backdrop')) { closeModal(); return; }
    const qa = t.closest('#quick-add-btn');
    if (qa) { $('#quick-add-menu').classList.toggle('open'); return; }
    if (!t.closest('#quick-add-menu')) $('#quick-add-menu')?.classList.remove('open');
    const go = t.closest('[data-go]');
    if (go) { location.hash = go.dataset.go; return; }
    const sortEl = t.closest('[data-tsort]');
    if (sortEl) {
      const st = UI.tables[sortEl.dataset.tsort], k = sortEl.dataset.key;
      if (st.sort === k) st.dir = st.dir === 'asc' ? 'desc' : 'asc'; else { st.sort = k; st.dir = 'asc'; }
      refreshTable(sortEl.dataset.tsort); return;
    }
    const pg = t.closest('[data-tpage]');
    if (pg && !pg.disabled) { UI.tables[pg.dataset.tpage].page = +pg.dataset.p; refreshTable(pg.dataset.tpage); return; }
    const pill = t.closest('[data-df-pill]');
    if (pill) { const f = getDF(pill.dataset.dfPill); f.preset = pill.dataset.v; if (f.preset === 'custom' && !f.from) { f.from = addDays(todayISO(), -30); f.to = todayISO(); } rerender(); return; }
    const a = t.closest('[data-action]');
    if (a && ACTIONS[a.dataset.action]) {
      e.preventDefault();
      $('#quick-add-menu')?.classList.remove('open');
      ACTIONS[a.dataset.action](a.dataset.id, a, e);
    }
  });
  document.addEventListener('input', e => {
    const s = e.target.closest('[data-tsearch]');
    if (s) { const st = UI.tables[s.dataset.tsearch]; st.q = s.value; st.page = 1; refreshTable(s.dataset.tsearch); }
  });
  document.addEventListener('change', e => {
    const t = e.target;
    if (t.dataset.dfPreset) { const f = getDF(t.dataset.dfPreset); f.preset = t.value; if (t.value === 'custom' && !f.from) { f.from = addDays(todayISO(), -30); f.to = todayISO(); } resetPages(); rerender(); }
    else if (t.dataset.dfFrom) { getDF(t.dataset.dfFrom).from = t.value; resetPages(); rerender(); }
    else if (t.dataset.dfTo) { getDF(t.dataset.dfTo).to = t.value; resetPages(); rerender(); }
    else if (t.dataset.pf) { const [r, k] = t.dataset.pf.split('.'); pf(r)[k] = t.value; resetPages(); rerender(); }
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { if ($('#modal-root').innerHTML) closeModal(); $('#quick-add-menu')?.classList.remove('open'); document.body.classList.remove('nav-open'); } });
  $('#menu-btn').addEventListener('click', () => document.body.classList.toggle('nav-open'));
  $('#sidebar-overlay').addEventListener('click', () => document.body.classList.remove('nav-open'));
  $('#banner-close')?.addEventListener('click', () => { DB.settings.showBanner = false; persist(); $('#demo-banner').classList.add('hidden'); });
  window.addEventListener('hashchange', () => renderRoute());
}
function resetPages() { Object.values(UI.tables).forEach(st => { st.page = 1; }); }

/* =====================================================================
   11. Boot
   ===================================================================== */
function init() {
  DB = loadDB();
  setupChartDefaults();
  renderNav();
  wireEvents();
  renderRoute();
}
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
}
