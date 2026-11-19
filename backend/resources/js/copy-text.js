/**
 * Buttons with `data-copy-text` copy that text to the clipboard and show
 * `data-copied-label` for a moment (QC-062 creator share).
 */
export function initCopyButtons() {
    document.querySelectorAll('[data-copy-text]').forEach((button) => {
        button.addEventListener('click', async () => {
            const original = button.textContent;
            try {
                await navigator.clipboard.writeText(button.dataset.copyText);
                button.textContent = button.dataset.copiedLabel || original;
                setTimeout(() => { button.textContent = original; }, 1500);
            } catch {
                window.prompt('', button.dataset.copyText);
            }
        });
    });
}
