<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use App\Services\Settings\AppSettings;
use Inertia\Inertia;

/** The public website: LibriSpace landing page and the pages a release and Razorpay need. */
class SiteController extends Controller
{
    /** Date the legal pages were last changed (shown on each page). */
    public const LEGAL_UPDATED = '15 November 2026';

    /** Landing page FAQ: shown on the page and sent to search engines as FAQPage structured data. */
    public const FAQ = [
        ['q' => 'Does it work on my phone?', 'a' => 'Yes. LibriSpace is made for phones. Android owners use the app; on iPhone, open the web app in Safari and add it to your home screen. It also works on a laptop browser.'],
        ['q' => 'What happens when the free trial ends?', 'a' => 'You choose a plan and pay online by UPI, card or net banking. If you don’t, you get a few extra days, then your library becomes read-only: nothing is deleted, and everything unlocks again as soon as you subscribe.'],
        ['q' => 'Is my students’ data safe?', 'a' => 'Your library’s data is visible only to you. It travels over an encrypted connection, ID proof photos are stored privately and are never public, and we never sell or share your data for advertising.'],
        ['q' => 'I already have students. Do I start from scratch?', 'a' => 'No. Add your current students with the date they joined and their batch, and LibriSpace picks up their renewals from there.'],
        ['q' => 'Can one seat be used in different batches?', 'a' => 'Yes. A seat can have one student in the morning batch and another in the evening batch. LibriSpace only shows a seat as taken in the batches where it actually is.'],
        ['q' => 'Can I cancel any time?', 'a' => 'Plans are paid in advance and don’t renew automatically, so there is nothing to cancel: if you don’t renew, your plan simply ends. See our Cancellation and Refund Policy for details.'],
    ];

    private const PAGES = [
        'terms' => ['Terms and Conditions', 'The terms for using LibriSpace, the study library management app by KailashRay.'],
        'privacy' => ['Privacy Policy', 'What LibriSpace collects, why, who it is shared with and how long it is kept.'],
        'refunds' => ['Cancellation and Refund Policy', 'How LibriSpace plans renew, how to cancel and when refunds are given.'],
        'contact' => ['Contact us', 'Reach the LibriSpace team for help, billing or privacy questions.'],
    ];

    public function __construct(
        private readonly AppSettings $settings,
    ) {}

    public function home()
    {
        $plans = SubscriptionPlan::where('is_active', true)->orderBy('duration_months')->get();
        $monthly = $plans->firstWhere('duration_months', 1);
        $trialDays = $this->settings->trialDays();
        $description = "Study library management app for owners in India: seats, batches, admissions, fees, renewals and attendance in one place. {$trialDays}-day free trial.";

        $jsonLd = [
            [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => 'KailashRay',
                'url' => url('/'),
                'logo' => url('/brand/librispace-icon.png'),
                'email' => config('library.support_email'),
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'SoftwareApplication',
                'name' => 'LibriSpace',
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'Android, iOS (web app), Web',
                'description' => $description,
                'url' => url('/'),
                'image' => url('/brand/og-image.png'),
                'publisher' => ['@type' => 'Organization', 'name' => 'KailashRay'],
                'offers' => $plans->map(fn (SubscriptionPlan $plan) => [
                    '@type' => 'Offer',
                    'name' => $plan->name,
                    'price' => (string) (int) $plan->price,
                    'priceCurrency' => 'INR',
                ])->values()->all(),
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn ($item) => [
                    '@type' => 'Question',
                    'name' => $item['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
                ], self::FAQ),
            ],
        ];

        return Inertia::render('landing', [
            'trialDays' => $trialDays,
            'faq' => self::FAQ,
            'plans' => $plans->map(fn (SubscriptionPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => (int) $plan->price,
                'months' => $plan->duration_months,
                'perMonth' => (int) round($plan->price / max(1, $plan->duration_months)),
                // What the same period costs on the monthly plan (shown struck through) and the rupees saved.
                'fullPrice' => $monthly ? (int) ($monthly->price * $plan->duration_months) : (int) $plan->price,
                'savedRupees' => $monthly ? max(0, (int) ($monthly->price * $plan->duration_months - $plan->price)) : 0,
                'perDay' => (int) ceil($plan->price / max(1, $plan->duration_months * 30.4)),
                'badge' => config('library.plan_badges')[$plan->duration_months] ?? null,
                'saving' => $monthly && $plan->duration_months > 1
                    ? max(0, (int) round(100 - ($plan->price / $plan->duration_months) / $monthly->price * 100))
                    : 0,
            ])->values(),
        ])->withViewData([
            'meta' => [
                'title' => 'Study library management app for India · LibriSpace',
                'description' => $description,
                'url' => url('/'),
            ],
            'jsonLd' => $jsonLd,
        ]);
    }

    public function page(string $page)
    {
        [$title, $description] = self::PAGES[$page];

        return Inertia::render("legal/{$page}", [
            'updated' => self::LEGAL_UPDATED,
            'business' => [
                'name' => config('library.business_name'),
                'address' => config('library.business_address'),
                'email' => config('library.support_email'),
                'phone' => config('library.support_phone'),
                'jurisdiction' => config('library.business_jurisdiction'),
            ],
            'trialDays' => $this->settings->trialDays(),
        ])->withViewData(['meta' => [
            'title' => "{$title} · LibriSpace",
            'description' => $description,
            'url' => url("/{$page}"),
        ]]);
    }

    public function sitemap()
    {
        $urls = collect(['' => '1.0', ...array_fill_keys(array_keys(self::PAGES), '0.4')])
            ->map(fn ($priority, $path) => '<url><loc>'.e(url('/'.$path)).'</loc><priority>'.$priority.'</priority></url>')
            ->implode('');

        return response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>')
            ->header('Content-Type', 'application/xml');
    }

    /** robots.txt with the sitemap's full address (it must be absolute, so it can't be a static file). */
    public function robots()
    {
        return response("User-agent: *\nDisallow: /admin\nDisallow: /subscription/\n\nSitemap: ".url('/sitemap.xml')."\n")
            ->header('Content-Type', 'text/plain');
    }
}
