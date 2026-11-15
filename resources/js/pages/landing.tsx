import { Head, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    BellRing,
    CalendarCheck,
    Check,
    IndianRupee,
    LayoutGrid,
    MessageSquareText,
    ShieldCheck,
    Smartphone,
    UserPlus,
} from 'lucide-react';

import { SiteFooter } from '@/components/site/site-footer';
import { SiteHeader, startTrialUrl } from '@/components/site/site-header';
import { StoreButtons } from '@/components/site/store-buttons';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { formatRupee } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

type Plan = {
    id: number;
    name: string;
    price: number;
    months: number;
    perMonth: number;
    saving: number;
    fullPrice: number;
    savedRupees: number;
    perDay: number;
    badge: string | null;
};

const INCLUDED = ['Every feature', 'Unlimited students and seats', 'SMS and WhatsApp reminders', 'Attendance and reports', 'Free updates and support'];

const FEATURES = [
    {
        icon: LayoutGrid,
        title: 'Every seat, in every batch',
        body: 'See which seats are free in the morning, evening or full-day batch at a glance. One seat can be shared by different students in different batches, and you can block a seat for repairs.',
    },
    {
        icon: UserPlus,
        title: 'Admission in a minute',
        body: 'Add a student with their phone number, pick a batch and a free seat, take a photo of their ID proof and record the first fee, all in one form.',
    },
    {
        icon: IndianRupee,
        title: 'Fees and dues, settled',
        body: 'Record cash or UPI payments, full or partial. LibriSpace works out who owes what and since when, so nobody slips through.',
    },
    {
        icon: MessageSquareText,
        title: 'Renewals and reminders',
        body: 'See whose month ends this week and send a reminder by SMS or WhatsApp in one tap, before the fee falls due.',
    },
    {
        icon: CalendarCheck,
        title: 'Daily attendance',
        body: 'A check-in list for each batch, every day. Spot students who haven’t come in for a week before they quietly leave.',
    },
    {
        icon: BarChart3,
        title: 'Reports that add up',
        body: 'Revenue by month and payment mode, collection rate, dues by age and occupancy per batch, for the last 3, 6 or 12 months.',
    },
];

const STEPS = [
    { title: 'Set up your library', body: 'Enter your seats and batch timings with their monthly fees. It takes about five minutes.' },
    { title: 'Admit your students', body: 'Add each student to a batch and a seat. Existing students can be added with their joining date.' },
    { title: 'Collect and relax', body: 'Record fees as they come in. LibriSpace tracks renewals, dues and attendance from then on.' },
];


