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
    const notes = root.querySelector('[data-draft-notes]');
    const notesError = root.querySelector('[data-draft-notes-error]');
    const back = root.querySelector('[data-draft-back]');
    const leaveDialog = root.querySelector('#draft-leave-dialog');
    const leaveTrigger = root.querySelector('[data-draft-leave-trigger]');
    let saved = JSON.parse(root.querySelector('[data-draft-initial]').textContent);
    let exists = root.dataset.saved === 'true';
    let canSave = true;
    let saving = false;
    let watching = false;
    let saveFailed = false;
    let leavingRequested = false;
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
        const state = saving ? 'saving' : saveFailed ? 'error' : changed ? 'dirty' : exists ? 'saved' : 'new';
        status.dataset.state = state;
        status.textContent = { saving: 'Guardando', error: 'Error al guardar', dirty: 'Cambios sin guardar', saved: 'Guardado', new: 'Sin guardar' }[state];
        button.disabled = saving || !canSave || (exists && !changed);
        button.textContent = saving ? 'Guardando…' : 'Guardar borrador';
        button.setAttribute('aria-busy', String(saving));
        const needsWarning = changed || saving;
        if (needsWarning !== watching) {
            window[needsWarning ? 'addEventListener' : 'removeEventListener']('beforeunload', warnBeforeLeaving);
            watching = needsWarning;
        }
    }
    function clearErrors() {
        error.hidden = true;
        notesError.hidden = true;
        Object.entries(fields).forEach(([key, input]) => {
            input.removeAttribute('aria-invalid');
            const output = root.querySelector(`[data-draft-field-error="${key}"]`);
            output.hidden = true;
            output.className = 'field-hint';
        });
    }
    // Reuse the panel's Confirm Dialog for the explicit back action. Browser
    // beforeunload still protects reload/close and other navigation.
    back.addEventListener('click', event => {
        if (event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        if ((!dirty() && !saving) || typeof leaveDialog.showModal !== 'function') return;
        event.preventDefault();
        leavingRequested = true;
        leaveTrigger.click();
        leaveDialog._focusReturn = back;
    });
    leaveDialog.querySelector('[data-confirm-accept]').addEventListener('click', () => {
        if (!leavingRequested) return;
        leavingRequested = false;
        window.removeEventListener('beforeunload', warnBeforeLeaving);
        watching = false;
        window.location.assign(back.href);
    }, { capture: true });
    leaveDialog.addEventListener('close', () => { leavingRequested = false; });
    window.addEventListener('pageshow', event => { if (event.persisted) update(); });
    button.addEventListener('click', async () => {
        if (saving || !canSave) return;
        const snapshot = current();
        clearErrors();
        saveFailed = false;
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
                    if (key === 'notes') {
                        notes.open = true;
                        notesError.textContent = messages.join(' ');
                        notesError.hidden = false;
                    }
                    if (key === 'body_markdown') root.querySelector('[data-pdf-panel="editor"]')?.click();
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
            root.dataset.saved = 'true';
            canSave = data.can_update;
            root.dataset.saveUrl = data.update_url;
            window.history.replaceState(null, '', data.edit_url);
            const heading = document.querySelector('.page-heading h1');
            if (heading) heading.textContent = 'Editar documento';
            document.title = document.title.replace(/^Nuevo documento/, 'Editar documento');
            root.querySelector('[data-draft-document-title]').textContent = snapshot.title;
            root.querySelector('[data-draft-context]').textContent = 'Puedes modificar el borrador, guardar tus cambios y actualizar la vista previa PDF.';
            window.AdminNotify?.show({ type: 'success', message: 'Borrador guardado.' });
        } catch (failure) {
            saveFailed = true;
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
