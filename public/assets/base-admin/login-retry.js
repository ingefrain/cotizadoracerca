const loginRetry = document.querySelector('[data-login-retry]');

if (loginRetry) {
    const form = document.querySelector('#password').form;
    const password = form.elements.password;
    const submit = form.querySelector('[type="submit"]');
    const eye = form.querySelector('[data-password-toggle]');
    const seconds = loginRetry.querySelector('[data-login-seconds]');
    const ready = document.querySelector('[data-login-ready]');
    const deadline = Date.now() + Number(loginRetry.dataset.loginRetry) * 1000;

    function updateLoginRetry() {
        const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        if (remaining > 0) {
            seconds.textContent = String(remaining);
            window.setTimeout(updateLoginRetry, 1000);
        } else {
            loginRetry.hidden = true;
            password.disabled = false;
            submit.disabled = false;
            eye.disabled = false;
            ready.textContent = 'Ya puedes volver a intentarlo.';
        }
    }

    updateLoginRetry();
}
