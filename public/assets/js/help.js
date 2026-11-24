/*
 * Help & User Guide: screenshot markers, table-of-contents highlight,
 * screenshot annotation editor and step/FAQ repeaters (management screens).
 * Vanilla JS only. Positions are applied through the CSSOM (CSP-safe).
 */
(function () {
    'use strict';

    const refreshIcons = () => { if (window.lucide) { window.lucide.createIcons(); } };

    function placeMarkers(root) {
        (root || document).querySelectorAll('.doc-marker[data-x]').forEach((m) => {
            m.style.left = parseFloat(m.dataset.x) + '%';
            m.style.top = parseFloat(m.dataset.y) + '%';
        });
    }

    function initToc() {
        const links = Array.from(document.querySelectorAll('.doc-toc a[href^="#"]'));
        if (!links.length || !('IntersectionObserver' in window)) { return; }
        const byId = new Map(links.map((a) => [a.getAttribute('href').slice(1), a]));
        const io = new IntersectionObserver((entries) => {
            entries.forEach((e) => {
                if (e.isIntersecting) {
                    links.forEach((a) => a.classList.remove('is-current'));
                    const a = byId.get(e.target.id);
                    if (a) { a.classList.add('is-current'); }
                }
            });
        }, { rootMargin: '-80px 0px -70% 0px' });
        byId.forEach((_, id) => { const el = document.getElementById(id); if (el) { io.observe(el); } });
    }

    /* ---------- Annotation editor: click the image to add a numbered marker ---------- */
    function initAnnotators() {
        document.querySelectorAll('[data-annotator]').forEach((box) => {
            const frame = box.querySelector('.annotator');
            const list = box.querySelector('[data-marker-list]');
            const field = box.querySelector('input[name="annotations"]');
            let markers = [];
            try { markers = JSON.parse(field.value || '[]') || []; } catch (e) { markers = []; }

            const sync = () => { field.value = JSON.stringify(markers); };
            const render = () => {
                frame.querySelectorAll('.doc-marker').forEach((m) => m.remove());
                list.innerHTML = '';
                markers.forEach((mk, i) => {
                    const dot = document.createElement('span');
                    dot.className = 'doc-marker';
                    dot.textContent = String(i + 1);
                    dot.dataset.x = mk.x; dot.dataset.y = mk.y;
                    dot.title = 'Marker ' + (i + 1);
                    frame.appendChild(dot);

                    const row = document.createElement('div');
                    row.className = 'marker-row';
                    const no = document.createElement('span'); no.className = 'marker-no'; no.textContent = String(i + 1);
                    const input = document.createElement('input');
                    input.className = 'form-control form-control-sm'; input.maxLength = 160; input.value = mk.label || '';
                    input.placeholder = 'What should the reader do or notice here?';
                    input.setAttribute('aria-label', 'Label for marker ' + (i + 1));
                    input.addEventListener('input', () => { mk.label = input.value; sync(); });
                    const del = document.createElement('button');
                    del.type = 'button'; del.className = 'btn btn-sm btn-light'; del.textContent = 'Remove';
                    del.addEventListener('click', () => { markers.splice(i, 1); sync(); render(); });
                    row.append(no, input, del);
                    list.appendChild(row);
                });
                placeMarkers(frame);
                if (!markers.length) {
                    const p = document.createElement('p'); p.className = 'small text-secondary mb-0';
                    p.textContent = 'No markers yet. Click on the screenshot where the reader should look.';
                    list.appendChild(p);
                }
            };
            frame.addEventListener('click', (ev) => {
                ev.preventDefault();
                if (markers.length >= 20) { return; }
                const img = frame.querySelector('img');
                const r = img.getBoundingClientRect();
                const x = Math.round(((ev.clientX - r.left) / r.width) * 10000) / 100;
                const y = Math.round(((ev.clientY - r.top) / r.height) * 10000) / 100;
                if (x < 0 || y < 0 || x > 100 || y > 100) { return; }
                markers.push({ x, y, label: '' });
                sync(); render();
                const inputs = list.querySelectorAll('input');
                if (inputs.length) { inputs[inputs.length - 1].focus(); }
            });
            render();
        });
    }

    /* ---------- Repeaters: steps and FAQs ---------- */
    function initRepeaters() {
        document.querySelectorAll('[data-repeater]').forEach((rep) => {
            const items = rep.querySelector('[data-repeater-items]');
            const tpl = rep.querySelector('template');
            let counter = items.children.length + 1000;

            const renumber = () => {
                Array.from(items.children).forEach((el, i) => {
                    const no = el.querySelector('.repeater-no');
                    if (no) { no.textContent = (rep.dataset.label || 'Item') + ' ' + (i + 1); }
                });
            };
            rep.querySelector('[data-repeater-add]').addEventListener('click', () => {
                const html = tpl.innerHTML.replace(/__i__/g, String(counter++));
                const wrap = document.createElement('div');
                wrap.innerHTML = html.trim();
                const el = wrap.firstElementChild;
                items.appendChild(el);
                renumber(); refreshIcons();
                const first = el.querySelector('input, textarea');
                if (first) { first.focus(); }
            });
            items.addEventListener('click', (ev) => {
                const btn = ev.target.closest('[data-repeater-action]');
                if (!btn) { return; }
                const item = btn.closest('.repeater-item');
                const action = btn.dataset.repeaterAction;
                if (action === 'remove') { item.remove(); }
                if (action === 'up' && item.previousElementSibling) { items.insertBefore(item, item.previousElementSibling); }
                if (action === 'down' && item.nextElementSibling) { items.insertBefore(item.nextElementSibling, item); }
                renumber();
            });
            renumber();
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        placeMarkers();
        initToc();
        initAnnotators();
        initRepeaters();
    });
})();
