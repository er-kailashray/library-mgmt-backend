import { Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';

export function Pager({ page, lastPage, prevUrl, nextUrl }: { page: number; lastPage: number; prevUrl: string | null; nextUrl: string | null }) {
    if (lastPage <= 1) return null;

    return (
        <div className="flex items-center justify-between pt-4">
            <span className="text-sm text-muted-foreground">
                Page {page} of {lastPage}
            </span>
            <div className="flex gap-2">
                <Button variant="outline" size="sm" disabled={!prevUrl} asChild={!!prevUrl}>
                    {prevUrl ? <Link href={prevUrl} preserveScroll>Previous</Link> : <span>Previous</span>}
                </Button>
                <Button variant="outline" size="sm" disabled={!nextUrl} asChild={!!nextUrl}>
                    {nextUrl ? <Link href={nextUrl} preserveScroll>Next</Link> : <span>Next</span>}
                </Button>
            </div>
        </div>
    );
}
