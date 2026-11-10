document.querySelectorAll('[data-markdown-editor]').forEach(editor => {
    const input = editor.querySelector('textarea');
    const toolbar = editor.querySelector('.markdown-toolbar');
    const toggle = editor.querySelector('[data-markdown-preview]');
    const output = editor.querySelector('[data-markdown-output]');
    const status = editor.querySelector('[data-markdown-status]');
    const preview = output?.closest('.markdown-preview');
    const formatButtons = [...toolbar.querySelectorAll('[data-markdown-action]')];
    toolbar.hidden = false;

    const inline = {
        bold: ['**', '**'],
        italic: ['*', '*'],
        link: ['[', '](https://example.com)'],
    };
    const prefixes = {
        heading: () => '## ',
        list: () => '- ',
        numbered: index => (index + 1) + '. ',
        quote: () => '> ',
    };

    for (const button of formatButtons) {
        button.addEventListener('click', () => {
            let start = input.selectionStart;
            const end = input.selectionEnd;
            const action = button.dataset.markdownAction;
            let selection = input.value.slice(start, end);
            let replacement;
            let selectionStart;
            let selectionEnd;
            if (Object.hasOwn(prefixes, action)) {
                start = start === 0 ? 0 : input.value.lastIndexOf('\n', start - 1) + 1;
                selection = input.value.slice(start, end) || 'texto';
                replacement = selection.split('\n').map((line, index) =>
                    line === '' ? '' : prefixes[action](index) + line
                ).join('\n');
                selectionStart = 0;
                selectionEnd = replacement.length;
            } else {
                selection ||= 'texto';
                const longestTickRun = Math.max(0, ...(selection.match(/`+/g) || []).map(run => run.length));
                const fence = '`'.repeat(longestTickRun + 1);
                const [before, after] = action === 'code'
                    ? (longestTickRun > 0 ? [fence + ' ', ' ' + fence] : [fence, fence])
                    : inline[action];
                replacement = before + selection + after;
                selectionStart = before.length;
                selectionEnd = before.length + selection.length;
            }
            if (input.maxLength >= 0 && input.value.length - (end - start) + replacement.length > input.maxLength) {
                input.focus();
                return;
            }
            input.setRangeText(replacement, start, end, 'end');
            input.focus();
            input.setSelectionRange(start + selectionStart, start + selectionEnd);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    if (!toggle) return;

    let timer;
    let pending;

    function cancelUpdate() {
        clearTimeout(timer);
        pending?.abort();
    }

    async function updatePreview() {
        const request = new AbortController();
        pending = request;
        try {
            const response = await fetch(editor.dataset.previewUrl, {
                method: 'POST',
                redirect: 'error',
                signal: request.signal,
                headers: {
                    'Content-Type': 'text/plain; charset=UTF-8',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: input.value,
            });
            if (!response.ok) throw new Error();
            const html = await response.text();
            if (request.signal.aborted) return;
            output.innerHTML = html;
            status.hidden = true;
        } catch {
            if (request.signal.aborted) return;
            status.hidden = false;
            status.textContent = 'No fue posible actualizar la vista previa.';
        } finally {
            if (pending === request) pending = undefined;
        }
    }

    function setPreviewVisible(visible) {
        cancelUpdate();
        preview.hidden = !visible;
        toggle.setAttribute('aria-pressed', String(visible));
        const label = visible ? 'Ocultar vista previa' : 'Mostrar vista previa';
        toggle.setAttribute('aria-label', label);
        toggle.title = label;
        toggle.querySelector('[data-eye-slash]').style.display = visible ? 'none' : '';
        if (visible) updatePreview();
    }

    input.addEventListener('input', () => {
        if (preview.hidden) return;
        cancelUpdate();
        timer = setTimeout(updatePreview, 400);
    });
    toggle.addEventListener('click', () => setPreviewVisible(preview.hidden));
    setPreviewVisible(toggle.dataset.previewVisible === 'true');
});
