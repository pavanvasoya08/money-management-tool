/*
 * Business ERP — UI behaviour (vanilla JS, no inline scripts so a strict CSP works).
 * All security checks happen on the server; this file only improves the UX.
 */
(function () {
    'use strict';

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    function icons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    /* ---------- Sidebar: drawer on mobile, mini mode on desktop (remembered in a cookie) ---------- */
    function initSidebar() {
        const desktop = () => window.matchMedia('(min-width: 992px)').matches;
        $$('[data-sidebar-toggle]').forEach((btn) => btn.addEventListener('click', () => {
            if (desktop()) {
                const mini = document.body.classList.toggle('sidebar-mini');
                document.cookie = 'ui_sidebar=' + (mini ? 'mini' : 'full') + '; path=/; max-age=31536000; SameSite=Lax';
            } else {
                document.body.classList.toggle('sidebar-open');
            }
        }));
        $$('[data-sidebar-close]').forEach((el) => el.addEventListener('click', () => document.body.classList.remove('sidebar-open')));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') document.body.classList.remove('sidebar-open'); });
        // Collapsible menu groups
        $$('.nav-group-toggle').forEach((btn) => btn.addEventListener('click', () => {
            const group = btn.closest('.nav-group');
            if (document.body.classList.contains('sidebar-mini') && desktop()) {
                // In mini mode a group click expands the sidebar so its items become reachable.
                document.body.classList.remove('sidebar-mini');
                document.cookie = 'ui_sidebar=full; path=/; max-age=31536000; SameSite=Lax';
                group.classList.add('open');
            } else {
                group.classList.toggle('open');
            }
            btn.setAttribute('aria-expanded', group.classList.contains('open') ? 'true' : 'false');
        }));
        const active = $('.sidebar .nav-item.active');
        if (active) active.scrollIntoView({ block: 'nearest' });
    }

    /* ---------- Quick navigation (top bar search, Ctrl/⌘+K or "/") ---------- */
    function initQuickNav() {
        const modalEl = $('#quickNav');
        if (!modalEl) return;
        const input = $('[data-quick-nav-input]', modalEl);
        const items = $$('[data-quick-nav-list] li', modalEl);
        const empty = $('[data-quick-nav-empty]', modalEl);
        let focus = -1;
        const visible = () => items.filter((li) => !li.classList.contains('d-none'));
        const mark = () => { items.forEach((li) => $('a', li).classList.remove('is-focus')); const v = visible(); if (v[focus]) { $('a', v[focus]).classList.add('is-focus'); $('a', v[focus]).scrollIntoView({ block: 'nearest' }); } };
        const filter = () => {
            const q = input.value.trim().toLowerCase();
            items.forEach((li) => li.classList.toggle('d-none', q !== '' && !li.dataset.search.includes(q)));
            empty.classList.toggle('d-none', visible().length > 0);
            focus = visible().length ? 0 : -1;
            mark();
        };
        input.addEventListener('input', filter);
        input.addEventListener('keydown', (e) => {
            const v = visible();
            if (e.key === 'ArrowDown') { e.preventDefault(); focus = Math.min(v.length - 1, focus + 1); mark(); }
            if (e.key === 'ArrowUp') { e.preventDefault(); focus = Math.max(0, focus - 1); mark(); }
            if (e.key === 'Enter' && v[focus]) { e.preventDefault(); window.location.href = $('a', v[focus]).href; }
        });
        modalEl.addEventListener('shown.bs.modal', () => { input.value = ''; filter(); input.focus(); });
        document.addEventListener('keydown', (e) => {
            const typing = /^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '') || (document.activeElement || {}).isContentEditable;
            if (((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') || (e.key === '/' && !typing)) {
                e.preventDefault();
                if (window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });
    }

    /* ---------- Toasts: auto-hide flash messages ---------- */
    function initToasts() {
        $$('.app-toast[data-autohide]').forEach((el) => {
            const hide = () => { el.classList.remove('show'); setTimeout(() => el.remove(), 300); };
            setTimeout(hide, 6000);
            const close = $('[data-bs-dismiss="toast"]', el);
            if (close) close.addEventListener('click', hide);
        });
    }

    /* ---------- Loading state on submit ---------- */
    function setLoading(form) {
        const btn = $('button[type="submit"]', form) || $('button:not([type])', form);
        if (!btn || btn.classList.contains('is-loading')) return;
        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
        const spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm';
        spinner.setAttribute('role', 'status');
        spinner.setAttribute('aria-hidden', 'true');
        btn.prepend(spinner);
        // Disable after the submit event has been dispatched so the form still posts.
        setTimeout(() => { btn.disabled = true; }, 0);
    }

    /* ---------- Confirmation dialogs (data-confirm on forms) ---------- */
    function initConfirm() {
        const modalEl = $('#confirm-modal');
        const hasBootstrap = !!(window.bootstrap && window.bootstrap.Modal);
        let pendingForm = null;
        let modal = null;
        if (modalEl && hasBootstrap) {
            modal = new window.bootstrap.Modal(modalEl);
            $('#confirm-ok').addEventListener('click', () => {
                if (!pendingForm) return;
                const form = pendingForm;
                pendingForm = null;
                form.dataset.confirmed = '1';
                modal.hide();
                if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
            });
        }

        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (form.dataset.confirm && form.dataset.confirmed !== '1') {
                e.preventDefault();
                if (!modal) {
                    if (window.confirm(form.dataset.confirm)) { form.dataset.confirmed = '1'; form.submit(); }
                    return;
                }
                pendingForm = form;
                const variant = form.dataset.confirmVariant || 'primary';
                $('#confirm-message').textContent = form.dataset.confirm;
                $('#confirm-title').textContent = form.dataset.confirmTitle || (variant === 'danger' ? 'Are you sure?' : 'Please confirm');
                const icon = $('#confirm-icon');
                const kind = { danger: ['', 'triangle-alert'], warning: ['is-warning', 'triangle-alert'], success: ['is-success', 'circle-check'] }[variant] || ['is-primary', 'info'];
                icon.className = 'confirm-icon ' + kind[0];
                icon.innerHTML = '<i data-lucide="' + kind[1] + '"></i>';
                icons();
                const ok = $('#confirm-ok');
                ok.textContent = form.dataset.confirmButton || 'Confirm';
                ok.className = 'btn btn-' + (variant === 'danger' ? 'danger' : variant);
                modal.show();
                return;
            }
            delete form.dataset.confirmed;
            if (form.hasAttribute('data-loading')) setLoading(form);
        });
    }

    /* ---------- Show / hide password ---------- */
    function initReveal() {
        $$('[data-reveal]').forEach((btn) => btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.reveal);
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        }));
    }

    /* ---------- Strong password generator (crypto RNG) ---------- */
    function generatePassword(length = 16) {
        const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*-_=+?'];
        const all = sets.join('');
        const rand = (max) => { const a = new Uint32Array(1); window.crypto.getRandomValues(a); return a[0] % max; };
        const chars = sets.map((s) => s[rand(s.length)]);
        while (chars.length < length) chars.push(all[rand(all.length)]);
        for (let i = chars.length - 1; i > 0; i--) { const j = rand(i + 1); [chars[i], chars[j]] = [chars[j], chars[i]]; }
        return chars.join('');
    }
    function initGenerator() {
        $$('[data-generate-password]').forEach((btn) => btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.generatePassword);
            if (!input) return;
            const pwd = generatePassword();
            input.value = pwd;
            input.type = 'text';
            const form = input.form;
            const confirm = form && (form.querySelector('[name="password_confirmation"]'));
            if (confirm) confirm.value = pwd;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.select();
        }));
    }

    /* ---------- Password strength meter (indicative; server enforces policy) ---------- */
    function initStrength() {
        $$('input[data-strength]').forEach((input) => {
            const bar = input.closest('.mb-3, .mb-2, .col-md-6, form')?.querySelector('.strength span');
            if (!bar) return;
            input.addEventListener('input', () => {
                const v = input.value;
                let score = 0;
                if (v.length >= 10) score++;
                if (v.length >= 14) score++;
                if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
                if (/\d/.test(v)) score++;
                if (/[^A-Za-z0-9]/.test(v)) score++;
                const colors = ['#dc2626', '#dc2626', '#f59e0b', '#f59e0b', '#16a34a', '#16a34a'];
                bar.style.width = (v ? Math.max(10, score * 20) : 0) + '%';
                bar.style.backgroundColor = colors[score];
            });
        });
    }

    /* ---------- Filters: submit on change ---------- */
    function initAutosubmit() {
        $$('select[data-autosubmit]').forEach((sel) => sel.addEventListener('change', () => sel.form && sel.form.submit()));
    }

    /* ---------- Empty role sentinel: only send "" when no checkbox is ticked ---------- */
    function initRoleSentinel() {
        $$('input[data-empty-sentinel]').forEach((sentinel) => {
            const form = sentinel.form;
            form.addEventListener('submit', () => {
                sentinel.disabled = form.querySelectorAll('input[name="roles[]"][type="checkbox"]:checked').length > 0;
            }, true);
        });
    }

    /* ---------- Error page "Go back" ---------- */
    function initHistoryBack() {
        $$('[data-history-back]').forEach((a) => a.addEventListener('click', (e) => {
            if (window.history.length > 1) { e.preventDefault(); window.history.back(); }
        }));
    }

    /* ---------- Charts (data passed via data-chart attribute) ---------- */
    function initCapitalChart() {
        const canvas = $('#capital-chart');
        if (!canvas) return;
        if (!window.Chart) {
            canvas.parentElement.innerHTML = '<div class="empty-state"><p class="mb-0">Chart unavailable — use “Show as table”.</p></div>';
            return;
        }
        const data = JSON.parse(canvas.dataset.chart || '{}');
        const inr = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 });
        const compact = new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 });
        // One series → one hue, no legend; the card title names the measure.
        new window.Chart(canvas, {
            type: 'bar',
            data: { labels: data.labels || [], datasets: [{ label: 'Closing capital', data: data.values || [], backgroundColor: '#2a85ff', hoverBackgroundColor: '#1f6fe0',
                borderRadius: { topRight: 4, bottomRight: 4, topLeft: 0, bottomLeft: 0 }, borderSkipped: 'start', barThickness: 18 }] },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => ' ' + inr.format(ctx.parsed.x) } }
                },
                scales: {
                    x: { beginAtZero: true, border: { display: false }, grid: { color: '#ededed' }, ticks: { callback: (v) => '₹' + compact.format(v) } },
                    y: { grid: { display: false }, border: { display: false }, ticks: { color: '#404040' } }
                }
            }
        });
    }

    function initCharts() {
        initCapitalChart();
        const canvas = $('#login-chart');
        if (!canvas) return;
        if (!window.Chart) {
            canvas.parentElement.innerHTML = '<div class="empty-state"><p class="mb-0">Chart unavailable (Chart.js could not be loaded).</p></div>';
            return;
        }
        const data = JSON.parse(canvas.dataset.chart || '{}');
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.color = '#737373';
        new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [
                    { label: 'Successful', data: data.success || [], backgroundColor: '#2a85ff', borderRadius: 4, maxBarThickness: 22 },
                    { label: 'Failed', data: data.failed || [], backgroundColor: '#fb2c36', borderRadius: 4, maxBarThickness: 22 }
                ]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                scales: {
                    x: { stacked: true, grid: { display: false }, border: { display: false } },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#ededed' }, border: { display: false } }
                }
            }
        });
    }

    /* ---------- Phase 3: capital & profit-sharing forms (UX only; the server enforces every rule) ---------- */
    function toPaise(v) {
        const m = /^(\d{1,13})(?:\.(\d{1,2}))?$/.exec((v || '').trim());
        return m ? parseInt(m[1], 10) * 100 + parseInt((m[2] || '0').padEnd(2, '0'), 10) : null;
    }
    function initCapitalForm() {
        const form = $('form[data-capital-form]');
        if (!form) return;
        const sel = $('#partner_id', form), amount = $('#amount', form), text = $('[data-availability-text]', form), warn = $('[data-overdraw-warning]', form), dir = $('#direction', form);
        const info = JSON.parse((sel && sel.dataset.availability) || '{}');
        const refresh = () => {
            const a = info[sel.value];
            if (text) text.textContent = a ? 'Posted balance ' + a.balance + ' · eligible available ' + a.available : (Object.keys(info).length ? 'Choose a partner to see the eligible available capital.' : '');
            const p = toPaise(amount.value);
            const debit = !dir || dir.value === 'debit';
            if (warn) warn.classList.toggle('d-none', !(a && debit && p !== null && p > a.availablePaise));
        };
        [sel, amount, dir].forEach((el) => el && el.addEventListener('input', refresh));
        [sel, dir].forEach((el) => el && el.addEventListener('change', refresh));
        refresh();
    }
    function initAllocationForm() {
        const form = $('form[data-allocation-form]');
        if (!form) return;
        const total = $('[data-alloc-total]', form), hint = $('[data-alloc-hint]', form), complete = form.dataset.complete === '1';
        const refresh = () => {
            let units = 0, bad = false;
            $$('[data-alloc-input]', form).forEach((i) => {
                const v = i.value.trim();
                if (v === '') return;
                const m = /^(\d{1,3})(?:\.(\d{1,4}))?$/.exec(v);
                if (!m) { bad = true; return; }
                units += parseInt(m[1], 10) * 10000 + parseInt((m[2] || '0').padEnd(4, '0'), 10);
            });
            const pct = (units / 10000).toFixed(4).replace(/\.?0+$/, '');
            total.textContent = pct + '%';
            const ok = !bad && (complete ? units === 1000000 : units <= 1000000 && units > 0);
            total.className = ok ? 'text-success' : 'text-danger';
            hint.textContent = bad ? 'Use numbers with up to 4 decimals.' : (complete ? (units === 1000000 ? 'Totals exactly 100%.' : 'A complete allocation must total exactly 100%.') : (units > 1000000 ? 'Cannot exceed 100%.' : ''));
        };
        form.addEventListener('input', refresh);
        refresh();
    }
    /* Sizes from data attributes (the CSP forbids inline style attributes; CSSOM is allowed). */
    function initSizes() {
        $$('[data-width]').forEach((el) => { el.style.width = Math.max(0, Math.min(100, parseFloat(el.dataset.width) || 0)) + '%'; });
        $$('[data-height]').forEach((el) => { el.style.height = (parseInt(el.dataset.height, 10) || 240) + 'px'; });
    }
    function initPrint() {
        $$('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
    }

    /* Phase 4: live GSTIN format check, validity-period field and state-from-GSTIN on the registration form. */
    function initGstForm() {
        const form = document.getElementById('gst-form');
        if (!form) { return; }
        const input = form.querySelector('[data-gstin]');
        const feedback = document.getElementById('gstin-feedback');
        const type = form.querySelector('#registration_type_id');
        const pan = form.querySelector('#pan');
        const stateSel = form.querySelector('select[data-follow-gstin]');
        const token = (form.querySelector('input[name="_csrf"]') || {}).value || '';
        const validity = form.querySelector('[data-validity-field]');
        const syncValidity = () => {
            if (!validity || !type) { return; }
            const opt = type.options[type.selectedIndex];
            validity.hidden = !(opt && opt.dataset.validity === '1');
        };
        if (type) { type.addEventListener('change', syncValidity); syncValidity(); }
        if (!input || !feedback || input.readOnly) { return; }
        let timer = null; let seq = 0;
        const say = (text, cls) => { feedback.textContent = text; feedback.className = 'form-text ' + (cls || ''); };
        const check = () => {
            const value = input.value.replace(/\s+/g, '').toUpperCase();
            if (input.value !== value) { input.value = value; }
            if (value.length < 15) { input.classList.remove('is-valid'); say(value === '' ? 'State, PAN and the check character are verified as you type.' : (15 - value.length) + ' more character(s) needed.', 'text-secondary'); return; }
            const body = new URLSearchParams({ gstin: value, registration_type_id: type ? type.value : '', pan: pan ? pan.value : '', except: form.dataset.except || '0', _csrf: token });
            const mine = ++seq;
            fetch(form.dataset.checkUrl, { method: 'POST', body, headers: { 'X-CSRF-Token': token, Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
                .then((d) => {
                    if (mine !== seq) { return; }
                    input.classList.toggle('is-valid', d.valid);
                    if (d.valid) {
                        say('Format OK · state ' + d.state.code + ' ' + d.state.name + ' · PAN ' + d.pan + '. ' + d.notice, 'text-success');
                        if (stateSel && !stateSel.value) { stateSel.value = String(d.state.id); }
                        const stateBox = document.getElementById('gst-state');
                        if (stateBox) { stateBox.value = d.state.code + ' · ' + d.state.name; }
                    } else {
                        say(d.errors.join(' '), 'text-danger');
                    }
                })
                .catch(() => { if (mine === seq) { say('Could not check right now; the GSTIN is validated again when you save.', 'text-secondary'); } });
        };
        const later = () => { clearTimeout(timer); timer = setTimeout(check, 350); };
        input.addEventListener('input', later);
        if (type) { type.addEventListener('change', later); }
        if (pan) { pan.addEventListener('change', later); }
        if (input.value) { check(); }
    }

    /* ---------- Phase 5: bank pages (UX only; the server enforces every rule) ---------- */
    function initBankCharts() {
        const inr = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 });
        const compact = new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 });
        const tick = (v) => (v < 0 ? '−₹' : '₹') + compact.format(Math.abs(v));
        const bal = $('#bank-balance-chart');
        const mon = $('#bank-monthly-chart');
        if ((bal || mon) && !window.Chart) {
            [bal, mon].forEach((c) => { if (c) { c.parentElement.innerHTML = '<div class="empty-state"><p class="mb-0">Chart unavailable — see the table below.</p></div>'; } });
            return;
        }
        if (bal) {
            const d = JSON.parse(bal.dataset.chart || '{}');
            // One series → one hue, no legend; negative (overdrawn) balances extend left of zero.
            new window.Chart(bal, {
                type: 'bar',
                data: { labels: d.labels || [], datasets: [{ label: 'Current balance', data: d.values || [], backgroundColor: '#2a85ff', hoverBackgroundColor: '#1f6fe0', borderRadius: 4, borderSkipped: false, barThickness: 18 }] },
                options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ' ' + inr.format(ctx.parsed.x) } } },
                    scales: { x: { border: { display: false }, grid: { color: '#ededed' }, ticks: { callback: tick } }, y: { grid: { display: false }, border: { display: false }, ticks: { color: '#404040' } } } }
            });
        }
        if (mon) {
            const d = JSON.parse(mon.dataset.chart || '{}');
            // Two series (validated pair: blue #2a85ff / orange #eb6834), legend on top, 2px gap between bars.
            new window.Chart(mon, {
                type: 'bar',
                data: { labels: d.labels || [], datasets: [
                    { label: 'Received', data: d.received || [], backgroundColor: '#2a85ff', borderRadius: 4, borderSkipped: 'start', maxBarThickness: 22, borderColor: '#ffffff', borderWidth: { left: 1, right: 1 } },
                    { label: 'Paid', data: d.paid || [], backgroundColor: '#eb6834', borderRadius: 4, borderSkipped: 'start', maxBarThickness: 22, borderColor: '#ffffff', borderWidth: { left: 1, right: 1 } }
                ] },
                options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 8, color: '#404040' } }, tooltip: { callbacks: { label: (ctx) => ' ' + ctx.dataset.label + ': ' + inr.format(ctx.parsed.y) } } },
                    scales: { x: { grid: { display: false }, border: { display: false } }, y: { beginAtZero: true, grid: { color: '#ededed' }, border: { display: false }, ticks: { callback: tick } } } }
            });
        }
    }
    function initBankPages() {
        // Show full account number on request (permission-checked and logged on the server).
        document.querySelectorAll('[data-reveal-number]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
                fetch(btn.dataset.revealNumber, { method: 'POST', headers: { 'X-CSRF-Token': token, Accept: 'application/json' }, body: new URLSearchParams({ _csrf: token }), credentials: 'same-origin' })
                    .then((r) => r.json().then((d) => ({ ok: r.ok, d })))
                    .then(({ ok, d }) => {
                        const target = document.querySelector('[data-account-number]');
                        if (ok && target) { target.textContent = d.number; btn.remove(); } else if (target) { target.insertAdjacentHTML('afterend', '<span class="small text-danger ms-2"></span>'); target.nextElementSibling.textContent = d.error || 'Not allowed.'; }
                    }).catch(() => {});
            });
        });
        // Select / clear all suggested matches.
        document.querySelectorAll('[data-check-all]').forEach((all) => {
            all.addEventListener('change', () => document.querySelectorAll('input[name="' + all.dataset.checkAll + '"]').forEach((c) => { c.checked = all.checked; }));
        });
        // Transfer form: show the source's available balance and warn when the amount exceeds it.
        const tf = document.querySelector('form[data-transfer-form]');
        if (tf) {
            const from = tf.querySelector('#from_account_id'), to = tf.querySelector('#to_account_id'), amount = tf.querySelector('#amount');
            const text = tf.querySelector('[data-available-text]'), warn = tf.querySelector('[data-over-warning]');
            const inr = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' });
            const sync = () => {
                const opt = from.options[from.selectedIndex];
                const avail = opt && opt.dataset.available !== undefined ? parseFloat(opt.dataset.available) : null;
                text.textContent = avail === null ? '' : 'Available: ' + inr.format(avail) + ' (posted balance minus pending withdrawals and transfers)';
                const val = parseFloat((amount.value || '').replace(/,/g, ''));
                warn.hidden = !(avail !== null && !isNaN(val) && val > avail);
                Array.from(to.options).forEach((o) => { o.disabled = o.value !== '' && o.value === from.value; });
            };
            [from, amount].forEach((el) => el.addEventListener('input', sync));
            from.addEventListener('change', sync);
            sync();
        }
        // Reconciliation start: take period and closing balance from the chosen statement.
        const st = document.getElementById('r-st');
        if (st) {
            st.addEventListener('change', () => {
                const o = st.options[st.selectedIndex];
                if (!o || !o.value) { return; }
                document.getElementById('r-from').value = o.dataset.from || '';
                document.getElementById('r-to').value = o.dataset.to || '';
                if (o.dataset.closing) { document.getElementById('r-cb').value = o.dataset.closing; }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        initCapitalForm();
        initAllocationForm();
        initPrint();
        initSizes();
        icons();
        initSidebar();
        initQuickNav();
        initToasts();
        initConfirm();
        initReveal();
        initGenerator();
        initStrength();
        initAutosubmit();
        initRoleSentinel();
        initHistoryBack();
        initCharts();
        initGstForm();
        initBankCharts();
        initBankPages();
    });
})();
