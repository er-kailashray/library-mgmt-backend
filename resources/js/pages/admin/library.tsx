import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';

import { ActivityTable } from '@/components/admin/activity-table';
import { StateBadge } from '@/components/admin/state-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AdminLayout } from '@/layouts/admin-layout';
import { formatRupee } from '@/lib/format';
import type { ActivityRow, SubscriptionState } from '@/types';

type Props = {
    library: {
        id: number;
        name: string;
        owner: string | null;
        phone: string | null;
        address: string | null;
        joined: string;
        seats: number;
        batches: number;
        activeMembers: number;
        totalMembers: number;
    };
    subscription: { state: SubscriptionState; stateLabel: string; plan: string | null; starts: string; ends: string; daysLeft: number; readOnlyFrom: string };
    payments: { id: number; date: string; plan: string | null; amount: number; status: string; invoice: string | null }[];
    activity: ActivityRow[];
    maxAdjustDays: number;
};

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[120px_1fr] gap-3 border-b border-border/70 py-2.5 text-[15px] last:border-0">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

export default function LibraryShow({ library, subscription, payments, activity, maxAdjustDays }: Props) {
    const form = useForm({ days: '', reason: '' });
    const what = subscription.plan ? 'this plan' : 'this trial';

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/admin/libraries/${library.id}/subscription`, { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <AdminLayout
            title={library.name}
            actions={
                <Button variant="ghost" asChild>
                    <Link href="/admin">
                        <ArrowLeft className="size-4" /> All libraries
                    </Link>
                </Button>
            }
        >
            <p className="-mt-4 mb-6 text-muted-foreground">
                Library #{library.id} · joined LibriSpace {library.joined}
            </p>

            <div className="grid gap-6 lg:grid-cols-2">
                <Card className="shadow-none">
                    <CardHeader>
                        <CardTitle className="text-lg font-medium">Owner &amp; library</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl>
                            <Detail label="Owner">{library.owner}</Detail>
                            <Detail label="Phone">{library.phone}</Detail>
                            <Detail label="Address">{library.address || '—'}</Detail>
                            <Detail label="Seats">{library.seats}</Detail>
                            <Detail label="Batches">{library.batches}</Detail>
                            <Detail label="Students">
                                {library.activeMembers} active · {library.totalMembers} in total
                            </Detail>
                        </dl>
                    </CardContent>
                </Card>
                <Card className="shadow-none">
                    <CardHeader>
                        <CardTitle className="text-lg font-medium">Subscription</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl>
                            <Detail label="Status">
                                <StateBadge state={subscription.state} label={subscription.stateLabel} />
                            </Detail>
                            <Detail label="Plan">{subscription.plan ?? 'Free trial'}</Detail>
                            <Detail label="Started">{subscription.starts}</Detail>
                            <Detail label="Ends">
                                {subscription.ends}{' '}
                                <span className="text-muted-foreground">
                                    ({subscription.daysLeft >= 0 ? `${subscription.daysLeft} days left` : `${Math.abs(subscription.daysLeft)} days ago`})
                                </span>
                            </Detail>
                            <Detail label="Read-only from">{subscription.readOnlyFrom}</Detail>
                        </dl>
                    </CardContent>
                </Card>
            </div>

            <Card className="mt-6 shadow-none">
                <CardHeader>
                    <CardTitle className="text-lg font-medium">Extend or shorten {what}</CardTitle>
                    <CardDescription>
                        Moves the end date ({subscription.ends}) for this library only. The default trial for new libraries is under Settings.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="grid gap-4 md:grid-cols-[200px_1fr_auto] md:items-end">
                        <div className="grid gap-2">
                            <Label htmlFor="days">Days</Label>
                            <Input
                                id="days"
                                type="number"
                                min={-maxAdjustDays}
                                max={maxAdjustDays}
                                step={1}
                                placeholder="30, or -10 to shorten"
                                value={form.data.days}
                                onChange={(e) => form.setData('days', e.target.value)}
                                aria-invalid={!!form.errors.days}
                                required
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="reason">Reason (for the activity log)</Label>
                            <Input
                                id="reason"
                                maxLength={200}
                                placeholder="e.g. Launch offer, asked for more time"
                                value={form.data.reason}
                                onChange={(e) => form.setData('reason', e.target.value)}
                            />
                        </div>
                        <Button type="submit" disabled={form.processing}>
                            Apply
                        </Button>
                    </form>
                    {form.errors.days && <p className="mt-3 text-sm text-destructive">{form.errors.days}</p>}
                </CardContent>
            </Card>

            <Card className="mt-6 shadow-none">
                <CardHeader>
                    <CardTitle className="text-lg font-medium">Subscription payments</CardTitle>
                </CardHeader>
                <CardContent>
                    {payments.length === 0 ? (
                        <p className="py-4 text-center text-sm text-muted-foreground">No payments yet.</p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Plan</TableHead>
                                    <TableHead className="text-right">Amount</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Invoice</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {payments.map((p) => (
                                    <TableRow key={p.id}>
                                        <TableCell>{p.date}</TableCell>
                                        <TableCell>{p.plan}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatRupee(p.amount)}</TableCell>
                                        <TableCell>{p.status}</TableCell>
                                        <TableCell className="text-muted-foreground">{p.invoice ?? '—'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            <Card className="mt-6 shadow-none">
                <CardHeader>
                    <CardTitle className="text-lg font-medium">Admin changes</CardTitle>
                </CardHeader>
                <CardContent>
                    <ActivityTable rows={activity} showLibrary={false} />
                </CardContent>
            </Card>
        </AdminLayout>
    );
}
