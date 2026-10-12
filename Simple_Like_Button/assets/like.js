(() => {
    'use strict';

    const currentScript = document.currentScript;
    if (!currentScript || !currentScript.src) {
        return;
    }

    const likeEndpoint = new URL('../api/like.php', currentScript.src).toString();
    const stateEndpoint = new URL('../api/state.php', currentScript.src).toString();
    const runtimeKey = Symbol.for('pollyanna.like.runtime.v1');
    if (window[runtimeKey] === true) {
        return;
    }
    Object.defineProperty(window, runtimeKey, {value: true});
    const tokenStorageKey = 'pollyanna.client-token.v1';
    const likedIdentifiersStorageKey = 'pollyanna.liked-identifiers.v1';
    const widgetsByIdentifier = new Map();
    let volatileClientToken = null;

    function createClientToken() {
        const bytes = new Uint8Array(32);
        crypto.getRandomValues(bytes);
        return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    }

    function getClientToken() {
        if (volatileClientToken !== null) return volatileClientToken;
        try {
            const token = localStorage.getItem(tokenStorageKey);
            if (token && /^[a-f0-9]{64}$/i.test(token)) {
                volatileClientToken = token;
                return token;
            }
        } catch (error) {
            // Continue with an in-memory token when browser storage is unavailable.
        }
        volatileClientToken = createClientToken();
        try {
            localStorage.setItem(tokenStorageKey, volatileClientToken);
        } catch (error) {
            // The token remains stable for this page lifetime.
        }
        return volatileClientToken;
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

    function isIdentifierCharacterAllowed(character) {
        if (character === '-' || character === '_') return true;
        if (/^[\x21-\x2C\x2E-\x2F\x3A-\x40\x5B-\x5E\x60\x7B-\x7E]$/.test(character)) return false;
        return /^[\p{L}\p{M}\p{N}\p{P}\p{S}]$/u.test(character);
    }

    function isIdentifierValid(identifier) {
        const characters = Array.from(identifier);
        return characters.length >= 1 && characters.length <= 100
            && characters.every(isIdentifierCharacterAllowed);
    }

    function decodePathComponent(component) {
        try {
            return decodeURIComponent(component);
        } catch (error) {
            return component;
        }
    }

    function sanitizeIdentifierComponent(component) {
        return Array.from(component, character => isIdentifierCharacterAllowed(character) ? character : '_')
            .join('').replace(/_+/g, '_');
    }

    function generateIdentifier(position, pathname = location.pathname) {
        const rawPath = pathname || '/';
        if (rawPath === '/') return `root_${String(position).padStart(3, '0')}`;
        const directoryUrl = rawPath.endsWith('/');
        const components = rawPath.split('/').filter(component => component !== '');
        if (components.length === 0) return `root_${String(position).padStart(3, '0')}`;

        const directorySource = directoryUrl
            ? components.at(-1)
            : (components.length >= 2 ? components.at(-2) : 'root');
        const fileSource = directoryUrl ? null : decodePathComponent(components.at(-1)).replace(/\.[^.]*$/u, '');
        const directory = sanitizeIdentifierComponent(decodePathComponent(directorySource));
        const filename = fileSource === null ? null : sanitizeIdentifierComponent(fileSource);
        if (directory === '' || (filename !== null && filename === '')) return null;

        const sequence = String(position).padStart(3, '0');
        const identifier = filename === null
            ? `${directory}_${sequence}`
            : `${directory}_${filename}_${sequence}`;
        return isIdentifierValid(identifier) ? identifier : null;
    }

    function normalizePageUrl(candidate, baseUrl = location.href) {
        if (typeof candidate !== 'string' || candidate.trim() === '') return null;
        try {
            const rawCandidate = candidate.trim();
            const url = new URL(rawCandidate, baseUrl);
            if (!['http:', 'https:'].includes(url.protocol) || url.username !== '' || url.password !== '') return null;
            if (/^[a-z][a-z0-9+.-]*:/i.test(rawCandidate)) {
                return rawCandidate.slice(0, rawCandidate.search(/[?#]/) === -1 ? undefined : rawCandidate.search(/[?#]/));
            }
            url.search = '';
            url.hash = '';
            return url.toString();
        } catch (error) {
            return null;
        }
    }

    function resolvePageUrl(area) {
        const canonical = document.querySelector('link[rel~="canonical"]');
        const openGraphUrl = document.querySelector('meta[property="og:url"]');
        const candidates = [
            area.getAttribute('data-url'),
            canonical ? canonical.getAttribute('href') : null,
            openGraphUrl ? openGraphUrl.getAttribute('content') : null,
            location.href,
        ];
        for (const candidate of candidates) {
            const normalized = normalizePageUrl(candidate);
            if (normalized !== null) return normalized;
        }
        return null;
    }

    function groupWidgets(identifier) {
        return widgetsByIdentifier.get(identifier)?.widgets || new Set();
    }

    function getController(identifier) {
        let controller = widgetsByIdentifier.get(identifier);
        if (controller) return controller;
        controller = {
            identifier,
            widgets: new Set(),
            countRefreshInterval: 0,
            countRefreshTimer: null,
            countRefreshInFlight: false,
            consecutiveFailures: 0,
            maintenance: false,
            blockedUntil: 0,
            initialPromise: null,
            initialComplete: false,
            revision: 0,
            lastResult: null,
        };
        widgetsByIdentifier.set(identifier, controller);
        return controller;
    }

    function synchronizeLikedState(identifier, result = {}) {
        for (const widget of groupWidgets(identifier)) {
            widget.showLikedState();
            widget.updateCount(result);
        }
    }

    function synchronizeCount(identifier, result) {
        for (const widget of groupWidgets(identifier)) widget.updateCount(result);
    }

    function setGroupDisabled(identifier, disabled) {
        const controller = widgetsByIdentifier.get(identifier);
        for (const widget of groupWidgets(identifier)) {
            widget.button.disabled = disabled || controller?.maintenance === true;
        }
    }

    function updateGroupText(controller, translations) {
        for (const widget of controller.widgets) widget.updateWidgetText(translations);
    }

    function applyResultToWidget(widget, result) {
        widget.updateWidgetText(result?.widget_text);
        if (result?.liked === true) widget.showLikedState();
        widget.updateCount(result || {});
    }

    function stopCountRefresh(controller) {
        if (controller.countRefreshTimer !== null) {
            window.clearTimeout(controller.countRefreshTimer);
            controller.countRefreshTimer = null;
        }
    }

    function scheduleCountRefresh(controller, delaySeconds = null) {
        stopCountRefresh(controller);
        if (
            controller.countRefreshInterval === 0
            || document.hidden
            || controller.maintenance
        ) {
            return;
        }
        const delay = Math.max(delaySeconds === null ? controller.countRefreshInterval : delaySeconds,
            Math.ceil((controller.blockedUntil - Date.now()) / 1000));
        controller.countRefreshTimer = window.setTimeout(
            () => refreshCount(controller),
            delay * 1000
        );
    }

    function markMaintenance(controller) {
        controller.maintenance = true;
        stopCountRefresh(controller);
        for (const widget of controller.widgets) {
            widget.button.disabled = true;
            widget.status.textContent = widget.getText('maintenance');
        }
    }

    function showAccessBlock(controller, result) {
        if (result.access_blocked !== true) return false;
        const retry = Number.isInteger(result.retry_after) && result.retry_after > 0 ? result.retry_after : 60;
        controller.blockedUntil = Date.now() + Math.min(retry, 172800) * 1000;
        for (const widget of controller.widgets) widget.status.textContent = widget.getText('access_blocked');
        return true;
    }

    function stateRequestPayload(identifier) {
        return {identifier, client_token: getClientToken()};
    }

    async function refreshCount(controller) {
        controller.countRefreshTimer = null;
        if (
            controller.countRefreshInterval === 0
            || document.hidden
            || controller.maintenance
        ) {
            scheduleCountRefresh(controller);
            return;
        }
        if (controller.countRefreshInFlight) {
            scheduleCountRefresh(controller);
            return;
        }

        controller.countRefreshInFlight = true;
        const requestRevision = controller.revision;
        let retryDelay = null;
        try {
            const response = await fetch(stateEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(stateRequestPayload(controller.identifier)),
            });
            const result = await response.json();
            updateGroupText(controller, result.widget_text);
            if (showAccessBlock(controller, result)) return;
            if (result.maintenance === true) {
                markMaintenance(controller);
                return;
            }
            if (!response.ok || result.success !== true) {
                throw new Error('Like count was not loaded.');
            }
            controller.consecutiveFailures = 0;
            if (requestRevision === controller.revision) {
                controller.lastResult = {...(controller.lastResult || {}), ...result};
                synchronizeCount(controller.identifier, result);
            }
        } catch (error) {
            controller.consecutiveFailures += 1;
            retryDelay = Math.min(
                60,
                controller.countRefreshInterval * (2 ** controller.consecutiveFailures)
            );
        } finally {
            controller.countRefreshInFlight = false;
            scheduleCountRefresh(controller, retryDelay);
        }
    }

    async function restoreState(controller) {
        setGroupDisabled(controller.identifier, true);
        const requestRevision = controller.revision;
        try {
            const response = await fetch(stateEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(stateRequestPayload(controller.identifier)),
            });
            const result = await response.json();
            updateGroupText(controller, result.widget_text);
            if (showAccessBlock(controller, result)) return;
            if (result.maintenance === true) {
                markMaintenance(controller);
                return;
            }
            if (!response.ok || result.success !== true || typeof result.liked !== 'boolean') {
                throw new Error('Like state was not loaded.');
            }
            if (Number.isInteger(result.count_refresh_interval)) {
                const interval = Number(result.count_refresh_interval);
                controller.countRefreshInterval = [0, 5, 10, 30, 60].includes(interval) ? interval : 0;
            }
            if (requestRevision === controller.revision) {
                controller.lastResult = result;
                if (result.liked) {
                    synchronizeLikedState(controller.identifier, result);
                    rememberLikedIdentifier(controller.identifier);
                } else {
                    synchronizeCount(controller.identifier, result);
                }
            }
            controller.consecutiveFailures = 0;
            scheduleCountRefresh(controller);
        } catch (error) {
            for (const widget of controller.widgets) {
                widget.status.textContent = widget.getText('state_unavailable');
            }
        } finally {
            controller.initialComplete = true;
            setGroupDisabled(controller.identifier, false);
        }
    }

    function initialize(area, position) {
        const hasManualIdentifier = area.hasAttribute('data-identifier');
        const identifier = hasManualIdentifier ? area.getAttribute('data-identifier') : generateIdentifier(position);
        const canonicalUrl = resolvePageUrl(area);
        area.classList.add('pollyanna-like-area');
        const button = document.createElement('button');
        const count = document.createElement('span');
        const status = document.createElement('span');
        let statusTimer = null;
        let widgetText = {
            button_label: 'いいねを送信',
            count_label: 'いいね数',
            identifier_missing: '識別子が設定されていません。',
            identifier_invalid: '識別子の形式が正しくありません。',
            identifier_generation_failed: '識別子を自動生成できません。data-identifierを指定してください。',
            state_unavailable: '押下状態を確認できませんでした。',
            maintenance: 'メンテナンス中です。',
            send_error: '送信できませんでした。通信を確認して、もう一度お試しください。',
            rate_limit: 'この作品へのいいね上限に達しました。時間をおいてお試しください。',
            access_blocked: '集中アクセスによりAPI利用を一時的に停止しています。',
            count_color: '#767676',
            symbol_unliked: '♡',
            symbol_liked: '♥',
            symbol_unliked_color: '#666666',
            symbol_liked_color: '#e53955',
        };

        button.type = 'button';
        button.className = 'pollyanna-like-button';
        button.textContent = '♡';
        button.setAttribute('aria-label', widgetText.button_label);
        button.setAttribute('aria-pressed', 'false');

        count.className = 'pollyanna-like-count';
        count.setAttribute('aria-label', widgetText.count_label);
        count.hidden = true;

        status.className = 'pollyanna-like-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');

        area.replaceChildren(button, count, status);

        if (identifier === null) {
            button.disabled = true;
            status.textContent = widgetText.identifier_generation_failed;
            return;
        }

        if (!isIdentifierValid(identifier)) {
            button.disabled = true;
            status.textContent = widgetText.identifier_invalid;
            return;
        }

        function showLikedState() {
            button.classList.add('is-liked');
            button.setAttribute('aria-pressed', 'true');
            applyButtonAppearance();
        }

        function applyButtonAppearance() {
            const unlikedSymbols = ['♡', '♢', '♤', '♧', '☆', '♭'];
            const likedSymbols = ['♥', '♦', '♠', '♣', '★', '♪'];
            const unlikedSymbol = unlikedSymbols.includes(widgetText.symbol_unliked) ? widgetText.symbol_unliked : '♡';
            const likedSymbol = likedSymbols.includes(widgetText.symbol_liked) ? widgetText.symbol_liked : '♥';
            const unlikedColor = /^#[0-9a-f]{6}$/i.test(widgetText.symbol_unliked_color) ? widgetText.symbol_unliked_color : '#666666';
            const likedColor = /^#[0-9a-f]{6}$/i.test(widgetText.symbol_liked_color) ? widgetText.symbol_liked_color : '#e53955';
            area.style.setProperty('--pollyanna-like-unliked-color', unlikedColor);
            area.style.setProperty('--pollyanna-like-liked-color', likedColor);
            button.textContent = button.classList.contains('is-liked') ? likedSymbol : unlikedSymbol;
        }

        if (getLocallyLikedIdentifiers().includes(identifier)) {
            showLikedState();
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
            applyButtonAppearance();
            button.setAttribute('aria-label', widgetText.button_label);
            count.setAttribute('aria-label', widgetText.count_label);
        }

        function getText(key) {
            return widgetText[key] || '';
        }

        const controller = getController(identifier);
        const widget = {
            button,
            status,
            showLikedState,
            updateCount,
            updateWidgetText,
            getText,
        };
        controller.widgets.add(widget);
        if (controller.lastResult !== null) applyResultToWidget(widget, controller.lastResult);
        if (controller.maintenance) {
            button.disabled = true;
            status.textContent = widgetText.maintenance;
        } else if (!controller.initialComplete) {
            button.disabled = true;
        }

        if (controller.initialPromise === null) {
            controller.initialPromise = restoreState(controller);
        }

        button.addEventListener('click', async () => {
            if (button.disabled || controller.maintenance) {
                return;
            }

            if (statusTimer !== null) {
                window.clearTimeout(statusTimer);
                statusTimer = null;
            }
            setGroupDisabled(identifier, true);
            status.textContent = '';

            try {
                const response = await fetch(likeEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestPayload(true)),
                });
                const result = await response.json();
                updateGroupText(controller, result.widget_text);
                if (showAccessBlock(controller, result)) return;

                if (result.maintenance === true) {
                    markMaintenance(controller);
                    return;
                }

                if (result.rate_limited === true) {
                    status.textContent = widgetText.rate_limit;
                    return;
                }

                if (!response.ok || result.success !== true) {
                    throw new Error('Like was not accepted.');
                }

                rememberLikedIdentifier(identifier);
                controller.revision += 1;
                controller.lastResult = {...(controller.lastResult || {}), ...result, liked: true};
                synchronizeLikedState(identifier, result);
                status.textContent = typeof result.success_message === 'string'
                    ? result.success_message
                    : 'Good!';
                statusTimer = window.setTimeout(() => {
                    status.textContent = '';
                    statusTimer = null;
                }, 500);
            } catch (error) {
                status.textContent = widgetText.send_error;
            } finally {
                setGroupDisabled(identifier, false);
            }
        });
    }

    document.addEventListener('visibilitychange', () => {
        for (const controller of widgetsByIdentifier.values()) {
            scheduleCountRefresh(controller);
        }
    });

    function initializeAll() {
        document.querySelectorAll('[data-pollyanna-like]').forEach((area, index) => {
            initialize(area, index + 1);
        });
    }

    if (document.documentElement.hasAttribute('data-pollyanna-test')) {
        window.__pollyannaTest = Object.freeze({
            isIdentifierValid,
            generateIdentifier,
            normalizePageUrl,
            resolvePageUrl,
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAll, { once: true });
    } else {
        initializeAll();
    }
})();
