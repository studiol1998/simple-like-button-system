'use strict';

document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
    toggle.addEventListener('change', () => {
        const form = toggle.closest('form');
        if (!form) return;
        form.querySelectorAll('input[type="password"], input[data-visible-password]').forEach((input) => {
            input.type = toggle.checked ? 'text' : 'password';
            if (toggle.checked) input.dataset.visiblePassword = '';
            else delete input.dataset.visiblePassword;
        });
    });
});

const adminTabs = [...document.querySelectorAll('[data-admin-tab]')];

function selectAdminTab(tab) {
        const name = tab.dataset.adminTab;
        adminTabs.forEach((item) => {
            const active = item === tab;
            item.setAttribute('aria-selected', String(active));
            item.tabIndex = active ? 0 : -1;
        });
        document.querySelectorAll('[data-admin-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.adminPanel !== name;
        });
        const url = new URL(window.location.href);
        url.searchParams.set('tab', name);
        history.replaceState(null, '', url);
}

adminTabs.forEach((tab, index) => {
    tab.addEventListener('click', () => selectAdminTab(tab));
    tab.addEventListener('keydown', (event) => {
        let nextIndex = null;
        if (event.key === 'ArrowRight') nextIndex = (index + 1) % adminTabs.length;
        if (event.key === 'ArrowLeft') nextIndex = (index + adminTabs.length - 1) % adminTabs.length;
        if (event.key === 'Home') nextIndex = 0;
        if (event.key === 'End') nextIndex = adminTabs.length - 1;
        if (nextIndex !== null) {
            event.preventDefault();
            selectAdminTab(adminTabs[nextIndex]);
            adminTabs[nextIndex].focus();
        }
    });
});

const languageSelect = document.querySelector('[data-language-select]');
if (languageSelect) {
    languageSelect.addEventListener('change', () => languageSelect.form.submit());
}

const themeSelect = document.querySelector('[data-theme-select]');
if (themeSelect) {
    themeSelect.addEventListener('change', () => {
        document.documentElement.dataset.theme = themeSelect.value;
        themeSelect.form.submit();
    });
}

const detailMonth = document.querySelector('[data-detail-month]');
if (detailMonth) {
    detailMonth.addEventListener('change', () => detailMonth.form.submit());
}

const messageCatalogNode = document.getElementById('widget-message-catalog');
const widgetLanguage = document.querySelector('[data-widget-language]');
const successMessage = document.querySelector('[data-success-message]');
if (messageCatalogNode && widgetLanguage && successMessage) {
    const messageCatalog = JSON.parse(messageCatalogNode.textContent);
    widgetLanguage.addEventListener('change', () => {
        const previous = successMessage.value;
        successMessage.replaceChildren();
        Object.entries(messageCatalog[widgetLanguage.value] || {}).forEach(([key, value]) => {
            const option = document.createElement('option');
            option.value = key;
            option.textContent = value;
            successMessage.append(option);
        });
        if ([...successMessage.options].some((option) => option.value === previous)) {
            successMessage.value = previous;
        }
    });
}

const countVisibility = document.querySelector('[data-count-visibility]');
const countDependents = [...document.querySelectorAll('[data-count-dependent]')];
function updateCountDependents() {
    const disabled = countVisibility && countVisibility.value === 'off';
    countDependents.forEach((control) => { control.disabled = disabled; });
}
if (countVisibility) {
    countVisibility.addEventListener('change', updateCountDependents);
    updateCountDependents();
}

function connectColorInputs(colorPicker, colorValue) {
    if (!colorPicker || !colorValue) return;
    const validHexColor = /^#[0-9a-f]{6}$/i;
    const updateHexInput = () => {
        colorValue.value = colorPicker.value.toUpperCase();
    };
    colorPicker.addEventListener('input', updateHexInput);
    colorValue.addEventListener('input', () => {
        const valid = validHexColor.test(colorValue.value);
        colorValue.setCustomValidity(valid ? '' : colorValue.dataset.invalidText || '');
        if (valid) {
            colorPicker.value = colorValue.value.toLowerCase();
        }
    });
    colorValue.addEventListener('change', () => {
        if (validHexColor.test(colorValue.value)) {
            colorValue.value = colorValue.value.toUpperCase();
        }
    });
    updateHexInput();
}

const countColor = document.getElementById('count-color');
const countColorValue = document.querySelector('[data-count-color-value]');
const buttonLikedColor = document.querySelector('[data-button-liked-color]');
const buttonLikedColorValue = document.querySelector('[data-button-liked-color-value]');
connectColorInputs(countColor, countColorValue);
connectColorInputs(buttonLikedColor, buttonLikedColorValue);

