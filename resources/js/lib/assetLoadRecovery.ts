import { createApp } from 'vue';
import AssetLoadErrorNotice from '@/components/AssetLoadErrorNotice.vue';

// Vite emits this for failed dynamic JS imports and CSS preloads. Keep the
// original rejection visible to monitoring, and leave forms mounted until the
// user chooses whether to reload. This also works before Inertia has mounted.
export function installAssetLoadRecovery(browser: Window = window): () => void {
    let dismiss: (() => void) | undefined;
    const onError = () => {
        if (/^\/tracker(?:\/|$)/.test(browser.location.pathname) || dismiss)
            return;

        const host = browser.document.createElement('div');
        browser.document.body.appendChild(host);
        const app = createApp(AssetLoadErrorNotice, {
            onReload: () => browser.location.reload(),
            onDismiss: () => dismiss?.(),
        });
        dismiss = () => {
            app.unmount();
            host.remove();
            dismiss = undefined;
        };
        app.mount(host);
    };
    browser.addEventListener('vite:preloadError', onError);
    return () => {
        browser.removeEventListener('vite:preloadError', onError);
        dismiss?.();
    };
}
