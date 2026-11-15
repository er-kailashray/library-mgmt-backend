import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function AdminLogin() {
    const form = useForm({ phone: '', password: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/admin/login', { onFinish: () => form.reset('password') });
    };

    return (
        <div className="grid min-h-screen lg:grid-cols-2">
            <Head title="Super admin sign in" />
            <div className="relative hidden flex-col justify-between overflow-hidden bg-night p-12 text-[#efe7dc] lg:flex">
                <img src="/brand/librispace-white.svg" alt="LibriSpace" className="h-7 w-auto self-start" />
                <div className="pointer-events-none absolute -right-24 bottom-24 h-96 w-96 rounded-full bg-brand/20 blur-3xl" />
                <div className="relative">
                    <img src="/brand/librispace-symbol-white.svg" alt="" className="mb-8 h-16 w-auto" />
                    <p className="max-w-md font-serif text-4xl leading-tight font-semibold">Every library on LibriSpace, in one place.</p>
                    <p className="mt-4 max-w-md text-[#a99d90]">Subscriptions, trials, plan prices and a record of every change.</p>
                </div>
                <div className="flex items-center gap-2 text-sm text-[#8c8178]">
                    <span>A product of</span>
                    <img src="/brand/kailashray-white.svg" alt="KailashRay" className="h-5 w-auto" />
                </div>
            </div>

            <div className="flex items-center justify-center p-6">
                <div className="w-full max-w-sm">
                    <img src="/brand/librispace.svg" alt="LibriSpace" className="mx-auto mb-8 h-7 w-auto lg:hidden" />
                    <Card className="border-border/80 shadow-sm">
                        <CardHeader>
                            <CardTitle className="font-serif text-2xl font-semibold">Super admin</CardTitle>
                            <CardDescription>For the LibriSpace team only. Library owners sign in from the app.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="grid gap-5">
                                <div className="grid gap-2">
                                    <Label htmlFor="phone">Phone number</Label>
                                    <Input
                                        id="phone"
                                        inputMode="numeric"
                                        autoComplete="username"
                                        autoFocus
                                        placeholder="10-digit mobile number"
                                        value={form.data.phone}
                                        onChange={(e) => form.setData('phone', e.target.value)}
                                        aria-invalid={!!form.errors.phone}
                                        required
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="password">Password</Label>
                                    <Input
                                        id="password"
                                        type="password"
                                        autoComplete="current-password"
                                        value={form.data.password}
                                        onChange={(e) => form.setData('password', e.target.value)}
                                        aria-invalid={!!form.errors.phone}
                                        required
                                    />
                                </div>
                                {(form.errors.phone || form.errors.password) && (
                                    <p className="text-sm text-destructive" role="alert">
                                        {form.errors.phone ?? form.errors.password}
                                    </p>
                                )}
                                <Button type="submit" className="h-10" disabled={form.processing}>
                                    {form.processing && <LoaderCircle className="size-4 animate-spin" />}
                                    Sign in
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                    <p className="mt-6 text-center text-xs text-muted-foreground">
                        Need an account? Ask a team member to run <code className="rounded bg-muted px-1">librispace:make-admin</code>.
                    </p>
                </div>
            </div>
        </div>
    );
}
