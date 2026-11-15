import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { AdminLayout } from '@/layouts/admin-layout';

type Plan = { id: number; name: string; price: number; months: number; isActive: boolean };

export default function Settings({ trialDays, plans, minTrial, maxTrial }: { trialDays: number; plans: Plan[]; minTrial: number; maxTrial: number }) {
    const trial = useForm({ trial_days: String(trialDays) });

    const saveTrial = (e: FormEvent) => {
        e.preventDefault();
        trial.post('/admin/settings/trial', { preserveScroll: true });
    };

    return (
        <AdminLayout title="Settings">
            <p className="-mt-4 mb-6 text-muted-foreground">Changes here apply from now on. Every change is recorded under Activity.</p>

            <Card className="shadow-none">
                <CardHeader>
                    <CardTitle className="text-lg font-medium">Free trial for new libraries</CardTitle>
                    <CardDescription>
                        Currently <strong className="font-medium text-foreground">{trialDays} days</strong>. Only libraries that sign up after you save get the new length; trials already
                        running keep theirs. To change one library’s trial, open it from the Dashboard.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={saveTrial} className="flex flex-wrap items-end gap-3">
                        <div className="grid w-48 gap-2">
                            <Label htmlFor="trial_days">Trial length (days)</Label>
                            <Input
                                id="trial_days"
                                type="number"
                                min={minTrial}
                                max={maxTrial}
                                value={trial.data.trial_days}
                                onChange={(e) => trial.setData('trial_days', e.target.value)}
                                aria-invalid={!!trial.errors.trial_days}
                                required
                            />
                        </div>
                        <Button type="submit" disabled={trial.processing}>
                            Save
                        </Button>
                    </form>
                    {trial.errors.trial_days && <p className="mt-3 text-sm text-destructive">{trial.errors.trial_days}</p>}
                </CardContent>
            </Card>

            <Card className="mt-6 shadow-none">
                <CardHeader>
                    <CardTitle className="text-lg font-medium">Subscription plans</CardTitle>
                    <CardDescription>New prices apply to the next purchase. Libraries already on a plan keep what they paid for until it ends.</CardDescription>
                </CardHeader>
                <CardContent className="divide-y divide-border">
                    {plans.map((plan) => (
                        <PlanRow key={plan.id} plan={plan} />
                    ))}
                </CardContent>
            </Card>
        </AdminLayout>
    );
}

function PlanRow({ plan }: { plan: Plan }) {
    const form = useForm({ name: plan.name, price: String(plan.price), is_active: plan.isActive });

    const save = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/admin/settings/plans/${plan.id}`, { preserveScroll: true });
    };

    return (
        <form onSubmit={save} className="grid gap-4 py-5 first:pt-0 last:pb-0 md:grid-cols-[1fr_1fr_auto_auto] md:items-end">
            <div className="grid gap-2">
                <Label htmlFor={`name-${plan.id}`}>Name</Label>
                <Input id={`name-${plan.id}`} maxLength={50} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`price-${plan.id}`}>
                    Price (₹ for {plan.months} {plan.months === 1 ? 'month' : 'months'})
                </Label>
                <Input
                    id={`price-${plan.id}`}
                    type="number"
                    min={1}
                    step={1}
                    value={form.data.price}
                    onChange={(e) => form.setData('price', e.target.value)}
                    aria-invalid={!!form.errors.price}
                    required
                />
            </div>
            <label className="flex h-9 items-center gap-2 text-sm">
                <Switch checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                Available to buy
            </label>
            <Button type="submit" variant="outline" disabled={form.processing || !form.isDirty}>
                Save
            </Button>
            {(form.errors.name || form.errors.price) && <p className="text-sm text-destructive md:col-span-4">{form.errors.name ?? form.errors.price}</p>}
        </form>
    );
}
