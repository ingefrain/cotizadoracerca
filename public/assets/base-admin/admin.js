(() => {
    document.querySelectorAll('[data-password-toggle]').forEach(button => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        const slash = button.querySelector('[data-eye-slash]');
        if (!input || !slash) return;
        button.hidden = false;
        const setVisible = visible => {
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(visible));
            button.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
            slash.hidden = visible;
        };
        button.addEventListener('click', () => {
            const visible = input.type === 'password';
            setVisible(visible);
            input.focus({ preventScroll: true });
        });
        input.form?.addEventListener('submit', () => setVisible(false));
        window.addEventListener('pagehide', () => setVisible(false));
    });
})();

(() => {
    const shell = document.querySelector('.admin-shell');
    const toggle = document.querySelector('[data-menu-toggle]');
    const close = document.querySelector('[data-drawer-close]');

    if (!shell || !toggle || !close) return;

    const setOpen = (open) => {
        shell.classList.toggle('drawer-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        if (open) close.focus();
        else toggle.focus();
    };

    toggle.addEventListener('click', () => setOpen(!shell.classList.contains('drawer-open')));
    close.addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && shell.classList.contains('drawer-open')) setOpen(false);
    });

    document.documentElement.classList.add('js-enabled');

    const openModal = (dialog, opener = null) => {
        if (!dialog || dialog.open) return;
        dialog._focusReturn = opener || document.activeElement;
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
        dialog.querySelector('[data-modal-initial-focus]')?.focus({ preventScroll: true });
    };
    document.querySelectorAll('[data-modal-open]').forEach(button => {
        button.addEventListener('click', () => openModal(document.getElementById(button.dataset.modalOpen), button));
    });
    document.querySelectorAll('[data-profile-modal]').forEach(dialog => {
        dialog.querySelectorAll('[data-modal-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('click', event => {
            if (event.target === dialog) dialog.close();
        });
        dialog.addEventListener('close', () => {
            if (dialog._focusReturn?.isConnected) dialog._focusReturn.focus({ preventScroll: true });
            dialog._focusReturn = null;
        });
        if (dialog.hasAttribute('data-open-on-load')) openModal(dialog, null);
    });

    document.querySelectorAll('[data-confirm-dialog]').forEach(dialog => {
        // Without native modal support, leave the original form usable.
        if (typeof dialog.showModal !== 'function') return;
        const form = dialog.closest('form');
        const accept = dialog.querySelector('[data-confirm-accept]');
        const phrase = dialog.querySelector('[data-confirm-phrase]');
        let submitter;
        let confirmed = false;

        phrase?.addEventListener('input', () => {
            accept.disabled = phrase.value !== phrase.dataset.confirmPhrase;
        });
        dialog.addEventListener('close', () => {
            if (dialog.open) return;
            submitter = undefined;
            if (phrase) {
                phrase.value = '';
                accept.disabled = true;
            }
        });
        form?.addEventListener('submit', event => {
            if (confirmed || event.defaultPrevented) return;
            event.preventDefault();
            if (dialog.open) return;
            submitter = event.submitter;
            openModal(dialog, submitter);
        });
        dialog.addEventListener('keydown', event => {
            if (event.key !== 'Tab') return;
            const controls = [...dialog.querySelectorAll('button:not(:disabled), input:not(:disabled)')];
            const edge = event.shiftKey ? controls[0] : controls.at(-1);
            if (document.activeElement !== edge) return;
            event.preventDefault();
            (event.shiftKey ? controls.at(-1) : controls[0]).focus();
        });
        accept.addEventListener('click', () => {
            if (!dialog.open || (phrase && phrase.value !== phrase.dataset.confirmPhrase)) return;
            dialog.close();
            if (!form) return;
            confirmed = true;
            try {
                form.requestSubmit(submitter ?? undefined);
            } finally {
                confirmed = false;
            }
        });
    });

    document.querySelectorAll('[data-role-selector]').forEach(selector => {
        const native = selector.querySelector('select[multiple]');
        const enhanced = selector.querySelector('[data-role-enhanced]');
        const chips = selector.querySelector('[data-role-chips]');
        const search = selector.querySelector('[data-role-search]');
        const options = selector.querySelector('[data-role-options]');
        const toggle = selector.querySelector('[data-role-toggle]');
        const dropdown = selector.querySelector('[data-role-dropdown]');
        if (!native || !enhanced || !chips || !search || !options || !toggle || !dropdown || !native.options.length) return;

        const render = () => {
            chips.replaceChildren();
            [...native.selectedOptions].forEach(option => {
                const chip = document.createElement('span');
                chip.className = 'role-chip';
                const label = document.createElement('span');
                label.textContent = option.textContent;
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.textContent = '×';
                remove.setAttribute('aria-label', `Quitar rol ${option.textContent}`);
                remove.addEventListener('click', () => {
                    option.selected = false;
                    render();
                });
                chip.append(label, remove);
                chips.append(chip);
            });

            options.replaceChildren();
            const query = search.value.trim().toLocaleLowerCase();
            [...native.options].forEach(option => {
                if (!option.textContent.toLocaleLowerCase().includes(query)) return;
                const label = document.createElement('label');
                label.className = 'role-option';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.checked = option.selected;
                checkbox.value = option.value;
                checkbox.addEventListener('change', () => {
                    option.selected = checkbox.checked;
                    render();
                    search.focus({ preventScroll: true });
                });
                const text = document.createElement('span');
                text.textContent = option.textContent;
                label.append(checkbox, text);
                options.append(label);
            });
            if (!options.childElementCount) {
                const empty = document.createElement('p');
                empty.className = 'field-hint';
                empty.textContent = 'No hay roles que coincidan.';
                options.append(empty);
            }
        };

        native.addEventListener('change', render);
        search.addEventListener('input', render);
        toggle.addEventListener('click', () => {
            const expanded = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', String(expanded));
            dropdown.hidden = !expanded;
            if (expanded) search.focus({ preventScroll: true });
        });
        selector.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !dropdown.hidden) {
                dropdown.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus({ preventScroll: true });
            }
        });
        render();
        native.hidden = true;
        selector.querySelector('.role-native-label')?.classList.add('sr-only');
        enhanced.hidden = false;
    });

    document.querySelectorAll('[data-file-dropzone]').forEach(field => {
        const input = field.querySelector('input[type="file"]');
        const zone = field.querySelector('[data-dropzone]');
        const selection = field.querySelector('[data-selection]');
        const preview = field.querySelector('[data-preview]');
        const status = field.querySelector('[data-file-status]');
        const error = field.querySelector('[data-upload-error]');
        const clear = field.querySelector('[data-clear-file]');
        if (!input || !zone || !selection || !preview || !status || !error || !clear) return;

        const allowed = (field.dataset.allowedTypes || '').split(',').filter(Boolean);
        const maxBytes = Number(field.dataset.maxBytes || 0);
        let previewUrl = null;
        const releasePreview = () => {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = null;
        };
        const showError = message => {
            error.textContent = message;
            error.hidden = false;
            input.setAttribute('aria-invalid', 'true');
        };
        const clearSelection = () => {
            releasePreview();
            input.value = '';
            input.removeAttribute('aria-invalid');
            preview.removeAttribute('src');
            preview.hidden = true;
            selection.hidden = true;
            error.hidden = true;
            status.textContent = 'Sin archivo seleccionado.';
        };
        const selectFile = file => {
            if (!file) return;
            if (file.size > maxBytes) {
                clearSelection();
                showError(`El archivo supera el máximo de ${field.dataset.sizeLabel || 'tamaño permitido'}.`);
                return;
            }
            if (allowed.length && file.type && !allowed.includes(file.type)) {
                clearSelection();
                showError('El tipo del archivo no está permitido. El servidor comprobará su contenido.');
                return;
            }
            releasePreview();
            error.hidden = true;
            input.removeAttribute('aria-invalid');
            selection.hidden = false;
            const size = file.size < 1048576
                ? `${Math.max(1, Math.round(file.size / 1024))} KB`
                : `${(file.size / 1048576).toFixed(2).replace(/0+$/, '').replace(/\.$/, '')} MB`;
            status.textContent = `${file.name} · ${size}`;
            if (file.type.startsWith('image/')) {
                previewUrl = URL.createObjectURL(file);
                preview.src = previewUrl;
                preview.alt = `Vista previa de ${file.name}`;
                preview.hidden = false;
                preview.onerror = () => {
                    releasePreview();
                    preview.hidden = true;
                    showError('No se pudo generar la vista previa. El servidor validará el archivo al guardarlo.');
                };
            } else {
                preview.removeAttribute('src');
                preview.hidden = true;
            }
        };

        input.addEventListener('change', () => selectFile(input.files?.[0]));
        clear.addEventListener('click', clearSelection);
        ['dragenter', 'dragover'].forEach(eventName => zone.addEventListener(eventName, event => {
            event.preventDefault();
            zone.classList.add('is-dragging');
        }));
        ['dragleave', 'drop'].forEach(eventName => zone.addEventListener(eventName, () => zone.classList.remove('is-dragging')));
        zone.addEventListener('drop', event => {
            event.preventDefault();
            if (!event.dataTransfer?.files?.length) return;
            if (event.dataTransfer.files.length !== 1) {
                clearSelection();
                showError('Selecciona un solo archivo.');
                return;
            }
            try {
                input.files = event.dataTransfer.files;
                selectFile(input.files[0]);
            } catch {
                showError('Usa el selector de archivos de tu navegador.');
            }
        });
        window.addEventListener('pagehide', releasePreview, { once: true });

        field.closest('form')?.addEventListener('submit', () => {
            const button = field.closest('form').querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.dataset.originalText = button.textContent;
                button.textContent = 'Guardando…';
            }
        }, { once: true });
    });

    document.querySelectorAll('[data-password-policy]').forEach(form => {
        const password = form.querySelector('[data-password-value]');
        const confirmation = form.querySelector('[data-password-confirm]');
        if (!password || !confirmation) return;
        const minimum = Number(form.dataset.minimum || 8);
        const maximum = Number(form.dataset.maximum || 72);
        const requireMixedCase = form.dataset.requireMixedCase !== 'false';
        const requireNumber = form.dataset.requireNumber !== 'false';
        const limitWarning = form.querySelector('[data-password-limit]');
        const checklist = form.querySelector('[data-password-checklist]');
        const length = value => Array.from(value).length;
        const checks = {
            minimum: value => length(value) >= minimum,
            uppercase: value => !requireMixedCase || /\p{Lu}/u.test(value),
            lowercase: value => !requireMixedCase || /\p{Ll}/u.test(value),
            number: value => !requireNumber || /\p{N}/u.test(value),
            match: value => value.length > 0 && confirmation.value.length > 0 && value === confirmation.value,
        };
        const update = () => {
            const value = password.value;
            if (checklist && form.dataset.passwordOptional === 'true') {
                checklist.hidden = value.length === 0 && confirmation.value.length === 0;
            }
            Object.entries(checks).forEach(([key, check]) => {
                const item = form.querySelector(`[data-rule="${key}"]`);
                if (!item) return;
                const valid = check(value);
                item.classList.toggle('is-valid', valid);
                item.classList.toggle('is-invalid', !valid);
                item.querySelector('[aria-hidden="true"]').textContent = valid ? '✓' : '✗';
                item.querySelector('[data-rule-status]').textContent = valid ? 'Cumplido' : 'Falta';
                item.setAttribute('aria-label', `${item.children[1].textContent} ${valid ? 'Cumplido' : 'Falta'}`);
            });
            const exceedsMaximum = length(value) > maximum;
            if (limitWarning) limitWarning.hidden = !exceedsMaximum;
            const match = form.querySelector('[data-password-match]');
            if (match) {
                const compared = confirmation.value.length > 0;
                const equal = compared && value === confirmation.value;
                match.textContent = equal ? 'Las contraseñas coinciden.' : compared ? 'Las contraseñas no coinciden.' : 'Escribe ambas contraseñas para compararlas.';
                match.classList.toggle('is-valid', equal);
                match.classList.toggle('is-invalid', compared && !equal);
            }
            password.setAttribute('aria-invalid', String(value.length > 0 && (exceedsMaximum || !checks.minimum(value) || !checks.uppercase(value) || !checks.lowercase(value) || !checks.number(value))));
            confirmation.setAttribute('aria-invalid', String(confirmation.value.length > 0 && !checks.match(value)));
        };
        form.addEventListener('input', update);
        form.addEventListener('change', update);
        form.addEventListener('focusout', update);
        update();
    });
})();
(() => {
    const stack = document.querySelector('[data-notify-stack]');
    if (!stack) return;

    const templates = new Map([...document.querySelectorAll('[data-notify-template]')]
        .map(template => [template.dataset.notifyTemplate, template]));
    const durations = { success: 3500, info: 4000, warning: 5500, error: 7000 };
    const polite = document.querySelector('[data-notify-polite]');
    const urgent = document.querySelector('[data-notify-urgent]');
    const visible = new Map();

    const show = ({ type = 'info', message } = {}) => {
        if (typeof message !== 'string' || !message.trim()) return;
        if (!templates.has(type)) type = 'info';
        const key = JSON.stringify([type, message]);
        if (visible.has(key)) return;
        if (visible.size >= 3) visible.values().next().value(true);

        const item = templates.get(type).content.firstElementChild.cloneNode(true);
        item.querySelector('[data-notify-message]').textContent = message;
        const origin = document.activeElement;
        const announcer = type === 'error' ? urgent : polite;
        let timer;
        let exitTimer;
        let remaining = durations[type];
        let started;
        const remove = () => {
            clearTimeout(exitTimer);
            item.remove();
            visible.delete(key);
        };
        const dismiss = immediate => {
            clearTimeout(timer);
            if (item.contains(document.activeElement)) {
                (origin && origin !== document.body && origin.isConnected ? origin : stack.closest('main'))
                    ?.focus({ preventScroll: true });
            }
            if (immediate || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                remove();
            } else if (!item.classList.contains('is-leaving')) {
                item.classList.add('is-leaving');
                exitTimer = setTimeout(remove, 140);
            }
        };
        const resume = () => {
            if (timer !== undefined || item.matches(':hover, :focus-within, .is-leaving')) return;
            started = performance.now();
            timer = setTimeout(() => dismiss(false), remaining);
        };
        const pause = () => {
            if (timer === undefined) return;
            clearTimeout(timer);
            timer = undefined;
            remaining = Math.max(0, remaining - (performance.now() - started));
        };

        item.addEventListener('pointerenter', pause);
        item.addEventListener('pointerleave', () => requestAnimationFrame(resume));
        item.addEventListener('focusin', pause);
        item.addEventListener('focusout', () => requestAnimationFrame(resume));
        item.querySelector('[data-notify-close]').addEventListener('click', () => dismiss(false));
        visible.set(key, dismiss);
        stack.append(item);
        announcer.replaceChildren(message);
        resume();
    };

    window.AdminNotify = { show };
    document.querySelectorAll('[data-notify-trigger]').forEach(button => {
        button.addEventListener('click', () => show({
            type: button.dataset.notifyTrigger,
            message: button.dataset.notifyText,
        }));
    });
    const flash = document.querySelector('[data-notify-flash]');
    if (flash) {
        show({ type: flash.dataset.notifyFlash, message: flash.querySelector('[data-notify-message]').textContent });
        flash.remove();
    }
})();

