import { Link, router } from '@inertiajs/react';
import { Building2, IndianRupee, Search, Sparkles, Users, X } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { Pager } from '@/components/admin/pager';
import { StateBadge } from '@/components/admin/state-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AdminLayout } from '@/layouts/admin-layout';
import { formatRupee } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SubscriptionState } from '@/types';

type Row = {
    id: number;
    name: string;
    owner: string | null;
    phone: string | null;
    joined: string;
    members: number;
    state: SubscriptionState;
    stateLabel: string;
    plan: string | null;
    ends: string;
};

type Props = {
    counts: Record<'all' | SubscriptionState, number>;
    totals: { activeMembers: number; newThisMonth: number; revenueThisMonth: number; revenueTotal: number };
    search: string;
    status: 'all' | SubscriptionState;
    libraries: { rows: Row[]; page: number; lastPage: number; total: number; prevUrl: string | null; nextUrl: string | null };
};

const FILTERS: { key: 'all' | SubscriptionState; label: string; dot?: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'trial', label: 'On free trial', dot: 'bg-sky-500' },
    { key: 'active', label: 'Paid', dot: 'bg-emerald-500' },
    { key: 'grace', label: 'Grace period', dot: 'bg-amber-500' },
    { key: 'expired', label: 'Read-only', dot: 'bg-red-500' },
];
export default function Dashboard({ counts, totals, search, status, libraries }: Props) {
    const [q, setQ] = useState(search);

    const visit = (params: { q?: string; status?: string }) => {
        const next = { q: params.q ?? search, status: params.status ?? status };
        router.get('/admin', { q: next.q || undefined, status: next.status === 'all' ? undefined : next.status }, { preserveState: true, preserveScroll: true });
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        visit({ q: q.trim() });
    };

    const stats = [
        { label: 'Libraries', value: counts.all.toLocaleString('en-IN'), hint: `${totals.newThisMonth} joined this month`, icon: Building2 },
        { label: 'Paying libraries', value: counts.active.toLocaleString('en-IN'), hint: `${counts.trial} on free trial`, icon: Sparkles },
        { label: 'Active students', value: totals.activeMembers.toLocaleString('en-IN'), hint: 'Across all libraries', icon: Users },
        { label: 'Plan revenue this month', value: formatRupee(totals.revenueThisMonth), hint: `${formatRupee(totals.revenueTotal)} in total`, icon: IndianRupee },
    ];

    return (
        <AdminLayout title="Dashboard">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {stats.map(({ label, value, hint, icon: Icon }) => (
                    <Card key={label} className="gap-2 py-5 shadow-none">
                        <CardHeader className="flex flex-row items-center justify-between px-5">
                            <CardTitle className="text-sm font-normal text-muted-foreground">{label}</CardTitle>
                            <Icon className="size-4 text-brand" strokeWidth={1.8} />
                        </CardHeader>
                        <CardContent className="px-5">
                            <p className="text-3xl font-medium tracking-tight">{value}</p>
                            <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <Card className="mt-6 gap-0 py-0 shadow-none">
                <div className="space-y-4 border-b border-border bg-[#fcfaf6] px-4 pt-5 pb-4 sm:px-5">
                    <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 className="text-lg font-medium">Libraries</h2>
                            <p className="text-sm text-muted-foreground">
                                {search ? (
                                    <>
                                        {libraries.total} {libraries.total === 1 ? 'match' : 'matches'} for “{search}”
                                    </>
                                ) : (
                                    <>
                                        Showing {libraries.total} of {counts.all}
                                    </>
                                )}
                            </p>
                        </div>
                        <form onSubmit={submit} className="flex w-full gap-2 md:w-[420px]">
                            <div className="relative flex-1">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder="Search library, owner or phone"
                                    className="h-10 rounded-full bg-white pr-9 pl-9 shadow-none"
                                    aria-label="Search libraries"
                                />
                                {(q || search) && (
                                    <button
                                        type="button"
                                        aria-label="Clear search"
                                        className="absolute top-1/2 right-2 grid size-6 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
                                        onClick={() => {
                                            setQ('');
                                            if (search) visit({ q: '' });
                                        }}
                                    >
                                        <X className="size-3.5" />
                                    </button>
                                )}
                            </div>
                            <Button type="submit" className="h-10 rounded-full px-5">
                                Search
                            </Button>
                        </form>
                    </div>

                    <div className="-mx-4 overflow-x-auto px-4 [scrollbar-width:none] sm:-mx-5 sm:px-5 [&::-webkit-scrollbar]:hidden" role="radiogroup" aria-label="Filter by status">
                        <div className="flex w-max gap-2">
                            {FILTERS.map((f) => {
                                const selected = status === f.key;
                                return (
                                    <button
                                        key={f.key}
                                        type="button"
                                        role="radio"
                                        aria-checked={selected}
                                        onClick={() => visit({ status: f.key })}
                                        className={cn(
                                            'flex h-9 items-center gap-2 rounded-full border px-3.5 text-sm whitespace-nowrap transition-colors',
                                            selected
                                                ? 'border-night bg-night text-[#f3ece2]'
                                                : 'border-border bg-white text-foreground/80 hover:border-foreground/25 hover:text-foreground',
                                        )}
                                    >
                                        {f.dot && <span className={cn('size-2 rounded-full', f.dot)} />}
                                        {f.label}
                                        <span
                                            className={cn(
                                                'min-w-6 rounded-full px-1.5 py-0.5 text-center text-xs font-medium tabular-nums',
                                                selected ? 'bg-white/15 text-white' : 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            {counts[f.key] ?? 0}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="pl-4">Library</TableHead>
                            <TableHead>Owner</TableHead>
                            <TableHead>Joined</TableHead>
                            <TableHead className="text-right">Students</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="pr-4">Ends</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {libraries.rows.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={6} className="py-12 text-center text-muted-foreground">
                                    No libraries match.
                                </TableCell>
                            </TableRow>
                        )}
                        {libraries.rows.map((row) => (
                            <TableRow key={row.id} className="cursor-pointer" onClick={() => router.visit(`/admin/libraries/${row.id}`)}>
                                <TableCell className="pl-4">
                                    <Link href={`/admin/libraries/${row.id}`} className="font-medium hover:text-brand" onClick={(e) => e.stopPropagation()}>
                                        {row.name}
                                    </Link>
                                    <div className="text-xs text-muted-foreground">#{row.id}</div>
                                </TableCell>
                                <TableCell>
                                    {row.owner}
                                    <div className="text-xs text-muted-foreground">{row.phone}</div>
                                </TableCell>
                                <TableCell className="text-muted-foreground">{row.joined}</TableCell>
                                <TableCell className="text-right tabular-nums">{row.members}</TableCell>
                                <TableCell>
                                    <StateBadge state={row.state} label={row.stateLabel} />
                                    {row.plan && <div className="mt-1 text-xs text-muted-foreground">{row.plan}</div>}
                                </TableCell>
                                <TableCell className="pr-4 text-muted-foreground">{row.ends}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>
            <Pager page={libraries.page} lastPage={libraries.lastPage} prevUrl={libraries.prevUrl} nextUrl={libraries.nextUrl} />
        </AdminLayout>
    );
}
