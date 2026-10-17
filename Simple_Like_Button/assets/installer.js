'use strict';

document.querySelectorAll('[data-new-admin-password]').forEach((field) => {
    const validate = () => {
        const value = field.value;
        const valid = Array.from(value).length >= 12 && new TextEncoder().encode(value).length <= 72 && !value.includes('\0');
        field.setCustomValidity(value === '' || valid ? '' : (field.closest('form')?.dataset.passwordRule || 'Invalid password.'));
    };
    field.addEventListener('input', validate);
    validate();
});


const passwordToggle = document.querySelector('[data-installer-password-toggle]');
if (passwordToggle) {
    const passwordFields = [...document.querySelectorAll('[data-installer-password]')];
    passwordToggle.addEventListener('change', () => {
        passwordFields.forEach((field) => {
            field.type = passwordToggle.checked ? 'text' : 'password';
        });
    });
}

document.querySelectorAll('[data-origin-host-source]').forEach((originField) => {
    const toggle = originField.closest('form')?.querySelector('[data-subdomain-toggle]');
    if (!toggle) return;
    const updateSubdomainToggle = () => {
        let host = '';
        try { host = new URL(originField.value).hostname.replace(/^\[|\]$/g, '').toLowerCase(); } catch (_) {}
        const localOrIp = host === 'localhost' || /^\d{1,3}(?:\.\d{1,3}){3}$/.test(host) || host.includes(':');
        if (localOrIp) toggle.checked = false;
        toggle.disabled = localOrIp;
    };
    originField.addEventListener('input', updateSubdomainToggle);
    updateSubdomainToggle();
});