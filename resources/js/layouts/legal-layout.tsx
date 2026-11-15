import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { SiteFooter } from '@/components/site/site-footer';
import { SiteHeader } from '@/components/site/site-header';

/** Shell for the Terms, Privacy, Refunds and Contact pages: one readable column, plain headings. */
export function LegalLayout({ title, updated, children }: { title: string; updated?: string; children: ReactNode }) {
    return (
        <>
            <Head title={title} />
            <SiteHeader />
            <main className="mx-auto max-w-3xl px-4 py-14 sm:px-6 sm:py-20">
                <h1 className="font-serif text-4xl font-semibold tracking-tight sm:text-5xl">{title}</h1>
                {updated && <p className="mt-3 text-sm text-muted-foreground">Last updated {updated}</p>}
                <div className="mt-10 space-y-5 text-[16px] leading-7 text-foreground/85 [&_a]:text-brand [&_a]:underline [&_h2]:!mt-10 [&_h2]:font-serif [&_h2]:text-2xl [&_h2]:font-semibold [&_h2]:text-foreground [&_li]:ml-5 [&_li]:list-disc [&_li]:pl-1 [&_strong]:font-medium [&_strong]:text-foreground [&_ul]:space-y-2">
                    {children}
                </div>
            </main>
            <SiteFooter />
        </>
    );
}

/** A business detail from .env; until it's filled in, it shows as a visible placeholder. */
export function Fill({ value, label }: { value: string | null | undefined; label: string }) {
    return value ? <>{value}</> : <mark className="rounded bg-amber-100 px-1 text-amber-900">[{label}: set in .env]</mark>;
}
