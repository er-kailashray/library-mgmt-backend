import { Link, usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import type { SharedProps } from '@/types';

const NAV = [
    { label: 'Features', href: '/#features' },
    { label: 'How it works', href: '/#how' },
    { label: 'Pricing', href: '/#pricing' },
    { label: 'FAQ', href: '/#faq' },
];

/** Sign-up happens in the web app; without one configured, send people to pricing. */
export function startTrialUrl(webApp: string | null) {
    return webApp ? `${webApp}/register` : '/#pricing';
}

export function SiteHeader() {
    const { links } = usePage<SharedProps>().props;

    return (
        <header className="sticky top-0 z-40 border-b border-border/70 bg-background/85 backdrop-blur">
            <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
                <Link href="/" className="flex items-center" aria-label="LibriSpace home">
                    <img src="/brand/librispace.svg" alt="LibriSpace" className="h-7 w-auto" width={190} height={28} />
                </Link>
                <nav className="hidden items-center gap-8 text-[15px] text-foreground/80 md:flex">
                    {NAV.map((item) => (
                        <a key={item.href} href={item.href} className="transition-colors hover:text-foreground">
                            {item.label}
                        </a>
                    ))}
                </nav>
                <div className="hidden items-center gap-2 md:flex">
                    {links.webApp && (
                        <Button variant="ghost" asChild>
                            <a href={`${links.webApp}/login`}>Sign in</a>
                        </Button>
                    )}
                    <Button asChild>
                        <a href={startTrialUrl(links.webApp)}>Start free trial</a>
                    </Button>
                </div>
                <Sheet>
                    <SheetTrigger asChild>
                        <Button variant="ghost" size="icon" className="md:hidden" aria-label="Open menu">
                            <Menu className="size-5" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="right" className="w-72">
                        <SheetHeader>
                            <SheetTitle className="sr-only">Menu</SheetTitle>
                            <img src="/brand/librispace.svg" alt="LibriSpace" className="h-6 w-auto self-start" />
                        </SheetHeader>
                        <nav className="flex flex-col gap-1 px-4">
                            {NAV.map((item) => (
                                <a key={item.href} href={item.href} className="rounded-md px-2 py-2.5 text-base hover:bg-muted">
                                    {item.label}
                                </a>
                            ))}
                        </nav>
                        <div className="mt-auto flex flex-col gap-2 p-4">
                            {links.webApp && (
                                <Button variant="outline" asChild>
                                    <a href={`${links.webApp}/login`}>Sign in</a>
                                </Button>
                            )}
                            <Button asChild>
                                <a href={startTrialUrl(links.webApp)}>Start free trial</a>
                            </Button>
                        </div>
                    </SheetContent>
                </Sheet>
            </div>
        </header>
    );
}
