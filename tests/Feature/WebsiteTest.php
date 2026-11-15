<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_shows_only_plans_on_sale_with_their_saving(): void
    {
        SubscriptionPlan::query()->delete();
        SubscriptionPlan::create(['code' => 'monthly', 'name' => 'Monthly', 'price' => 300, 'duration_months' => 1, 'is_active' => true]);
        SubscriptionPlan::create(['code' => 'yearly', 'name' => 'Yearly', 'price' => 2700, 'duration_months' => 12, 'is_active' => true]);
        SubscriptionPlan::create(['code' => 'old', 'name' => 'Old', 'price' => 500, 'duration_months' => 3, 'is_active' => false]);

        $this->get('/')->assertOk()
            ->assertSee('<meta name="description"', false)
            ->assertInertia(fn (Assert $page) => $page->component('landing')
                ->has('plans', 2)
                ->where('plans.1.name', 'Yearly')
                ->where('plans.1.perMonth', 225)
                ->where('plans.1.saving', 25)
                ->has('trialDays'));
    }

    public function test_legal_and_contact_pages_render(): void
    {
        foreach (['terms', 'privacy', 'refunds', 'contact'] as $page) {
            $this->get("/{$page}")->assertOk()->assertInertia(fn (Assert $p) => $p->component("legal/{$page}")->has('business'));
        }
    }

    public function test_unknown_pages_are_not_found_and_sitemap_lists_the_site(): void
    {
        $this->get('/pricing-old')->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertSee('/privacy', false);
    }

    public function test_landing_page_html_carries_title_and_structured_data_for_search_engines(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<title>Study library management app for India · LibriSpace</title>', false)
            ->assertSee('application/ld+json', false)
            ->assertSee('"@type":"FAQPage"', false)
            ->assertSee('"@type":"SoftwareApplication"', false)
            ->assertSee('og:image', false);
    }

    public function test_robots_points_to_the_sitemap_and_hides_the_admin_panel(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /admin')->assertSee('Sitemap: '.url('/sitemap.xml'));
        $this->get('/admin/login')->assertSee('noindex', false);
    }
}
