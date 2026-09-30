/** Registers the service worker (PWA shell caching) in production only. */
export function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    if (import.meta.env.DEV) return; // avoid stale SW caches during development

    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .catch(() => {
                // Registration failure is non-fatal: the app still works online.
            });
    });
}
