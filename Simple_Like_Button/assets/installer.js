'use strict';

const passwordToggle = document.querySelector('[data-installer-password-toggle]');
if (passwordToggle) {
    const passwordFields = [...document.querySelectorAll('[data-installer-password]')];
    passwordToggle.addEventListener('change', () => {
        passwordFields.forEach((field) => {
            field.type = passwordToggle.checked ? 'text' : 'password';
        });
    });
}
