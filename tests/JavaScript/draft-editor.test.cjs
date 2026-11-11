const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const scripts = ['draft-editor.js', 'pdf-editor.js'].map(name => fs.readFileSync(path.join(__dirname, '../../public/assets/base-admin', name), 'utf8'));
const settle = () => new Promise(resolve => setImmediate(resolve));
class Element extends EventTarget {
    constructor() { super(); this.hidden = false; this.disabled = false; this.dataset = {}; this.attributes = {}; this._value = ''; }
    set value(value) { this._value = value.replace(/\r\n?/g, '\n'); }
    get value() { return this._value; }
    setAttribute(key, value) { this.attributes[key] = value; }
    getAttribute(key) { return this.attributes[key] ?? null; }
    removeAttribute(key) { delete this.attributes[key]; }
    focus() { this.focused = true; }
    click() { return this.dispatchEvent(new Event('click', { cancelable: true })); }
    showModal() { this.open = true; }
    close() { this.open = false; this.dispatchEvent(new Event('close')); }
}
const okSave = () => ({ ok: true, json: async () => ({ uuid: 'doc-uuid', edit_url: '/admin/pdf-documents/doc-uuid/edit', update_url: '/admin/pdf-documents/doc-uuid', can_update: true }) });
const okPdf = () => ({ ok: true, headers: new Headers({ 'Content-Type': 'application/pdf' }), blob: async () => new Blob(['%PDF-preview']) });
function mount({ initial = { title: '', body_markdown: '', notes: '' }, exists = false, save = okSave, preview = okPdf } = {}) {
    const nodes = new Map();
    const node = key => { if (!nodes.has(key)) nodes.set(key, Object.assign(new Element(), { querySelector: node })); return nodes.get(key); };
    const root = new Element();
    root.dataset = { saved: String(exists), saveUrl: exists ? '/admin/pdf-documents/doc-uuid' : '/admin/pdf-documents', previewUrl: '/admin/pdf-documents/preview', maxBytes: '102400' };
    root.querySelector = node;
    root.querySelectorAll = () => [];
    node('[data-draft-initial]').textContent = JSON.stringify(initial);
    const window = new EventTarget();
    const notifications = []; const locations = []; const requests = [];
    window.matchMedia = () => Object.assign(new EventTarget(), { matches: false });
    window.setTimeout = setTimeout;
    window.AdminNotify = { show: value => notifications.push(value) };
    window.history = { replaceState: (state, title, url) => locations.push(url) };
    const departures = [];
    window.location = { assign: url => departures.push(url) };
    node('[data-draft-back]').href = '/admin/pdf-documents';
    node('[data-draft-leave-trigger]').addEventListener('click', () => node('#draft-leave-dialog').showModal());
    const document = { title: exists ? 'Editar documento · Cotizador Acerca' : 'Nuevo documento · Cotizador Acerca', querySelector: selector => selector.startsWith('[data-') ? root : selector === '.page-heading h1' ? node(selector) : { content: 'csrf-test' } };
    let serial = 0;
    const context = vm.createContext({ document, window, TextEncoder, AbortController, clearTimeout, TypeError,
        URL: { createObjectURL: () => `blob:test-${++serial}`, revokeObjectURL() {} },
        fetch: (url, options) => { requests.push({ url, options }); return Promise.resolve(url.endsWith('/preview') ? preview(options) : save(options)); } });
    scripts.forEach(script => vm.runInContext(script, context));
    const selectors = { title: '[name="title"]', body_markdown: 'textarea[name="markdown"]', notes: '[name="notes"]' };
    const input = (key, value) => { node(selectors[key]).value = value; node(selectors[key]).dispatchEvent(new Event('input')); };
    const leave = () => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented; };
    return { node, input, leave, requests, notifications, locations, departures, document, save: () => node('[data-draft-save]').click(),
        preview: () => node('[data-pdf-form]').dispatchEvent(new Event('submit', { cancelable: true })) };
}

test('saving creates once, stays in editor and later updates without generating a PDF', async () => {
    const ui = mount(); ui.input('title', 'Mi documento'); ui.input('body_markdown', '\nTexto  \n:::page-break\nOtro');
    ui.save(); await settle();
    assert.equal(ui.requests.length, 1); assert.equal(ui.requests[0].options.method, 'POST');
    assert.equal(JSON.parse(ui.requests[0].options.body).body_markdown, '\nTexto  \n:::page-break\nOtro');
    assert.equal(ui.requests[0].options.headers['X-CSRF-TOKEN'], 'csrf-test');
    assert.equal(ui.locations[0], '/admin/pdf-documents/doc-uuid/edit');
    assert.equal(ui.node('[data-draft-status]').textContent, 'Guardado');
    assert.equal(ui.leave(), false); assert.equal(ui.notifications[0].type, 'success');
    ui.input('notes', 'Solo interno'); ui.save(); await settle();
    assert.equal(ui.requests[1].options.method, 'PUT');
    assert.equal(ui.requests[1].url, '/admin/pdf-documents/doc-uuid');
    assert.equal(ui.requests.filter(request => request.url.endsWith('/preview')).length, 0);
});

