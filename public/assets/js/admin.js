/*
 * Control Center UI helpers (vanilla JS). Purely UX — every rule is enforced server-side.
 *  - permission matrix: search, filters, select/deselect all, row & column toggles
 *  - unsaved-change counter + leave-page warning
 *  - confirmation before saving high-impact grants
 *  - live "effective preview" summary
 */
(function () {
    'use strict';
    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

    function isChanged(el) {
        if (el.type === 'checkbox') return (el.checked ? '1' : '0') !== el.dataset.original;
        return el.value !== el.dataset.original;
    }

    function initMatrixForm(form) {
        const inputs = $$('[data-matrix-input]', form);
        const pill = $('[data-matrix-unsaved]', form.closest('.admin-main') || document);
        const summary = $('[data-matrix-summary]');
        let dirty = 0;

        function refresh() {
            dirty = inputs.filter(isChanged).length;
            if (pill) {
                pill.classList.toggle('d-none', dirty === 0);
                $('span', pill) && ($$('span', pill).pop().textContent = String(dirty));
            }
            inputs.forEach((el) => el.closest('td, label, .tri')?.classList.toggle('cell-changed', isChanged(el)));
            $$('select.tri-select', form).forEach((s) => { s.className = 'tri-select tri-' + s.value; });
            if (summary) {
                const checked = inputs.filter((el) => el.type === 'checkbox' && el.checked && el.dataset.slug);
                const modules = new Set(checked.map((el) => el.dataset.slug.split('.')[0]));
                const high = checked.filter((el) => el.dataset.high).map((el) => el.dataset.slug);
                summary.textContent = checked.length + ' permission(s) across ' + modules.size + ' module(s)'
                    + (high.length ? ' · high-impact: ' + high.slice(0, 6).join(', ') + (high.length > 6 ? '…' : '') : '');
            }
        }
        form.addEventListener('change', refresh);
        form.addEventListener('input', refresh);

        // High-impact grants: ask before submitting.
        if (form.dataset.confirmHigh) {
            form.addEventListener('submit', (e) => {
                const newlyHigh = inputs.filter((el) => el.type === 'checkbox' && el.dataset.high && el.checked && el.dataset.original === '0');
                if (newlyHigh.length && form.dataset.confirmed !== '1' && !form.dataset.confirm) {
                    form.dataset.confirm = form.dataset.confirmHigh + '\n\n' + newlyHigh.map((el) => el.dataset.slug).join(', ');
                    form.dataset.confirmButton = 'Grant permissions';
                    form.dataset.confirmVariant = 'warning';
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                }
            }, true);
        }

        let submitting = false;
        form.addEventListener('submit', () => { submitting = true; });
        window.addEventListener('beforeunload', (e) => { if (dirty > 0 && !submitting) { e.preventDefault(); e.returnValue = ''; } });

        // Bulk controls
        const setAll = (value) => inputs.forEach((el) => { if (el.type === 'checkbox' && !el.disabled && el.closest('tr')?.style.display !== 'none') el.checked = value; });
        $('[data-matrix-all]', form)?.addEventListener('click', () => { setAll(true); refresh(); });
        $('[data-matrix-none]', form)?.addEventListener('click', () => { setAll(false); refresh(); });
        $('[data-matrix-inherit]', form)?.addEventListener('click', () => { $$('select.tri-select', form).forEach((s) => { s.value = 'inherit'; }); refresh(); });
        $$('[data-matrix-col]', form).forEach((btn) => btn.addEventListener('click', () => {
            const cells = inputs.filter((el) => el.dataset.col === btn.dataset.matrixCol && !el.disabled && el.closest('tr').style.display !== 'none');
            const target = !cells.every((el) => el.checked);
            cells.forEach((el) => { el.checked = target; });
            refresh();
        }));
        $$('[data-matrix-rowtoggle]', form).forEach((box) => box.addEventListener('change', () => {
            $$('input.matrix-check:not(:disabled)', box.closest('tr')).forEach((el) => { el.checked = box.checked; });
            refresh();
        }));

        // Search + filter
        const search = $('[data-matrix-search]', form);
        const filter = $('[data-matrix-filter]', form);
        function applyFilter() {
            const q = (search?.value || '').trim().toLowerCase();
            const f = filter?.value || '';
            $$('[data-matrix-row]', form).forEach((tr) => {
                let show = !q || tr.dataset.name.includes(q);
                if (show && f === 'granted') {
                    show = $$('[data-matrix-input]', tr).some((el) => (el.type === 'checkbox' ? el.checked : el.value !== 'inherit'));
                }
                if (show && f === 'released') show = tr.dataset.released === '1';
                tr.style.display = show ? '' : 'none';
            });
        }
        search?.addEventListener('input', applyFilter);
        filter?.addEventListener('change', applyFilter);
        refresh();
    }

    function initListSearch() {
        $$('[data-list-search]').forEach((input) => {
            const root = $(input.dataset.listSearch);
            if (!root) return;
            input.addEventListener('input', () => {
                const q = input.value.trim().toLowerCase();
                $$('[data-search-item]', root).forEach((el) => { el.style.display = !q || el.dataset.name.includes(q) ? '' : 'none'; });
            });
        });
        $$('[data-check-all]').forEach((b) => b.addEventListener('click', () => { $$('input[type=checkbox]', $(b.dataset.checkAll)).forEach((c) => { if (c.closest('[data-search-item]').style.display !== 'none') c.checked = true; }); b.form?.dispatchEvent(new Event('change')); }));
        $$('[data-check-none]').forEach((b) => b.addEventListener('click', () => { $$('input[type=checkbox]', $(b.dataset.checkNone)).forEach((c) => { c.checked = false; }); b.form?.dispatchEvent(new Event('change')); }));
    }

    function initApproverPickers() {
        $$('[data-step-row]').forEach((row) => {
            const type = $('[data-approver-type]', row);
            const sync = () => $$('[data-approver]', row).forEach((s) => { s.classList.toggle('d-none', s.dataset.approver !== type.value); });
            type?.addEventListener('change', sync);
            sync();
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        $$('form[data-matrix-form]').forEach(initMatrixForm);
        initListSearch();
        initApproverPickers();
    });
})();