const symbolPreset = document.querySelector('[data-button-symbol-preset]');
const unlikedColorOptions = [...document.querySelectorAll('[data-button-unliked-color]')];
const likePreviewButton = document.querySelector('[data-like-preview-button]');
const likePreviewCount = document.querySelector('[data-like-preview-count]');
const likePreviewStage = document.querySelector('[data-like-preview-stage]');
const previewBackgroundButtons = [...document.querySelectorAll('[data-preview-background]')];
if (symbolPreset && likePreviewButton && likePreviewCount && likePreviewStage) {
    const symbols = {
        heart: ['♡', '♥'],
        diamond: ['♢', '♦'],
        spade: ['♤', '♠'],
        club: ['♧', '♣'],
        star: ['☆', '★'],
        music: ['♭', '♪'],
    };
    let liked = false;
    const updateLikePreview = () => {
        const selectedSymbols = symbols[symbolPreset.value] || symbols.heart;
        const unlikedColor = unlikedColorOptions.find((option) => option.checked)?.value || '#666666';
        const likedColor = buttonLikedColor?.value || '#e53955';
        likePreviewButton.textContent = selectedSymbols[liked ? 1 : 0];
        likePreviewButton.style.color = liked ? likedColor : unlikedColor;
        likePreviewButton.setAttribute('aria-pressed', String(liked));
        if (countColor) likePreviewCount.style.color = countColor.value;
    };
    symbolPreset.addEventListener('change', updateLikePreview);
    unlikedColorOptions.forEach((option) => option.addEventListener('change', updateLikePreview));
    [buttonLikedColor, buttonLikedColorValue, countColor, countColorValue].forEach((control) => {
        control?.addEventListener('input', updateLikePreview);
    });
    likePreviewButton.addEventListener('click', () => {
        liked = !liked;
        updateLikePreview();
    });
    previewBackgroundButtons.forEach((button) => {
        button.addEventListener('click', () => {
            likePreviewStage.dataset.background = button.dataset.previewBackground || 'light';
            previewBackgroundButtons.forEach((item) => item.classList.toggle('is-active', item === button));
        });
    });
    updateLikePreview();
}

document.querySelectorAll('[data-settings-form]').forEach((form) => {
    const status = form.querySelector('[data-save-status]');
    const markUnsaved = () => {
        if (status) status.textContent = form.dataset.unsavedText || '';
    };
    form.addEventListener('input', markUnsaved);
    form.addEventListener('change', markUnsaved);
    form.addEventListener('submit', () => {
        form.querySelectorAll('[data-count-dependent]').forEach((control) => { control.disabled = false; });
    });
});

const timezoneForm = document.querySelector('[data-timezone-form]');
const timezoneDataNode = document.getElementById('timezone-change-data');
if (timezoneForm && timezoneDataNode) {
    const timezoneData = JSON.parse(timezoneDataNode.textContent);
    const timezoneSelect = timezoneForm.querySelector('[name="timezone"]');
    const timezoneButton = timezoneForm.querySelector('[data-timezone-button]');
    const timezoneResult = document.querySelector('[data-timezone-result]');

    async function postTimezoneAction(action) {
        const payload = new URLSearchParams(new FormData(timezoneForm));
        payload.set('action', action);
        payload.set('timezone', timezoneSelect.value);
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: payload,
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || timezoneData.labels.error);
        return data;
    }

    timezoneForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!timezoneData.inProgress) {
            if (timezoneSelect.value === timezoneData.currentTimezone) return;
            if (!window.confirm(timezoneForm.dataset.confirmText || '')) return;
        }
        timezoneSelect.disabled = true;
        timezoneButton.disabled = true;
        try {
            if (!timezoneData.inProgress) {
                timezoneResult.textContent = timezoneData.labels.aggregating;
                await postTimezoneAction('begin_timezone_change');
                timezoneData.inProgress = true;
            }
            while (true) {
                const batch = await postTimezoneAction('timezone_change_batch');
                timezoneResult.textContent = `${timezoneData.labels.aggregating}${batch.pending}`;
                if (batch.ready_to_archive) break;
                if (batch.processed === 0) throw new Error(timezoneData.labels.error);
            }
            timezoneResult.textContent = timezoneData.labels.archiving;
            const completed = await postTimezoneAction('finish_timezone_change');
            timezoneResult.textContent = `${timezoneData.labels.done}${completed.archive_directory}. ${timezoneData.labels.storageNote}`;
            window.location.reload();
        } catch (error) {
            timezoneResult.textContent = error instanceof Error ? error.message : timezoneData.labels.error;
            timezoneSelect.disabled = false;
            timezoneButton.disabled = false;
        }
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

const siteOriginField = document.querySelector('[data-site-origin]');
const baseDomainField = document.querySelector('[data-base-domain]');
if (siteOriginField && baseDomainField) {
    let baseDomainWasEdited = baseDomainField.value.trim() !== '';
    let automaticValue = '';

    const getCertainBaseDomainCandidate = () => {
        let host = '';
        try {
            host = new URL(siteOriginField.value).hostname.replace(/^\[|\]$/g, '').toLowerCase();
        } catch (_) {
            return '';
        }
        if (host.startsWith('www.') && host.length > 4) return host.slice(4);
        return host;
    };

    const updateBaseDomainCandidate = () => {
        if (baseDomainWasEdited && baseDomainField.value !== automaticValue) return;
        const candidate = getCertainBaseDomainCandidate();
        if (candidate === '') return;
        baseDomainField.value = candidate;
        automaticValue = candidate;
    };

    baseDomainField.addEventListener('input', () => {
        if (baseDomainField.value !== automaticValue) baseDomainWasEdited = true;
    });
    siteOriginField.addEventListener('input', updateBaseDomainCandidate);
    updateBaseDomainCandidate();
}

document.querySelectorAll('[data-origin-delete-form], [data-access-release-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirmMessage || '')) event.preventDefault();
    });
});
