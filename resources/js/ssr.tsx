import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { renderToString } from 'react-dom/server';

import { TooltipProvider } from '@/components/ui/tooltip';
import { pageTitle, resolvePage } from '@/lib/pages';

// Server-side rendering for the public website, so search engines and link previews get the full page.
// Run with `php artisan inertia:start-ssr` after `npm run build`. The admin panel is excluded (AppServiceProvider).
createServer((page) =>
    createInertiaApp({
        page,
        render: renderToString,
        title: pageTitle,
        resolve: resolvePage,
        setup: ({ App, props }) => (
            <TooltipProvider>
                <App {...props} />
            </TooltipProvider>
        ),
    }),
);
