'use strict';

document.querySelectorAll('[data-copy-code]').forEach((button) => {
    button.addEventListener('click', async () => {
        const code = document.getElementById(button.dataset.copyCode);
        const status = document.getElementById(button.getAttribute('aria-describedby'));
        if (!code || !status) return;
        button.disabled = true;
        status.textContent = '';
        try {
            await navigator.clipboard.writeText(code.textContent);
            status.textContent = status.dataset.copySuccess;
        } catch {
            status.textContent = status.dataset.copyFailure;
        } finally {
            button.disabled = false;
        }
    });
});