(() => {
    document.querySelectorAll('[data-status-toggle]').forEach(form => {
        const button = form.querySelector('[data-toggle-switch]');
        const value = form.elements.namedItem('is_active');
        const label = form.querySelector('[data-toggle-label]');
        const note = form.querySelector('[data-toggle-note]');
        const demo = form.dataset.toggleDemo;
        const token = form.elements.namedItem('_token');
        if (!window.AdminNotify || (!demo && (!token || typeof fetch !== 'function'))) return;

        const save = async event => {
            event.preventDefault();
            if (button.disabled) return;
            const hadFocus = document.activeElement === button;
            const next = value.value === '1';
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');

            try {
                let result;
                if (demo) {
                    await new Promise(resolve => setTimeout(resolve, 650));
                    result = { success: demo === 'success', is_active: next };
                } else {
                    const response = await fetch(form.action, {
                        method: 'PATCH',
                        credentials: 'same-origin',
                        redirect: 'error',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token.value,
                        },
                        body: JSON.stringify({ is_active: next }),
                    });
                    if (!response.ok) throw new Error('No se pudo guardar el estado');
                    result = await response.json();
                }
                if (result.success !== true || typeof result.is_active !== 'boolean') {
                    throw new Error('Respuesta de estado inválida');
                }

                button.setAttribute('aria-checked', String(result.is_active));
                label.textContent = result.is_active ? form.dataset.activeLabel : form.dataset.inactiveLabel;
                value.value = result.is_active ? '0' : '1';
                if (note) {
                    note.hidden = !result.is_active;
                    if (result.is_active) button.setAttribute('aria-describedby', note.id);
                    else button.removeAttribute('aria-describedby');
                }
                window.AdminNotify.show({ type: 'success', message: 'Estado actualizado.' });
            } catch {
                window.AdminNotify.show({ type: 'error', message: 'No fue posible cambiar el estado.' });
            } finally {
                button.removeAttribute('aria-busy');
                button.disabled = false;
                if (hadFocus && document.activeElement === document.body) button.focus({ preventScroll: true });
            }
        };

        if (demo) button.addEventListener('click', save);
        else form.addEventListener('submit', save);
    });
})();
