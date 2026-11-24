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
    });
})();
