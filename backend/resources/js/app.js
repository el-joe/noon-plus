import { initCountryDatetimeFields } from './country-datetime';
import { initPanelSidebar } from './panel-sidebar';
import { initCopyButtons } from './copy-text';
import { initLiveDispatch } from './live-dispatch';

document.addEventListener('DOMContentLoaded', () => {
    initCountryDatetimeFields();
    initPanelSidebar();
    initCopyButtons();
    void initLiveDispatch();

    // Page builder editor (Content → Pages → page); loaded on demand.
    const pageBuilder = document.querySelector('[data-page-builder]');
    if (pageBuilder) {
        void import('./page-builder/editor').then(({ initPageBuilder }) => initPageBuilder(pageBuilder));
    }

    // D18: the live studio is only on a live broadcast's admin page; loaded on demand.
    if (document.querySelector('[data-live-studio]')) {
        void import('./live-studio').then(({ initLiveStudio }) => initLiveStudio());
    }
});
