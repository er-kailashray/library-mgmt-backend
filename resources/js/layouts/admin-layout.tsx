import { Head, Link, router, usePage } from '@inertiajs/react';
import { Activity, CheckCircle2, LayoutDashboard, LogOut, Menu, Settings } from 'lucide-react';
import type { ReactNode } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

const NAV = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard, match: (url: string) => url === '/admin' || url.startsWith('/admin?') || url.startsWith('/admin/libraries') },
    { label: 'Settings', href: '/admin/settings', icon: Settings, match: (url: string) => url.startsWith('/admin/settings') },
    { label: 'Activity', href: '/admin/activity', icon: Activity, match: (url: string) => url.startsWith('/admin/activity') },
];

function NavLinks({ onNavigate }: { onNavigate?: () => void }) {
    const { url } = usePage();

    return (
        <nav className="flex flex-col gap-1">
            {NAV.map(({ label, href, icon: Icon, match }) => (
                <Link
                    key={href}
                    href={href}
                    onClick={onNavigate}
                    className={cn(
                        'flex items-center gap-3 rounded-md px-3 py-2 text-[15px] transition-colors',
                        match(url) ? 'bg-night text-[#f3ece2]' : 'text-foreground/75 hover:bg-black/5 hover:text-foreground',
                    )}
                >
                    <Icon className="size-4" strokeWidth={1.8} />
                    {label}
                </Link>
            ))}
        </nav>
    );
}

function UserBox() {
    const { auth } = usePage<SharedProps>().props;
    const name = auth.admin?.name ?? 'Admin';

    return (
        <div className="flex items-center gap-3 border-t border-sidebar-border pt-4">
            <Avatar className="size-9">
                <AvatarFallback className="bg-sand text-xs font-medium">{name.slice(0, 2).toUpperCase()}</AvatarFallback>
            </Avatar>
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{name}</p>
                <p className="truncate text-xs text-muted-foreground">Super admin</p>
            </div>
            <Button variant="ghost" size="icon" aria-label="Sign out" title="Sign out" onClick={() => router.post('/admin/logout')}>
                <LogOut className="size-4" />
            </Button>
        </div>
    );
}

export function AdminLayout({ title, children, actions }: { title: string; children: ReactNode; actions?: ReactNode }) {
    const { flash } = usePage<SharedProps>().props;

    return (
        <div className="min-h-screen bg-background">
            <Head title={`${title} · Admin`} />
            <aside className="fixed inset-y-0 left-0 hidden w-64 flex-col border-r border-sidebar-border bg-sidebar p-4 lg:flex">
                <Link href="/admin" className="mb-1 flex items-center gap-2 px-2 py-2">
                    <img src="/brand/librispace.svg" alt="LibriSpace" className="h-6 w-auto" />
                </Link>
                <p className="mb-6 px-3 text-[11px] tracking-[0.14em] text-muted-foreground uppercase">Super admin</p>
                <NavLinks />
                <div className="mt-auto">
                    <UserBox />
                </div>
            </aside>

            <div className="lg:pl-64">
                <header className="sticky top-0 z-30 flex h-14 items-center justify-between border-b border-border bg-background/90 px-4 backdrop-blur lg:hidden">
                    <img src="/brand/librispace.svg" alt="LibriSpace" className="h-5 w-auto" />
                    <MobileMenu />
                </header>
                <main className="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:py-10">
                    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
                        <h1 className="font-serif text-3xl font-semibold tracking-tight sm:text-4xl">{title}</h1>
                        {actions}
                    </div>
                    {flash.status && (
                        <Alert className="mb-6 border-emerald-200 bg-emerald-50 text-emerald-900">
                            <CheckCircle2 className="size-4" />
                            <AlertDescription className="text-emerald-900">{flash.status}</AlertDescription>
                        </Alert>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}

function MobileMenu() {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button variant="ghost" size="icon" aria-label="Open menu">
                    <Menu className="size-5" />
                </Button>
            </SheetTrigger>
            <SheetContent side="left" className="flex w-72 flex-col bg-sidebar p-4">
                <SheetHeader className="p-0 pb-4">
                    <SheetTitle className="sr-only">Menu</SheetTitle>
                    <img src="/brand/librispace.svg" alt="LibriSpace" className="h-6 w-auto self-start" />
                </SheetHeader>
                <NavLinks />
                <div className="mt-auto">
                    <UserBox />
                </div>
            </SheetContent>
        </Sheet>
    );
}
