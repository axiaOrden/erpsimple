import Alpine from 'alpinejs';
import { netStore } from './net.js';
import { net } from './net-store.js';
import { visitQueue } from './visit-queue.js';
import { orderCaptureComponent } from './order-capture.js';
import { customerRegistration } from './customer-map.js';
import { paymentProof } from './payment-proof.js';
import { registerServiceWorker } from './sw-register.js';

// Network/sync state store shared by the header pill and banners.
document.addEventListener('alpine:init', () => {
    Alpine.store('net', netStore);
    // Init via the reactive proxy so state mutations trigger re-renders.
    Alpine.store('net').init();

    // Give non-Alpine modules access to the reactive store.
    net.bind(Alpine.store('net'));

    Alpine.data('visitQueue', visitQueue);
    Alpine.data('orderCapture', (existing) => orderCaptureComponent(existing));
    Alpine.data('customerRegistration', (config) => customerRegistration(config));
    Alpine.data('paymentProof', (config) => paymentProof(config));

    // Reflect queued operations in the header pill at startup.
    net.refreshPending();
    window.addEventListener('online', () => {
        import('./pwa/sync-engine.js').then((m) => m.syncNow().catch(() => {}));
    });
});

window.Alpine = Alpine;
Alpine.start();

// Drawer toggle (tablet widths; desktop shows the drawer statically).
document.addEventListener('DOMContentLoaded', () => {
    const drawer = document.getElementById('drawer');
    document.querySelectorAll('[data-drawer-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            drawer?.classList.toggle('-translate-x-full');
        });
    });

    registerServiceWorker();
});
