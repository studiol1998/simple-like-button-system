'use strict';

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
