(() => {
    const root = document.querySelector('[data-pdf-editor]');
    if (!root) return;
    const input = root.querySelector('textarea[name="markdown"]');
    const form = root.querySelector('[data-pdf-form]');
    const generate = root.querySelector('[data-pdf-generate]');
    const refresh = root.querySelector('[data-pdf-refresh]');
    const download = root.querySelector('[data-pdf-download]');
    const frame = root.querySelector('[data-pdf-frame]');
    const surface = root.querySelector('[data-pdf-surface]');
    const status = root.querySelector('[data-pdf-status]');
    const error = root.querySelector('[data-pdf-error]');
    const errorMessage = root.querySelector('[data-pdf-error-message]');
    const stale = root.querySelector('[data-pdf-stale]');
    const empty = root.querySelector('[data-pdf-empty]');
    const size = root.querySelector('[data-pdf-size]');
    const narrow = window.matchMedia('(max-width: 1000px)');
    const maxBytes = Number(root.dataset.maxBytes);
    const allowed = root.dataset.previewAllowed !== 'false';
    let panel = 'editor';
    let url;
    let blob;
    let renderedSource;
    let pending;

    function showPanel(next = panel) {
        panel = next;
        root.querySelector('#pdf-source-panel').hidden = narrow.matches && panel !== 'editor';
        root.querySelector('#pdf-preview-panel').hidden = narrow.matches && panel !== 'preview';
        root.querySelectorAll('[data-pdf-panel]').forEach(button => {
            button.setAttribute('aria-pressed', String(button.dataset.pdfPanel === panel));
        });
    }
    function updateState() {
        const bytes = new TextEncoder().encode(input.value).length;
        size.textContent = `${(bytes / 1024).toFixed(1)} / ${maxBytes / 1024} KiB`;
        stale.hidden = !blob || input.value === renderedSource;
        download.disabled = !url || Boolean(pending);
    }
    function showError(message) {
        errorMessage.textContent = message;
        error.hidden = false;
    }
    function releaseUrl() {
        if (url) URL.revokeObjectURL(url);
        url = undefined;
    }
    function displayBlob() {
        releaseUrl();
        url = URL.createObjectURL(blob);
        frame.src = url;
        frame.hidden = false;
        empty.hidden = true;
    }
    async function render() {
        if (pending || !allowed) return;
        const source = input.value;
        error.hidden = true;
        if (!source.trim()) {
            showError('Escribe contenido Markdown antes de generar el PDF.');
            showPanel('editor');
            input.focus();
            return;
        }
        if (new TextEncoder().encode(source).length > maxBytes) {
            showError(`El contenido supera el límite de ${maxBytes / 1024} KiB. Tu Markdown se conserva.`);
            return;
        }
        const controller = new AbortController();
        pending = controller;
        generate.disabled = refresh.disabled = true;
        generate.textContent = 'Generando PDF…';
        surface.setAttribute('aria-busy', 'true');
        status.textContent = 'Generando vista previa…';
        updateState();
        const timeout = window.setTimeout(() => controller.abort('timeout'), 60000);
        try {
            const response = await fetch(root.dataset.previewUrl, {
                method: 'POST', credentials: 'same-origin', redirect: 'error', signal: controller.signal,
                headers: {
                    'Content-Type': 'text/plain; charset=UTF-8', 'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: source,
            });
            if (!response.ok) {
                const messages = {
                    401: 'Tu sesión terminó. Conserva tu Markdown antes de iniciar sesión de nuevo.',
                    403: 'Tu cuenta no tiene permiso para generar vistas previas PDF.',
                    413: 'El contenido supera el límite permitido de 100 KiB.',
                    419: 'Tu sesión expiró. Copia tu Markdown antes de recargar la página.',
                    422: 'Revisa que el contenido sea texto Markdown válido.',
                    429: 'Has generado varias vistas previas seguidas. Espera un minuto e inténtalo de nuevo.',
                };
                throw new Error(messages[response.status] || 'No fue posible generar el PDF. Tu Markdown se conserva; inténtalo de nuevo.');
            }
            if (!response.headers.get('Content-Type')?.startsWith('application/pdf')) throw new Error('La respuesta no contiene un PDF válido.');
            const nextBlob = await response.blob();
            if (controller.signal.aborted) return;
            blob = nextBlob;
            renderedSource = source;
            displayBlob();
            status.textContent = 'Vista previa generada. Puedes recorrer sus páginas o descargarla.';
            showPanel('preview');
        } catch (failure) {
            if (controller.signal.reason === 'leaving') return;
            showError(controller.signal.aborted
                ? 'La generación tardó demasiado. Tu Markdown y la vista previa anterior se conservan; vuelve a intentarlo.'
                : failure.message === 'Failed to fetch' || failure instanceof TypeError
                    ? 'No se pudo conectar con el servidor. Tu Markdown se conserva.' : failure.message);
            status.textContent = blob ? 'Se conserva la última vista previa generada.' : 'No se pudo generar la vista previa.';
        } finally {
            clearTimeout(timeout);
            pending = undefined;
            generate.disabled = refresh.disabled = false;
            generate.textContent = 'Actualizar vista previa';
            surface.setAttribute('aria-busy', 'false');
            updateState();
        }
    }
    form.addEventListener('submit', event => { event.preventDefault(); render(); });
    refresh.addEventListener('click', render);
    input.addEventListener('input', updateState);
    root.querySelectorAll('[data-pdf-panel]').forEach(button => button.addEventListener('click', () => showPanel(button.dataset.pdfPanel)));
    narrow.addEventListener('change', () => showPanel());
    download.addEventListener('click', () => {
        if (!url || pending) return;
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'documento-preview.pdf';
        document.body.append(anchor);
        anchor.click();
        anchor.remove();
        if (input.value !== renderedSource) window.AdminNotify?.show({ type: 'warning', message: 'Descargaste la vista previa anterior. Actualízala para incluir tus cambios.' });
    });
    window.addEventListener('pagehide', () => { pending?.abort('leaving'); releaseUrl(); });
    window.addEventListener('pageshow', event => { if (event.persisted && blob) { displayBlob(); updateState(); } });
    showPanel();
    generate.disabled = refresh.disabled = !allowed;
    updateState();
})();
