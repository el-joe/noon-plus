import { initCountryDatetimeFields } from './country-datetime';
import { initPanelSidebar } from './panel-sidebar';
import { initCopyButtons } from './copy-text';
import { initLiveDispatch } from './live-dispatch';

document.addEventListener('DOMContentLoaded', () => {
    initCountryDatetimeFields();
    initPanelSidebar();
    initCopyButtons();
    void initLiveDispatch();
});