test('all editable fields warn before leaving and reverting removes the warning', () => {
    const initial = { title: 'Guardado', body_markdown: 'Texto', notes: 'Nota' };
    const ui = mount({ initial, exists: true });
    assert.equal(ui.leave(), false);
    for (const field of Object.keys(initial)) {
        ui.input(field, 'Modificado'); assert.equal(ui.leave(), true);
        assert.equal(ui.node('[data-draft-status]').textContent, 'Cambios sin guardar');
        ui.input(field, initial[field]); assert.equal(ui.leave(), false);
    }
});

test('reopening and saving only a title preserves original CRLF and whitespace bytes', async () => {
    const initial = { title: 'Original', body_markdown: '\r\n    Código  \r\n:::page-break\r\nFinal\t\r\n', notes: 'Nota\r\n' };
    const ui = mount({ initial, exists: true });
    assert.equal(ui.node('textarea[name="markdown"]').value, initial.body_markdown.replace(/\r\n/g, '\n'));
    assert.equal(ui.leave(), false);
    ui.input('title', 'Otro título'); ui.save(); await settle();
    const sent = JSON.parse(ui.requests[0].options.body);
    assert.equal(sent.body_markdown, initial.body_markdown);
    assert.equal(sent.notes, initial.notes);
});

test('preview uses unsaved Markdown, excludes notes and does not mark draft as saved', async () => {
    const ui = mount({ initial: { title: 'Guardado', body_markdown: 'Antes', notes: 'Secreto interno' }, exists: true });
    ui.input('body_markdown', 'Todavía sin guardar'); ui.preview(); await settle();
    assert.equal(ui.requests[0].options.body, 'Todavía sin guardar');
    assert.equal(ui.node('[data-pdf-stale]').hidden, true);
    assert.equal(ui.node('[data-draft-status]').textContent, 'Cambios sin guardar');
    assert.equal(ui.leave(), true);
});

test('title and notes affect save status only; saving changed Markdown leaves PDF stale', async () => {
    const ui = mount({ initial: { title: 'Original', body_markdown: 'Texto', notes: '' }, exists: true });
    ui.preview(); await settle();
    ui.input('title', 'Otro'); ui.input('notes', 'Interno');
    assert.equal(ui.node('[data-pdf-stale]').hidden, true);
    ui.input('body_markdown', 'Texto cambiado'); ui.save(); await settle();
    assert.equal(ui.node('[data-draft-status]').textContent, 'Guardado');
    assert.equal(ui.node('[data-pdf-stale]').hidden, false);
    assert.equal(ui.requests.length, 2);
});

test('validation failure preserves all input, old PDF, dirty state and field feedback', async () => {
    const ui = mount({ save: () => ({ ok: false, status: 422, json: async () => ({ errors: { title: ['Escribe un título.'] } }) }) });
    ui.input('body_markdown', 'Mi texto'); ui.input('notes', 'Nota'); ui.preview(); await settle();
    const previous = ui.node('[data-pdf-frame]').src;
    ui.save(); await settle();
    assert.equal(ui.node('[data-draft-field-error="title"]').textContent, 'Escribe un título.');
    assert.equal(ui.node('[name="title"]').focused, true);
    assert.equal(ui.node('textarea[name="markdown"]').value, 'Mi texto');
    assert.equal(ui.node('[name="notes"]').value, 'Nota');
    assert.equal(ui.node('[data-pdf-frame]').src, previous);
    assert.equal(ui.leave(), true); assert.equal(ui.node('[data-draft-error]').hidden, false);
});

test('edits during a save remain dirty and double clicks do not create duplicates', async () => {
    let finish;
    const ui = mount({ save: () => new Promise(resolve => { finish = resolve; }) });
    ui.input('title', 'Capturado'); ui.input('body_markdown', 'Primero'); ui.save(); ui.save();
    ui.input('body_markdown', 'Después');
    assert.equal(ui.requests.length, 1);
    finish(okSave()); await settle();
    assert.equal(ui.node('textarea[name="markdown"]').value, 'Después');
    assert.equal(ui.node('[data-draft-status]').textContent, 'Cambios sin guardar');
    assert.equal(ui.leave(), true);
});

test('network and authorization errors keep the source and permit retry', async () => {
    for (const save of [() => Promise.reject(new TypeError('offline')), () => ({ ok: false, status: 403, json: async () => ({}) })]) {
        const ui = mount({ save }); ui.input('title', 'Título'); ui.input('body_markdown', 'Conservar'); ui.save(); await settle();
        assert.equal(ui.node('textarea[name="markdown"]').value, 'Conservar');
        assert.equal(ui.node('[data-draft-save]').disabled, false);
        assert.equal(ui.node('[data-draft-error]').hidden, false);
        assert.equal(ui.leave(), true); assert.equal(ui.locations.length, 0);
    }
});