export default function Landing({ trialDays, plans, faq }: { trialDays: number; plans: Plan[]; faq: { q: string; a: string }[] }) {
    const { links } = usePage<SharedProps>().props;
    const trialUrl = startTrialUrl(links.webApp);

    return (
        <>
            <Head title="Study library management app for India" />
            <SiteHeader />

            <main>
                {/* Hero */}
                <section className="relative overflow-hidden">
                    <div className="pointer-events-none absolute -top-40 right-[-10%] h-[520px] w-[520px] rounded-full bg-sand/70 blur-3xl" />
                    <div className="relative mx-auto grid max-w-6xl items-center gap-14 px-4 pt-14 pb-20 sm:px-6 md:grid-cols-[1.1fr_1fr] md:pt-20 md:pb-28">
                        <div>
                            <Badge variant="outline" className="mb-6 gap-1.5 border-brand/30 bg-white/70 px-3 py-1 text-[13px] font-normal text-foreground/80">
                                <span className="size-1.5 rounded-full bg-brand" /> {trialDays}-day free trial · no card needed
                            </Badge>
                            <h1 className="font-serif text-[44px] leading-[1.02] font-semibold tracking-tight text-balance sm:text-6xl md:text-[68px]">
                                Run your study library from one app.
                            </h1>
                            <p className="mt-6 max-w-xl text-lg leading-relaxed text-foreground/75">
                                Seats, batches, admissions, fees and attendance, without registers or reminders in your head. Built for self-study libraries and reading rooms in India.
                            </p>
                            <div className="mt-9 flex flex-col gap-3 sm:flex-row">
                                <Button size="lg" className="h-12 px-6 text-base" asChild>
                                    <a href={trialUrl}>
                                        Start {trialDays}-day free trial <ArrowRight className="size-4" />
                                    </a>
                                </Button>
                                <Button size="lg" variant="outline" className="h-12 bg-white/60 px-6 text-base" asChild>
                                    <a href="#how">See how it works</a>
                                </Button>
                            </div>
                            <StoreButtons className="mt-6" />
                            <ul className="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-foreground/65">
                                {['Works on any phone', 'Your data stays yours', 'Prices in ₹, pay by UPI'].map((t) => (
                                    <li key={t} className="flex items-center gap-1.5">
                                        <Check className="size-4 text-brand" /> {t}
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <HeroVisual />
                    </div>
                </section>

                {/* Before / after */}
                <section className="border-y border-border bg-white">
                    <div className="mx-auto grid max-w-6xl gap-10 px-4 py-16 sm:px-6 md:grid-cols-2 md:py-20">
                        <div>
                            <p className="text-xs font-medium tracking-[0.16em] text-muted-foreground uppercase">Today</p>
                            <h2 className="mt-3 font-serif text-3xl font-semibold sm:text-4xl">A register, a WhatsApp group and your memory.</h2>
                            <p className="mt-4 leading-relaxed text-foreground/70">
                                Which seat is free in the evening batch? Who paid half last month? Whose month ended on Sunday? The answers are scattered, and every missed renewal is money lost.
                            </p>
                        </div>
                        <div className="rounded-2xl bg-night p-8 text-[#efe7dc]">
                            <p className="text-xs font-medium tracking-[0.16em] text-brand uppercase">With LibriSpace</p>
                            <h2 className="mt-3 font-serif text-3xl font-semibold sm:text-4xl">Every seat, due fee and renewal on one screen.</h2>
                            <p className="mt-4 leading-relaxed text-[#bdb2a6]">
                                Open the app in the morning and see today’s renewals, pending dues and free seats. Each payment you record moves the student’s next renewal date for you.
                            </p>
                        </div>
                    </div>
                </section>

                {/* Features */}
                <section id="features" className="scroll-mt-20">
                    <div className="mx-auto max-w-6xl px-4 py-20 sm:px-6 md:py-28">
                        <SectionHeading eyebrow="Features" title="Everything a study library runs on" />
                        <div className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {FEATURES.map(({ icon: Icon, title, body }) => (
                                <Card key={title} className="gap-4 border-border/80 bg-white/80 shadow-none transition-shadow hover:shadow-md">
                                    <CardHeader className="gap-4">
                                        <span className="grid size-11 place-items-center rounded-lg bg-night text-[#f3ece2]">
                                            <Icon className="size-5" strokeWidth={1.6} />
                                        </span>
                                        <h3 className="text-lg leading-snug font-medium">{title}</h3>
                                    </CardHeader>
                                    <CardContent className="text-[15px] leading-relaxed text-foreground/70">{body}</CardContent>
                                </Card>
                            ))}
                        </div>
                        <div className="mt-5 grid gap-5 md:grid-cols-2">
                            <Card className="flex-row items-center gap-5 border-border/80 bg-white/80 p-6 shadow-none">
                                <BellRing className="size-6 shrink-0 text-brand" strokeWidth={1.6} />
                                <p className="text-[15px] leading-relaxed text-foreground/75">
                                    <strong className="font-medium text-foreground">Morning summary.</strong> A notification at 9 am with today’s renewals and pending dues, and a heads-up before each batch starts.
                                </p>
                            </Card>
                            <Card className="flex-row items-center gap-5 border-border/80 bg-white/80 p-6 shadow-none">
                                <ShieldCheck className="size-6 shrink-0 text-brand" strokeWidth={1.6} />
                                <p className="text-[15px] leading-relaxed text-foreground/75">
                                    <strong className="font-medium text-foreground">Safe by design.</strong> Encrypted connections, private ID proofs, and a payment is never recorded twice even if the network drops.
                                </p>
                            </Card>
                        </div>
                    </div>
                </section>

                {/* How it works */}
                <section id="how" className="scroll-mt-20 bg-sand/45">
                    <div className="mx-auto max-w-6xl px-4 py-20 sm:px-6 md:py-28">
                        <SectionHeading eyebrow="How it works" title="Up and running in an afternoon" />
                        <ol className="mt-14 grid gap-8 md:grid-cols-3">
                            {STEPS.map((step, i) => (
                                <li key={step.title} className="relative">
                                    <span className="font-serif text-6xl font-semibold text-brand/80">{i + 1}</span>
                                    <h3 className="mt-2 text-xl font-medium">{step.title}</h3>
                                    <p className="mt-2 leading-relaxed text-foreground/70">{step.body}</p>
                                </li>
                            ))}
                        </ol>
                    </div>
                </section>

                {/* Student app */}
                <section className="bg-white">
                    <div className="mx-auto grid max-w-6xl items-center gap-12 px-4 py-20 sm:px-6 md:grid-cols-[auto_1fr] md:py-24">
                        <div className="flex items-end gap-4">
                            <img src="/brand/librispace-icon.png" alt="LibriSpace app icon" width={112} height={112} className="size-24 rounded-[22%] shadow-lg sm:size-28" />
                            <img src="/brand/mystudyhall-icon.png" alt="My Study Hall app icon" width={84} height={84} className="size-16 rounded-[22%] shadow-lg sm:size-20" />
                        </div>
                        <div>
                            <Badge variant="secondary" className="mb-4 font-normal">Coming soon</Badge>
                            <h2 className="font-serif text-3xl font-semibold sm:text-4xl">My Study Hall, the app for your students</h2>
                            <p className="mt-4 max-w-2xl leading-relaxed text-foreground/70">
                                Your students will see their seat, batch timing, valid-till date and attendance on their own phone, and pay their next month online. Fewer calls asking “when is my fee due?”.
                            </p>
                        </div>
                    </div>
                </section>

                {/* Pricing: ₹999/month is the anchor; longer plans show their per-month price, the struck-through
                    monthly total and the rupees saved, so 6 months and yearly look like the obvious choice. */}
                <section id="pricing" className="scroll-mt-20">
                    <div className="mx-auto max-w-6xl px-4 py-20 sm:px-6 md:py-28">
                        <SectionHeading
                            eyebrow="Pricing"
                            title="Pay less the longer you stay"
                            body={`Try everything free for ${trialDays} days, no card needed. Every plan includes every feature, and the longer the plan, the less you pay each month.`}
                        />
                        {plans.length > 0 ? (
                            <div className={cn('mx-auto mt-16 grid gap-5 sm:grid-cols-2', plans.length >= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3')}>
                                {plans.map((plan) => {
                                    const popular = plan.badge === 'Most popular';
                                    const bestValue = plan.badge === 'Best value';
                                    return (
                                        <Card
                                            key={plan.id}
                                            className={cn(
                                                'relative gap-0 py-0 shadow-none transition-shadow hover:shadow-lg',
                                                popular && 'border-night bg-night text-[#f3ece2] shadow-xl lg:-my-3',
                                                bestValue && 'border-2 border-brand bg-white',
                                                !popular && !bestValue && 'border-border/80 bg-white/80',
                                            )}
                                        >
                                            {plan.badge && (
                                                <Badge className={cn('absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-1 text-xs', popular ? 'bg-brand text-white' : 'bg-night text-white')}>
                                                    {plan.badge}
                                                </Badge>
                                            )}
                                            <div className={cn('flex flex-1 flex-col p-6', popular && 'lg:py-9')}>
                                                <div className="flex items-center justify-between gap-2">
                                                    <h3 className="text-lg font-medium">{plan.name}</h3>
                                                    {plan.saving > 0 && (
                                                        <span
                                                            className={cn(
                                                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                                                popular ? 'bg-white/10 text-[#f3c9a8]' : 'bg-emerald-50 text-emerald-700',
                                                            )}
                                                        >
                                                            {plan.saving}% off
                                                        </span>
                                                    )}
                                                </div>

                                                <div className="mt-5 flex items-baseline gap-1">
                                                    <span className="font-serif text-5xl font-semibold tracking-tight [font-variant-numeric:lining-nums]">{formatRupee(plan.perMonth)}</span>
                                                    <span className={cn('text-sm', popular ? 'text-[#bdb2a6]' : 'text-muted-foreground')}>/month</span>
                                                </div>

                                                <p className={cn('mt-2 min-h-10 text-sm', popular ? 'text-[#bdb2a6]' : 'text-muted-foreground')}>
                                                    {plan.months === 1 ? (
                                                        'Billed every month'
                                                    ) : (
                                                        <>
                                                            <span className="line-through opacity-70">{formatRupee(plan.fullPrice)}</span>{' '}
                                                            <span className={cn('font-medium', popular ? 'text-white' : 'text-foreground')}>{formatRupee(plan.price)}</span> for{' '}
                                                            {plan.months} months, paid once
                                                        </>
                                                    )}
                                                </p>

                                                <div className="mt-5 min-h-12 space-y-1 text-sm">
                                                    {plan.savedRupees > 0 && (
                                                        <p className={cn('flex items-center gap-1.5 font-medium', popular ? 'text-[#f3c9a8]' : 'text-emerald-700')}>
                                                            <Check className="size-4" /> You save {formatRupee(plan.savedRupees)}
                                                        </p>
                                                    )}
                                                    {bestValue && (
                                                        <p className="flex items-center gap-1.5 text-foreground/70">
                                                            <Check className="size-4 text-brand" /> Under ₹{plan.perDay} a day
                                                        </p>
                                                    )}
                                                    {plan.months === 1 && <p className="text-muted-foreground">Most flexible, highest price</p>}
                                                </div>

                                                <Button
                                                    className={cn('mt-6 w-full', popular && 'bg-brand text-white hover:bg-brand/90')}
                                                    variant={popular || bestValue ? 'default' : 'outline'}
                                                    asChild
                                                >
                                                    <a href={trialUrl}>{popular || bestValue ? `Start free, then ${plan.name.toLowerCase()}` : 'Start free trial'}</a>
                                                </Button>
                                            </div>
                                        </Card>
                                    );
                                })}
                            </div>
                        ) : (
                            <p className="mt-10 text-center text-muted-foreground">Plans are being updated. Start your free trial today.</p>
                        )}

                        <div className="mx-auto mt-12 flex max-w-4xl flex-wrap justify-center gap-x-6 gap-y-2 text-[15px] text-foreground/75">
                            <span className="w-full text-center text-xs font-medium tracking-[0.16em] text-muted-foreground uppercase">Every plan includes</span>
                            {INCLUDED.map((item) => (
                                <span key={item} className="flex items-center gap-1.5">
                                    <Check className="size-4 text-brand" /> {item}
                                </span>
                            ))}
                        </div>
                        <p className="mt-6 text-center text-sm text-muted-foreground">
                            Prices in Indian rupees. Pay by UPI, card or net banking. Plans don’t renew automatically, so there’s nothing to cancel.
                        </p>
                    </div>
                </section>

                {/* FAQ */}
                <section id="faq" className="scroll-mt-20 border-t border-border bg-white">
                    <div className="mx-auto grid max-w-6xl gap-10 px-4 py-20 sm:px-6 md:grid-cols-[1fr_1.6fr] md:py-28">
                        <div>
                            <SectionHeading eyebrow="FAQ" title="Questions owners ask" align="left" />
                            <p className="mt-4 text-foreground/70">
                                Something else? <a href="/contact" className="text-brand underline underline-offset-4">Contact us</a>.
                            </p>
                        </div>
                        <Accordion type="single" collapsible className="w-full">
                            {faq.map((item) => (
                                <AccordionItem key={item.q} value={item.q}>
                                    <AccordionTrigger className="text-left text-base font-medium">{item.q}</AccordionTrigger>
                                    <AccordionContent className="text-[15px] leading-relaxed text-foreground/70">{item.a}</AccordionContent>
                                </AccordionItem>
                            ))}
                        </Accordion>
                    </div>
                </section>

                {/* Final call to action */}
                <section className="bg-night">
                    <div className="mx-auto flex max-w-6xl flex-col items-center px-4 py-20 text-center sm:px-6 md:py-24">
                        <img src="/brand/librispace-symbol-white.svg" alt="" className="h-14 w-auto" />
                        <h2 className="mt-6 max-w-2xl font-serif text-4xl font-semibold text-[#f3ece2] sm:text-5xl">Give your library the calm it deserves.</h2>
                        <p className="mt-4 text-[#bdb2a6]">{trialDays} days free. Set up in an afternoon.</p>
                        <Button size="lg" className="mt-8 h-12 bg-brand px-7 text-base text-white hover:bg-brand/90" asChild>
                            <a href={trialUrl}>
                                Start {trialDays}-day free trial <ArrowRight className="size-4" />
                            </a>
                        </Button>
                        <StoreButtons tone="dark" className="mt-8 items-center text-center" />
                    </div>
                </section>
            </main>

            <SiteFooter />
        </>
    );
}

function SectionHeading({ eyebrow, title, body, align = 'center' }: { eyebrow: string; title: string; body?: string; align?: 'center' | 'left' }) {
    return (
        <div className={cn(align === 'center' && 'mx-auto max-w-2xl text-center')}>
            <p className="text-xs font-medium tracking-[0.16em] text-brand uppercase">{eyebrow}</p>
            <h2 className="mt-3 font-serif text-4xl font-semibold tracking-tight text-balance sm:text-5xl">{title}</h2>
            {body && <p className="mt-4 text-lg leading-relaxed text-foreground/70">{body}</p>}
        </div>
    );
}

/** A drawn phone showing the owner's home screen (illustration, not a screenshot), with a student card beside it. */
function HeroVisual() {
    return (
        <div className="relative mx-auto w-full max-w-[380px]" aria-hidden="true">
            <div className="relative mx-auto w-[290px] rounded-[44px] border-[10px] border-[#0e0d0c] bg-[#f6f2ec] shadow-2xl">
                <div className="px-5 pt-4 pb-6">
                    <div className="flex items-center justify-between text-[11px] font-semibold">
                        <span>9:41</span>
                        <span className="h-2 w-10 rounded-full bg-[#0e0d0c]/80" />
                    </div>
                    <div className="mt-4 flex items-center justify-between">
                        <img src="/brand/librispace.svg" alt="" className="h-[18px] w-auto" />
                        <span className="grid size-8 place-items-center rounded-full bg-sand text-[11px] font-semibold">SR</span>
                    </div>
                    <p className="mt-4 text-xl font-medium">Good morning</p>
                    <p className="text-[12px] text-muted-foreground">Sunrise Study Library</p>
                    <div className="mt-4 grid grid-cols-2 gap-2">
                        <div className="rounded-xl bg-night p-3 text-white">
                            <p className="text-[10px] text-[#bfb3a6]">Collected today</p>
                            <p className="text-lg font-semibold">₹12,400</p>
                        </div>
                        <div className="rounded-xl border border-border bg-white p-3">
                            <p className="text-[10px] text-muted-foreground">Seats filled</p>
                            <p className="text-lg font-semibold">86 / 120</p>
                        </div>
                    </div>
                    <p className="mt-4 mb-1 text-[12px] font-semibold">Renewals this week</p>
                    {[
                        ['Aman V.', 'Today', '₹900'],
                        ['Priya S.', 'Tomorrow', '₹1,200'],
                        ['Rohit K.', 'Fri', '₹900'],
                    ].map(([name, when, amount]) => (
                        <div key={name} className="flex items-center gap-2.5 border-b border-border py-2.5 text-[13px]">
                            <span className="size-7 rounded-full bg-sand" />
                            <span className="flex-1">
                                {name}
                                <span className="block text-[10px] text-muted-foreground">{when}</span>
                            </span>
                            <span className="font-semibold text-brand">{amount}</span>
                        </div>
                    ))}
                    <div className="mt-4 flex justify-around text-[10px] text-muted-foreground">
                        <span className="font-semibold text-brand">Home</span>
                        <span>Members</span>
                        <span>Payments</span>
                        <span>Reports</span>
                    </div>
                </div>
            </div>
            <div className="absolute -bottom-14 -left-2 w-48 rounded-2xl border border-border bg-white p-4 shadow-xl sm:-left-8">
                <p className="text-[10px] text-muted-foreground">Seat</p>
                <p className="text-2xl font-medium tracking-wide">A-14</p>
                <p className="text-[11px] text-muted-foreground">Morning · 6:00 – 12:00</p>
                <div className="mt-2 flex items-center gap-1.5 text-[11px] font-medium text-emerald-700">
                    <Check className="size-3.5" /> Paid till 30 Nov
                </div>
            </div>
            <div className="absolute top-28 -right-4 hidden items-center gap-2 rounded-full border border-border bg-white px-3 py-2 text-[12px] shadow-lg sm:flex">
                <Smartphone className="size-4 text-brand" /> Reminder sent
            </div>
        </div>
    );
}
