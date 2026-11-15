import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot, hydrateRoot } from 'react-dom/client';

import { TooltipProvider } from '@/components/ui/tooltip';
import { pageTitle, resolvePage } from '@/lib/pages';

createInertiaApp({
    title: pageTitle,
    resolve: resolvePage,
    setup({ el, App, props }) {
        const app = (
            <TooltipProvider>
                <App {...props} />
            </TooltipProvider>
        );
        // Public pages arrive server-rendered (SSR): attach to that HTML instead of redrawing it.
        if (el.hasChildNodes()) {
            hydrateRoot(el, app);
        } else {
            createRoot(el).render(app);
        }
    },
    progress: { color: '#A8643A' },
});
