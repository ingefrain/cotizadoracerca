(() => {
    const root = document.querySelector('[data-draft-editor]');
    if (!root) return;
    const fields = {
        title: root.querySelector('[name="title"]'),
        body_markdown: root.querySelector('textarea[name="markdown"]'),
        notes: root.querySelector('[name="notes"]'),
    };
    const button = root.querySelector('[data-draft-save]');
    const status = root.querySelector('[data-draft-status]');
    const error = root.querySelector('[data-draft-error]');
    const message = root.querySelector('[data-draft-error-message]');
    let saved = JSON.parse(root.querySelector('[data-draft-initial]').textContent);
    let exists = root.dataset.saved === 'true';
    let canSave = true;
    let saving = false;
    let watching = false;
    // Textareas normalize CRLF to LF. Retain the original bytes when a field is unchanged.
    const normalized = value => value.replace(/\r\n?/g, '\n');
    Object.entries(fields).forEach(([key, input]) => { input.value = saved[key]; });
    fields.body_markdown.setAttribute('aria-describedby', `${fields.body_markdown.getAttribute('aria-describedby') || ''} draft-markdown-error`.trim());
    const current = () => Object.fromEntries(Object.entries(fields).map(([key, input]) =>
        [key, input.value === normalized(saved[key]) ? saved[key] : input.value]));
    const dirty = () => Object.entries(current()).some(([key, value]) => value !== saved[key]);
    function warnBeforeLeaving(event) {
        if (!dirty() && !saving) return;
        event.preventDefault();
        event.returnValue = '';
    }
    function update() {
        const changed = dirty();
        status.textContent = saving ? 'Guardando borrador…' : changed ? 'Cambios sin guardar.'
            : exists ? 'Sin cambios pendientes.' : 'Documento nuevo, aún sin guardar.';
        button.disabled = saving || !canSave || (exists && !changed);
        button.textContent = saving ? 'Guardando…' : 'Guardar borrador';
        const needsWarning = changed || saving;
        if (needsWarning !== watching) {
            window[needsWarning ? 'addEventListener' : 'removeEventListener']('beforeunload', warnBeforeLeaving);
            watching = needsWarning;
        }
    }
    function clearErrors() {
        error.hidden = true;
        Object.entries(fields).forEach(([key, input]) => {
            input.removeAttribute('aria-invalid');
            const output = root.querySelector(`[data-draft-field-error="${key}"]`);
            output.hidden = true;
            output.className = 'field-hint';
        });
    }
    button.addEventListener('click', async () => {
        if (saving || !canSave) return;
        const snapshot = current();
        clearErrors();
        saving = true;
        update();
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 30000);
        try {
            const response = await fetch(root.dataset.saveUrl, {
                method: exists ? 'PUT' : 'POST', credentials: 'same-origin', redirect: 'error', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(snapshot),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                let first;
                if (response.status === 422) Object.entries(data.errors || {}).forEach(([key, messages]) => {
                    if (!fields[key]) return;
                    const output = root.querySelector(`[data-draft-field-error="${key}"]`);
                    output.textContent = messages.join(' ');
                    output.className = 'field-error';
                    output.hidden = false;
                    fields[key].setAttribute('aria-invalid', 'true');
                    first ||= fields[key];
                });
                first?.focus();
                const failures = {
                    401: 'Tu sesión terminó. Copia tu contenido antes de iniciar sesión de nuevo.',
                    403: 'Tu cuenta no tiene permiso para guardar este borrador.',
                    419: 'Tu sesión expiró. Copia tu contenido antes de recargar.',
                    422: 'Revisa los campos indicados. El contenido se conserva.',
                    413: 'El borrador supera el tamaño admitido. Tu contenido se conserva.',
                };
                throw new Error(failures[response.status] || 'No fue posible guardar. Tu contenido se conserva; inténtalo de nuevo.');
            }
            if (!data.uuid || !data.update_url || !data.edit_url) throw new Error('El servidor no confirmó el guardado. Tu contenido se conserva.');
            saved = snapshot;
            exists = true;
            canSave = data.can_update;
            root.dataset.saveUrl = data.update_url;
            window.history.replaceState(null, '', data.edit_url);
            window.AdminNotify?.show({ type: 'success', message: 'Borrador guardado.' });
        } catch (failure) {
            message.textContent = failure.name === 'AbortError'
                ? 'No se recibió confirmación a tiempo. Tu contenido se conserva; comprueba el listado antes de volver a guardar.'
                : failure instanceof TypeError ? 'No se pudo conectar con el servidor. Tu contenido se conserva.' : failure.message;
            error.hidden = false;
        } finally {
            clearTimeout(timeout);
            saving = false;
            update();
        }
    });
    Object.values(fields).forEach(input => input.addEventListener('input', update));
    update();
})();
