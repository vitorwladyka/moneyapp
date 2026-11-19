/**
 * MoneyApp v2.0 — componentes JS puros (sem framework).
 * Helpers de DOM, fetch, modal, toast.
 */

/** Atalho para document.createElement com className, style, attrs, events e children.
 *  Aceita children tanto em opts.children quanto como 3º argumento (array).
 */
function el(tag, opts = {}, childrenArg) {
    const e = document.createElement(tag);
    if (opts.className) e.className = opts.className;
    if (opts.text) e.textContent = opts.text;
    if (opts.html) e.innerHTML = opts.html;
    if (opts.style) e.setAttribute('style', opts.style);
    if (opts.attrs) {
        for (const [k, v] of Object.entries(opts.attrs)) {
            if (v === null || v === undefined) continue;
            e.setAttribute(k, v);
        }
    }
    if (opts.on) {
        for (const [evt, fn] of Object.entries(opts.on)) {
            e.addEventListener(evt, fn);
        }
    }
    const children = childrenArg !== undefined ? childrenArg : opts.children;
    if (children) {
        for (const c of children) {
            if (c === null || c === undefined) continue;
            e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        }
    }
    return e;
}

/** Wrapper de fetch para JSON. */
async function api(path, options = {}) {
    const opts = {
        headers: { 'Content-Type': 'application/json' },
        ...options,
    };
    if (opts.body && typeof opts.body !== 'string') {
        opts.body = JSON.stringify(opts.body);
    }
    const res = await fetch(path, opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const msg = data.error || `Erro ${res.status}`;
        const err = new Error(msg);
        err.status = res.status;
        err.body = data;
        throw err;
    }
    return data;
}

/** Formata centavos para R$ pt-BR (fallback caso o server não envie). */
function formatCents(c) {
    const abs = Math.abs(c);
    const intPart = Math.floor(abs / 100);
    const frac = abs % 100;
    const grouped = intPart.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const sign = c < 0 ? '-' : '';
    return `${sign}R$ ${grouped},${frac.toString().padStart(2, '0')}`;
}

/** Cria e abre um modal. Retorna { root, close } para controle externo. */
function openModal(title, bodyNode, footerButtons = []) {
    const root = document.getElementById('modal-root');
    root.innerHTML = '';

    const backdrop = el('div', { className: 'modal-backdrop' });
    const modal = el('div', { className: 'modal' });

    const header = el('div', { className: 'modal-header' }, [
        el('h3', { className: 'modal-title', text: title }),
        el('button', { className: 'btn-icon', text: '×', on: { click: close } }),
    ]);

    const body = el('div', { className: 'modal-body' });
    body.appendChild(bodyNode);

    const footer = el('div', { className: 'modal-footer' });
    for (const b of footerButtons) footer.appendChild(b);
    if (footerButtons.length === 0) footer.style.display = 'none';

    modal.appendChild(header);
    modal.appendChild(body);
    modal.appendChild(footer);
    backdrop.appendChild(modal);
    root.appendChild(backdrop);

    backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) close();
    });

    document.addEventListener('keydown', escClose);

    function escClose(e) {
        if (e.key === 'Escape') {
            close();
            document.removeEventListener('keydown', escClose);
        }
    }

    function close() {
        root.innerHTML = '';
        document.removeEventListener('keydown', escClose);
    }

    return { root: modal, close };
}

/** Toast notification. */
function toast(message, type = 'info') {
    const root = document.getElementById('toast-root');
    const t = el('div', { className: `toast ${type}`, text: message });
    root.appendChild(t);
    setTimeout(() => {
        t.style.opacity = '0';
        t.style.transition = 'opacity 0.3s';
        setTimeout(() => t.remove(), 300);
    }, 3500);
}

/** Helper de select para formulários. */
function buildSelect(options, value, placeholder = 'Selecione...') {
    const sel = el('select', { className: 'select' });
    if (placeholder) {
        sel.appendChild(el('option', { attrs: { value: '' }, text: placeholder }));
    }
    for (const o of options) {
        const opt = el('option', {
            attrs: { value: o.value },
            text: o.label,
        });
        if (String(o.value) === String(value)) opt.selected = true;
        sel.appendChild(opt);
    }
    return sel;
}

/** Helper de input. */
function buildInput(type, value, placeholder = '') {
    return el('input', {
        className: 'input',
        attrs: { type, value: value ?? '', placeholder },
    });
}

/** Helper de label + field wrapper. */
function field(labelText, inputNode, hint) {
    return el('div', { className: 'field' }, [
        el('label', { className: 'label', text: labelText }),
        inputNode,
        hint ? el('p', { className: 'form-hint', text: hint }) : null,
    ]);
}
