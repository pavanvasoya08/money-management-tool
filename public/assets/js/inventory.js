/* Inventory line editor: add / remove lines, show batch / serial / expiry fields only for items that track them,
   and the fields that belong to the chosen document type or adjustment line type. Works on server-rendered lines too. */
(function () {
    'use strict';
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    function refreshLine(line) {
        const item = line.querySelector('[data-line-item]');
        const opt = item && item.selectedOptions[0];
        const flags = { batch: opt ? opt.dataset.batch === '1' : false, serial: opt ? opt.dataset.serial === '1' : false };
        $$('[data-needs]', line).forEach((el) => {
            const need = el.dataset.needs;
            const keep = flags[need] || (el.querySelector('input, textarea') && el.querySelector('input, textarea').value !== '');
            el.classList.toggle('d-none', !keep);
        });
        const unit = line.querySelector('[data-line-unit]');
        if (unit && opt && opt.dataset.unit) {
            const first = unit.options[0];
            if (first && first.value === '') first.textContent = 'Stock unit (' + opt.dataset.unit + ')';
        }
        const type = line.querySelector('[data-line-type]');
        if (type) {
            $$('[data-show-type]', line).forEach((el) => el.classList.toggle('d-none', el.dataset.showType !== type.value));
        }
        refreshEntryType(line);
    }

    function refreshEntryType(root) {
        const sel = document.querySelector('[data-entry-type]');
        $$('[data-show-entry]', root).forEach((el) => {
            const types = el.dataset.showEntry.split(' ');
            el.classList.toggle('d-none', !!sel && !types.includes(sel.value));
        });
    }

    function bind(line) {
        line.addEventListener('change', (e) => {
            if (e.target.matches('[data-line-item], [data-line-type]')) refreshLine(line);
        });
        const remove = line.querySelector('[data-line-remove]');
        if (remove) {
            remove.addEventListener('click', () => {
                const box = line.closest('[data-lines]');
                if (box && box.querySelectorAll('[data-line]').length > 1) {
                    line.remove();
                } else {
                    $$('input, textarea', line).forEach((i) => { i.value = ''; });
                    $$('select', line).forEach((s) => { s.selectedIndex = 0; });
                    refreshLine(line);
                }
            });
        }
        refreshLine(line);
    }

    $$('[data-lines]').forEach((box) => {
        const wrap = box.parentElement;
        const tpl = wrap.querySelector('template[data-line-template]');
        const add = wrap.querySelector('[data-line-add]');
        $$('[data-line]', box).forEach(bind);
        if (tpl && add) {
            add.addEventListener('click', () => {
                const n = parseInt(box.dataset.next || '1', 10);
                box.dataset.next = String(n + 1);
                const html = tpl.innerHTML.replace(/__N__/g, String(n));
                const holder = document.createElement('div');
                holder.innerHTML = html.trim();
                const line = holder.firstElementChild;
                $$('[data-line-item]', line).forEach((s) => s.setAttribute('required', ''));
                box.appendChild(line);
                bind(line);
                if (window.lucide) window.lucide.createIcons();
                const first = line.querySelector('select, input');
                if (first) first.focus();
            });
        }
    });

    const entryType = document.querySelector('[data-entry-type]');
    if (entryType) {
        entryType.addEventListener('change', () => refreshEntryType(document));
        refreshEntryType(document);
    }
})();
