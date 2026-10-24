<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivity;
use App\Models\SubscriptionPlan;
use App\Services\Settings\AppSettings;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(
        private readonly AppSettings $settings,
    ) {}

    public function edit()
    {
        return view('admin.settings', [
            'trialDays' => $this->settings->trialDays(),
            'plans' => SubscriptionPlan::orderBy('duration_months')->get(),
            'minTrial' => AppSettings::TRIAL_DAYS_MIN,
            'maxTrial' => AppSettings::TRIAL_DAYS_MAX,
        ]);
    }

    /** Free trial for libraries that sign up from now on. Trials already running are not changed. */
    public function updateTrial(Request $request)
    {
        $data = $request->validate([
            'trial_days' => ['required', 'integer', 'between:'.AppSettings::TRIAL_DAYS_MIN.','.AppSettings::TRIAL_DAYS_MAX],
        ]);

        $from = $this->settings->trialDays();
        $this->settings->set(AppSettings::TRIAL_DAYS, (string) $data['trial_days']);
        AdminActivity::record('settings.trial_days', null, ['from' => $from, 'to' => (int) $data['trial_days']]);

        return back()->with('status', "New libraries now get a {$data['trial_days']}-day free trial. Existing trials are unchanged.");
    }

    /** Name, price and whether owners can buy it. Libraries already on the plan keep what they paid for. */
    public function updatePlan(Request $request, SubscriptionPlan $plan)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'price' => ['required', 'numeric', 'min:1', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        $before = $plan->only(['name', 'price', 'is_active']);
        $plan->update($data);
        AdminActivity::record('plan.updated', null, ['plan' => $plan->code, 'before' => $before, 'after' => $plan->only(['name', 'price', 'is_active'])]);

        return back()->with('status', "{$plan->name} plan saved.");
    }
}
