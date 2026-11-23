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

    /* ---------- Mobile sidebar ---------- */
    function initSidebar() {
        $$('[data-sidebar-toggle]').forEach((btn) => btn.addEventListener('click', () => document.body.classList.toggle('sidebar-open')));
        $$('[data-sidebar-close]').forEach((el) => el.addEventListener('click', () => document.body.classList.remove('sidebar-open')));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') document.body.classList.remove('sidebar-open'); });
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
                $('#confirm-message').textContent = form.dataset.confirm;
                const ok = $('#confirm-ok');
                ok.textContent = form.dataset.confirmButton || 'Confirm';
                ok.className = 'btn btn-' + (form.dataset.confirmVariant || 'primary');
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
    function initCharts() {
        const canvas = $('#login-chart');
        if (!canvas) return;
        if (!window.Chart) {
            canvas.parentElement.innerHTML = '<div class="empty-state"><p class="mb-0">Chart unavailable (Chart.js could not be loaded).</p></div>';
            return;
        }
        const data = JSON.parse(canvas.dataset.chart || '{}');
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.color = '#64748b';
        new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [
                    { label: 'Successful', data: data.success || [], backgroundColor: '#16a34a', borderRadius: 5, maxBarThickness: 26 },
                    { label: 'Failed', data: data.failed || [], backgroundColor: '#dc2626', borderRadius: 5, maxBarThickness: 26 }
                ]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(148,163,184,.18)' } }
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        icons();
        initSidebar();
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
