import { usePage } from '@inertiajs/react';
import { Smartphone } from 'lucide-react';

import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/**
 * Download badges: the official Google Play badge (links to the store page) and an iPhone badge.
 * Until APP_STORE_URL is set the iPhone badge shows "coming soon" with a small loading animation,
 * and points iPhone owners to the web app meanwhile.
 */
export function StoreButtons({ tone = 'light', className }: { tone?: 'light' | 'dark'; className?: string }) {
    const { links } = usePage<SharedProps>().props;
    const dark = tone === 'dark';

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            <div className="flex flex-wrap items-center gap-3">
                {links.playStore && (
                    <a href={links.playStore} target="_blank" rel="noopener" aria-label="Get LibriSpace on Google Play" className="transition-opacity hover:opacity-90">
                        {/* The official badge has built-in padding; the negative margin lines it up with the iPhone badge. */}
                        <img src="/brand/google-play-badge.png" alt="Get it on Google Play" width={168} height={65} className="-my-[9px] -mr-[10px] -ml-[10px] h-[65px] w-auto" />
                    </a>
                )}

                {links.appStore ? (
                    <a
                        href={links.appStore}
                        target="_blank"
                        rel="noopener"
                        className="flex h-[47px] items-center gap-2.5 rounded-[9px] border border-[#a6a6a6] bg-black px-3.5 text-white transition-opacity hover:opacity-90"
                    >
                        <Smartphone className="size-6" strokeWidth={1.5} />
                        <span className="leading-tight">
                            <span className="block text-[10px]">Download for</span>
                            <span className="block text-[17px] font-medium">iPhone</span>
                        </span>
                    </a>
                ) : (
                    <div
                        role="status"
                        aria-label="iPhone app coming soon"
                        className={cn(
                            'relative flex h-[47px] items-center gap-2.5 overflow-hidden rounded-[9px] border px-3.5',
                            dark ? 'border-white/20 bg-white/5 text-[#efe7dc]' : 'border-border bg-white text-foreground',
                        )}
                    >
                        <span className="relative grid size-6 place-items-center">
                            <span className={cn('absolute inset-0 animate-spin rounded-full border-2 border-t-brand', dark ? 'border-white/15' : 'border-black/10')} style={{ animationDuration: '1.4s' }} />
                            <Smartphone className="size-3.5" strokeWidth={1.8} />
                        </span>
                        <span className="leading-tight">
                            <span className={cn('block text-[10px]', dark ? 'text-[#a99d90]' : 'text-muted-foreground')}>iPhone app</span>
                            <span className="flex items-center gap-1 text-[15px] font-medium">
                                Coming soon
                                <span className="flex gap-0.5" aria-hidden="true">
                                    {[0, 1, 2].map((i) => (
                                        <span key={i} className="size-1 animate-bounce rounded-full bg-brand" style={{ animationDelay: `${i * 0.15}s` }} />
                                    ))}
                                </span>
                            </span>
                        </span>
                        <span aria-hidden="true" className="ls-shimmer pointer-events-none absolute inset-y-0 -left-1/2 w-1/2 bg-gradient-to-r from-transparent via-white/40 to-transparent" />
                    </div>
                )}
            </div>
            {!links.appStore && links.webApp && (
                <p className={cn('text-sm', dark ? 'text-[#a99d90]' : 'text-muted-foreground')}>
                    On iPhone today?{' '}
                    <a href={links.webApp} className="text-brand underline underline-offset-4">
                        Use the web app
                    </a>{' '}
                    and add it to your home screen.
                </p>
            )}
        </div>
    );
}
