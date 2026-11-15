import type { ResolvedComponent } from '@inertiajs/react';

const pages = import.meta.glob<{ default: ResolvedComponent }>('../pages/**/*.tsx');

/** Loads a page component by its Inertia name, e.g. "admin/dashboard". Shared by the browser and SSR entries. */
export async function resolvePage(name: string) {
    const page = pages[`../pages/${name}.tsx`];
    if (!page) throw new Error(`Page not found: ${name}`);
    return (await page()).default;
}

export const pageTitle = (title: string) => (title ? `${title} · LibriSpace` : 'LibriSpace');