test('successful first save switches the shared editor from new to editing context', async () => {
    const ui = mount();
    assert.equal(ui.node('[data-draft-status]').textContent, 'Sin guardar');
    ui.input('title', 'Título guardado'); ui.input('body_markdown', 'Texto'); ui.save(); await settle();
    assert.equal(ui.document.title, 'Editar documento · Cotizador Acerca');
    assert.equal(ui.node('.page-heading h1').textContent, 'Editar documento');
    assert.equal(ui.node('[data-draft-document-title]').textContent, 'Título guardado');
    assert.equal(ui.node('[data-draft-status]').dataset.state, 'saved');
    assert.equal(ui.node('[data-draft-save]').attributes['aria-busy'], 'false');
});

test('back navigation is always available and dirty drafts can be abandoned without saving', () => {
    const ui = mount();
    assert.equal(ui.node('[data-draft-back]').click(), true);
    assert.equal(ui.node('#draft-leave-dialog').open, undefined);
    ui.input('body_markdown', 'No guardar');
    assert.equal(ui.node('[data-draft-back]').click(), false);
    assert.equal(ui.node('#draft-leave-dialog').open, true);
    assert.equal(ui.node('#draft-leave-dialog')._focusReturn, ui.node('[data-draft-back]'));
    ui.node('#draft-leave-dialog').close(); // Cancel/Escape retain changes and browser warning.
    assert.equal(ui.departures.length, 0); assert.equal(ui.leave(), true);
    ui.node('[data-draft-back]').click();
    ui.node('[data-confirm-accept]').click();
    assert.deepEqual(ui.departures, ['/admin/pdf-documents']);
    assert.equal(ui.leave(), false); assert.equal(ui.requests.length, 0);
    assert.equal(ui.node('textarea[name="markdown"]').value, 'No guardar');
});

test('reverting or saving leaves the back link free of unnecessary confirmation', async () => {
    const ui = mount({ initial: { title: 'Guardado', body_markdown: 'Texto', notes: '' }, exists: true });
    ui.input('notes', 'Cambiar'); ui.input('notes', '');
    assert.equal(ui.node('[data-draft-back]').click(), true);
    ui.input('notes', 'Nueva nota'); ui.save(); await settle();
    assert.equal(ui.node('[data-draft-back]').click(), true); assert.equal(ui.leave(), false);
});

test('notes errors open the disclosure and remain announced when collapsed again', async () => {
    let fail = true;
    const ui = mount({ save: () => fail ? ({ ok: false, status: 422, json: async () => ({ errors: { notes: ['Notas demasiado extensas.'] } }) }) : okSave() });
    ui.input('title', 'Prueba'); ui.input('body_markdown', 'Texto'); ui.input('notes', 'Nota');
    ui.node('[data-draft-notes]').open = false;
    ui.save(); await settle();
    assert.equal(ui.node('[data-draft-notes]').open, true);
    assert.equal(ui.node('[name="notes"]').focused, true);
    assert.equal(ui.node('[data-draft-status]').textContent, 'Error al guardar');
    ui.node('[data-draft-notes]').open = false;
    assert.equal(ui.node('[data-draft-notes-error]').hidden, false);
    assert.equal(ui.node('[data-draft-notes-error]').textContent, 'Notas demasiado extensas.');
    fail = false; ui.save(); await settle();
    assert.equal(ui.node('[data-draft-notes-error]').hidden, true);
    assert.equal(ui.node('[data-draft-status]').textContent, 'Guardado');
});

test('save and PDF states include loading and failures independently, retaining the prior preview', async () => {
    let failPreview = false; let finish;
    const ui = mount({ save: () => new Promise(resolve => { finish = resolve; }), preview: () => failPreview ? ({ ok: false, status: 503 }) : okPdf() });
    assert.equal(ui.node('[data-pdf-status]').textContent, 'Sin vista previa');
    ui.input('title', 'Documento'); ui.input('body_markdown', 'Primero'); ui.preview();
    assert.equal(ui.node('[data-pdf-status]').textContent, 'Generando PDF');
    await settle(); assert.equal(ui.node('[data-pdf-status]').textContent, 'Vista previa actualizada');
    const old = ui.node('[data-pdf-frame]').src;
    ui.input('body_markdown', 'Después'); assert.equal(ui.node('[data-pdf-status]').textContent, 'Vista previa desactualizada');
    ui.save(); assert.equal(ui.node('[data-draft-status]').textContent, 'Guardando');
    assert.equal(ui.node('[data-draft-save]').attributes['aria-busy'], 'true');
    finish(okSave()); await settle();
    assert.equal(ui.node('[data-draft-status]').textContent, 'Guardado');
    failPreview = true; ui.preview(); await settle();
    assert.equal(ui.node('[data-pdf-status]').textContent, 'Error al generar');
    assert.equal(ui.node('[data-draft-status]').textContent, 'Guardado');
    assert.equal(ui.node('[data-pdf-frame]').src, old);
    assert.equal(ui.node('[data-pdf-download]').disabled, false);
});
