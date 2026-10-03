(() => {
    'use strict';

    const currentScript = document.currentScript;
    if (!currentScript || !currentScript.src) {
        return;
    }

    const likeEndpoint = new URL('../api/like.php', currentScript.src).toString();
    const stateEndpoint = new URL('../api/state.php', currentScript.src).toString();
    const tokenStorageKey = 'pollyanna.client-token.v1';
    const likedIdentifiersStorageKey = 'pollyanna.liked-identifiers.v1';

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

    function getLocallyLikedIdentifiers() {
        try {
            const stored = JSON.parse(localStorage.getItem(likedIdentifiersStorageKey) || '[]');
            return Array.isArray(stored)
                ? stored.filter(identifier => typeof identifier === 'string')
                : [];
        } catch (error) {
            return [];
        }
    }

    function rememberLikedIdentifier(identifier) {
        try {
            const likedIdentifiers = new Set(getLocallyLikedIdentifiers());
            likedIdentifiers.add(identifier);
            localStorage.setItem(likedIdentifiersStorageKey, JSON.stringify([...likedIdentifiers]));
        } catch (error) {
            // A successful Like remains successful even when browser storage is unavailable.
        }
    }

    function showLikedState(button) {
        button.textContent = '♥';
        button.classList.add('is-liked');
    }

    function initialize(area) {
        const identifier = (area.dataset.identifier || '').trim();
        const canonicalUrl = (area.dataset.url || '').trim();
        area.classList.add('pollyanna-like-area');
        const button = document.createElement('button');
        const count = document.createElement('span');
        const status = document.createElement('span');
        let statusTimer = null;
        let isMaintenance = false;
        let countRefreshInterval = 0;
        let countRefreshTimer = null;
        let countRefreshInFlight = false;
        let widgetText = {
            button_label: 'いいねを送信',
            count_label: 'いいね数',
            identifier_missing: '識別子が設定されていません。',
            state_unavailable: '押下状態を確認できませんでした。',
            maintenance: 'メンテナンス中です。',
            send_error: '送信できませんでした。通信を確認して、もう一度お試しください。',
            rate_limit: 'この作品へのいいね上限に達しました。時間をおいてお試しください。',
            count_color: '#767676',
        };

        button.type = 'button';
        button.className = 'pollyanna-like-button';
        button.textContent = '♡';
        button.setAttribute('aria-label', widgetText.button_label);

        count.className = 'pollyanna-like-count';
        count.setAttribute('aria-label', widgetText.count_label);
        count.hidden = true;

        status.className = 'pollyanna-like-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');

        area.replaceChildren(button, count, status);

        if (!identifier) {
            button.disabled = true;
            status.textContent = widgetText.identifier_missing;
            return;
        }

        if (getLocallyLikedIdentifiers().includes(identifier)) {
            showLikedState(button);
        }

        function requestPayload(includeUrl = false) {
            const payload = {identifier, client_token: getClientToken()};
            if (includeUrl && canonicalUrl) payload.canonical_url = canonicalUrl;
            return payload;
        }

        function updateCount(result) {
            if (Number.isSafeInteger(result.count) && result.count >= 0) {
                count.textContent = String(result.count);
                count.setAttribute('aria-label', `${widgetText.count_label} ${result.count}`);
                count.hidden = false;
            }
        }

        function updateWidgetText(translations) {
            if (!translations || typeof translations !== 'object') {
                return;
            }
            for (const key of Object.keys(widgetText)) {
                if (typeof translations[key] === 'string') {
                    widgetText[key] = translations[key];
                }
            }
            if (/^#[0-9a-f]{6}$/i.test(widgetText.count_color)) {
                area.style.setProperty('--pollyanna-like-count-color', widgetText.count_color);
            }
            button.setAttribute('aria-label', widgetText.button_label);
            count.setAttribute('aria-label', widgetText.count_label);
        }

        function scheduleCountRefresh() {
            if (countRefreshTimer !== null) {
                window.clearTimeout(countRefreshTimer);
                countRefreshTimer = null;
            }
            if (countRefreshInterval === 0 || document.hidden || isMaintenance) {
                return;
            }
            countRefreshTimer = window.setTimeout(refreshCount, countRefreshInterval * 1000);
        }

        async function refreshCount() {
            countRefreshTimer = null;
            if (countRefreshInterval === 0 || document.hidden || isMaintenance || countRefreshInFlight) {
                scheduleCountRefresh();
                return;
            }

            countRefreshInFlight = true;
            try {
                const response = await fetch(stateEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestPayload()),
                });
                const result = await response.json();
                updateWidgetText(result.widget_text);
                if (result.maintenance === true) {
                    isMaintenance = true;
                    button.disabled = true;
                    status.textContent = widgetText.maintenance;
                    return;
                }
                if (response.ok && result.success === true && Number.isSafeInteger(result.count) && result.count >= 0) {
                    updateCount(result);
                }
            } catch (error) {
                // Keep the last known count and retry on the next interval.
            } finally {
                countRefreshInFlight = false;
                scheduleCountRefresh();
            }
        }

        document.addEventListener('visibilitychange', scheduleCountRefresh);

        async function restoreState() {
            button.disabled = true;
            try {
                const response = await fetch(stateEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestPayload(true)),
                });
                const result = await response.json();
                updateWidgetText(result.widget_text);
                if (result.maintenance === true) {
                    isMaintenance = true;
                    status.textContent = widgetText.maintenance;
                    return;
                }
                if (!response.ok || result.success !== true || typeof result.liked !== 'boolean') {
                    throw new Error('Like state was not loaded.');
                }
                if (result.liked) {
                    showLikedState(button);
                    rememberLikedIdentifier(identifier);
                }
                updateCount(result);
                if (Number.isInteger(result.count_refresh_interval)) {
                    const interval = Number(result.count_refresh_interval);
                    countRefreshInterval = [0, 5, 10, 30, 60].includes(interval) ? interval : 0;
                    scheduleCountRefresh();
                }
            } catch (error) {
                button.disabled = false;
                status.textContent = widgetText.state_unavailable;
            } finally {
                button.disabled = isMaintenance;
            }
        }

        restoreState();

        button.addEventListener('click', async () => {
            if (button.disabled || isMaintenance) {
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
                    body: JSON.stringify(requestPayload(true)),
                });
                const result = await response.json();
                updateWidgetText(result.widget_text);

                if (result.maintenance === true) {
                    isMaintenance = true;
                    button.disabled = true;
                    status.textContent = widgetText.maintenance;
                    return;
                }

                if (result.rate_limited === true) {
                    button.disabled = false;
                    status.textContent = widgetText.rate_limit;
                    return;
                }

                if (!response.ok || result.success !== true) {
                    throw new Error('Like was not accepted.');
                }

                button.textContent = '♥';
                showLikedState(button);
                button.disabled = false;
                rememberLikedIdentifier(identifier);
                updateCount(result);
                status.textContent = typeof result.success_message === 'string'
                    ? result.success_message
                    : 'Good!';
                statusTimer = window.setTimeout(() => {
                    status.textContent = '';
                    statusTimer = null;
                }, 500);
            } catch (error) {
                button.disabled = false;
                status.textContent = widgetText.send_error;
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
