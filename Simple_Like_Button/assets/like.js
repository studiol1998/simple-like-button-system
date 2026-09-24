(() => {
    'use strict';

    const currentScript = document.currentScript;
    if (!currentScript || !currentScript.src) {
        return;
    }

    const likeEndpoint = new URL('../api/like.php', currentScript.src).toString();
    const tokenStorageKey = 'pollyanna.client-token.v1';

    function getClientToken() {
        let token = localStorage.getItem(tokenStorageKey);
        if (token && /^[a-f0-9]{64}$/i.test(token)) {
            return token;
        }

        const bytes = new Uint8Array(32);
        crypto.getRandomValues(bytes);
        token = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        localStorage.setItem(tokenStorageKey, token);
        return token;
    }

    function initialize(area) {
        const identifier = (area.dataset.identifier || '').trim();
        const button = document.createElement('button');
        const status = document.createElement('span');
        let statusTimer = null;

        button.type = 'button';
        button.className = 'pollyanna-like-button';
        button.textContent = '♡';
        button.setAttribute('aria-label', 'いいねを送信');

        status.className = 'pollyanna-like-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');

        area.replaceChildren(button, status);

        if (!identifier) {
            button.disabled = true;
            status.textContent = '識別子が設定されていません。';
            return;
        }

        button.addEventListener('click', async () => {
            if (button.disabled) {
                return;
            }

            if (statusTimer !== null) {
                window.clearTimeout(statusTimer);
                statusTimer = null;
            }
            button.disabled = true;
            status.textContent = '';

            try {
                const response = await fetch(likeEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        identifier,
                        client_token: getClientToken(),
                    }),
                });
                const result = await response.json();

                if (!response.ok || result.success !== true) {
                    throw new Error('Like was not accepted.');
                }

                button.textContent = '♥';
                button.classList.add('is-liked');
                button.disabled = false;
                status.textContent = 'Good!';
                statusTimer = window.setTimeout(() => {
                    status.textContent = '';
                    statusTimer = null;
                }, 500);
            } catch (error) {
                button.disabled = false;
                status.textContent = '送信できませんでした。通信を確認して、もう一度お試しください。';
            }
        });
    }

    function initializeAll() {
        document.querySelectorAll('[data-pollyanna-like]').forEach(initialize);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAll, { once: true });
    } else {
        initializeAll();
    }
})();
