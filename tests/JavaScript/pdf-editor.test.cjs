const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Exercise the actual client script's state transitions without a browser or dependencies.
// This deliberately does not claim to test native PDF rendering or CSS layout.
const script = fs.readFileSync(path.join(__dirname, '../../public/assets/base-admin/pdf-editor.js'), 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));
class Element extends EventTarget {
    constructor() { super(); this.hidden = false; this.disabled = false; this.value = ''; this.dataset = {}; this.attributes = {}; }
    setAttribute(name, value) { this.attributes[name] = value; }
    focus() { this.focused = true; }
    remove() {}
    click() { this.dispatchEvent(new Event('click')); }
}
function mount(fetchImpl = async () => ({ ok: true, headers: new Headers({ 'Content-Type': 'application/pdf' }), blob: async () => new Blob(['%PDF-preview'], { type: 'application/pdf' }) })) {
    const nodes = new Map();
    const node = key => { if (!nodes.has(key)) nodes.set(key, new Element()); return nodes.get(key); };
    const root = new Element();
    root.dataset = { previewUrl: '/admin/pdf-documents/preview', maxBytes: '102400' };
    root.querySelector = node;
    const tabs = ['editor', 'preview'].map(panel => Object.assign(new Element(), { dataset: { pdfPanel: panel } }));
    root.querySelectorAll = () => tabs;
    const window = new EventTarget();
    const media = Object.assign(new EventTarget(), { matches: false });
    window.matchMedia = () => media;
    window.setTimeout = setTimeout;
    const notifications = []; window.AdminNotify = { show: value => notifications.push(value) };
    const urls = new Map(); const revoked = []; const downloads = []; const requests = [];
    let serial = 0;
    const URL = {
        createObjectURL: blob => { const url = `blob:preview-${++serial}`; urls.set(url, blob); return url; },
        revokeObjectURL: url => { revoked.push(url); urls.delete(url); },
    };
    const document = {
        querySelector: selector => selector === '[data-pdf-editor]' ? root : { content: 'csrf-test' },
        body: { append() {} },
        createElement: () => Object.assign(new Element(), { click() { downloads.push({ url: this.href, blob: urls.get(this.href), name: this.download }); } }),
    };
    vm.runInNewContext(script, { document, window, URL, TextEncoder, AbortController, clearTimeout, TypeError,
        fetch: (...args) => { requests.push(args); return fetchImpl(...args); } });
    const input = value => { node('textarea').value = value; node('textarea').dispatchEvent(new Event('input')); };
    const submit = () => node('[data-pdf-form]').dispatchEvent(new Event('submit', { cancelable: true }));
    return { node, input, submit, tabs, media, window, urls, revoked, downloads, requests, notifications };
}

test('typing never generates a PDF; submit preserves the exact source and uses CSRF', async () => {
    const ui = mount(); const source = '    código\r\n\n:::page-break\nTexto  ';
    ui.input(source); assert.equal(ui.requests.length, 0);
    ui.submit(); await settle();
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.requests[0][1].body, source);
    assert.equal(ui.requests[0][1].headers['X-CSRF-TOKEN'], 'csrf-test');
    assert.equal(ui.node('textarea').value, source);
    assert.equal(ui.node('[data-pdf-frame]').hidden, false);
});
test('download reuses the preview bytes without another request', async () => {
    const ui = mount(); ui.input('Texto'); ui.submit(); await settle();
    ui.node('[data-pdf-download]').click();
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.downloads[0].url, ui.node('[data-pdf-frame]').src);
    assert.equal(await ui.downloads[0].blob.text(), '%PDF-preview');
});
test('edits warn about the old PDF and offer an explicit refresh', async () => {
    const ui = mount(); ui.input('Antes'); ui.submit(); await settle();
    ui.input('Después'); assert.equal(ui.node('[data-pdf-stale]').hidden, false);
    ui.node('[data-pdf-download]').click(); assert.equal(ui.notifications[0].type, 'warning');
    ui.node('[data-pdf-refresh]').click(); await settle();
    assert.equal(ui.node('[data-pdf-stale]').hidden, true);
    assert.equal(ui.requests.length, 2); assert.deepEqual(ui.revoked, ['blob:preview-1']);
});
test('server errors keep the Markdown and the previous PDF', async () => {
    let fail = false;
    const ui = mount(async () => fail ? { ok: false, status: 503 } : { ok: true, headers: new Headers({ 'Content-Type': 'application/pdf' }), blob: async () => new Blob(['%PDF-old']) });
    ui.input('Primero'); ui.submit(); await settle(); const previous = ui.node('[data-pdf-frame]').src;
    fail = true; ui.input('Edición sin perder'); ui.submit(); await settle();
    assert.equal(ui.node('textarea').value, 'Edición sin perder');
    assert.equal(ui.node('[data-pdf-frame]').src, previous);
    assert.equal(ui.node('[data-pdf-error]').hidden, false);
    assert.equal(ui.node('[data-pdf-generate]').disabled, false);
});
test('edits while generating remain dirty and duplicate submissions are suppressed', async () => {
    let finish;
    const ui = mount(() => new Promise(resolve => { finish = resolve; }));
    ui.input('Captura'); ui.submit(); ui.submit(); ui.input('Cambio durante generación');
    assert.equal(ui.requests.length, 1);
    finish({ ok: true, headers: new Headers({ 'Content-Type': 'application/pdf' }), blob: async () => new Blob(['%PDF-capture']) });
    await settle(); assert.equal(ui.node('[data-pdf-stale]').hidden, false);
    assert.equal(ui.node('textarea').value, 'Cambio durante generación');
});
test('input limits count UTF-8 bytes and never truncate the editor', () => {
    const ui = mount(); const source = 'é'.repeat(51201); ui.input(source); ui.submit();
    assert.equal(ui.requests.length, 0); assert.equal(ui.node('textarea').value, source);
    assert.equal(ui.node('[data-pdf-error]').hidden, false);
});
test('replacing and leaving release Blob URLs; back/forward restores the same Blob', async () => {
    const ui = mount(); ui.input('Texto'); ui.submit(); await settle();
    const blob = ui.urls.get('blob:preview-1');
    ui.window.dispatchEvent(new Event('pagehide')); assert.equal(ui.urls.size, 0);
    const event = new Event('pageshow'); event.persisted = true; ui.window.dispatchEvent(event);
    assert.equal(ui.urls.get('blob:preview-2'), blob); assert.equal(ui.requests.length, 1);
});
test('small-screen controls alternate panes and successful generation opens the viewer', async () => {
    const ui = mount(); ui.media.matches = true; ui.media.dispatchEvent(new Event('change'));
    assert.equal(ui.node('#pdf-preview-panel').hidden, true);
    ui.input('Documento'); ui.submit(); await settle();
    assert.equal(ui.node('#pdf-source-panel').hidden, true);
    assert.equal(ui.node('#pdf-preview-panel').hidden, false);
    ui.tabs[0].click(); assert.equal(ui.node('#pdf-source-panel').hidden, false);
});
