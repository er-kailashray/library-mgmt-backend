<?php

namespace App\Services\Subscriptions;

use App\Models\Library;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Carbon\CarbonInterface;

/**
 * Single source of truth for a library's subscription. The state is derived from the dates
 * (never stored), so a subscription moves trial -> grace -> expired on its own as time passes.
 */
class SubscriptionService
{
    public function startTrial(Library $library, ?CarbonInterface $now = null): Subscription
    {
        $now ??= now();

        return Subscription::create([
            'library_id' => $library->id,
            'starts_at' => $now,
            'ends_at' => $now->copy()->addMonthsNoOverflow((int) config('subscription.trial_months')),
        ]);
    }

    /** The library's subscription, starting a trial if it somehow has none yet. */
    public function forLibrary(Library $library): Subscription
    {
        return $library->subscription()->with('plan')->first() ?? $this->startTrial($library)->load('plan');
    }

    public function stateOf(Subscription $subscription, ?CarbonInterface $now = null): SubscriptionState
    {
        $now ??= now();

        if ($now->lte($subscription->ends_at)) {
            return $subscription->plan_id ? SubscriptionState::Active : SubscriptionState::Trial;
        }

        return $now->lte($this->graceEndsAt($subscription)) ? SubscriptionState::Grace : SubscriptionState::Expired;
    }

    public function graceEndsAt(Subscription $subscription): CarbonInterface
    {
        return $subscription->ends_at->copy()->addDays((int) config('subscription.grace_days'));
    }

    /**
     * What the app needs to show the subscription and the plans to choose from.
     *
     * @return array<string, mixed>
     */
    public function present(Library $library, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $subscription = $this->forLibrary($library);
        $state = $this->stateOf($subscription, $now);
        // In grace the countdown is to read-only; otherwise to the end of the trial/plan.
        $countdownTo = $state === SubscriptionState::Grace ? $this->graceEndsAt($subscription) : $subscription->ends_at;

        return [
            'state' => $state->value,
            'can_write' => $state->canWrite(),
            'plan' => $subscription->plan ? $this->presentPlan($subscription->plan) : null,
            'starts_at' => $subscription->starts_at->toIso8601String(),
            'ends_at' => $subscription->ends_at->toIso8601String(),
            'grace_ends_at' => $this->graceEndsAt($subscription)->toIso8601String(),
            'days_left' => max(0, (int) $now->copy()->startOfDay()->diffInDays($countdownTo->copy()->startOfDay(), false)),
            'plans' => SubscriptionPlan::where('is_active', true)->orderBy('duration_months')->get()
                ->map(fn (SubscriptionPlan $plan) => $this->presentPlan($plan))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPlan(SubscriptionPlan $plan): array
    {
        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'price' => $plan->price,
            'duration_months' => $plan->duration_months,
            // Effective monthly price, so the yearly saving can be shown.
            'monthly_equivalent' => round($plan->price / max(1, $plan->duration_months), 2),
        ];
    }
}
