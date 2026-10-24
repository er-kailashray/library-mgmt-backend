@extends('admin.layout')

@section('title', 'Settings')

@section('content')
    <h1>Settings</h1>
    <p class="subtitle">Changes here apply from now on. Every change is recorded under Activity.</p>

    <form class="card" method="post" action="{{ route('admin.settings.trial') }}">
        @csrf
        <h2>Free trial for new libraries</h2>
        <div class="row">
            <div style="max-width: 220px;">
                <label for="trial_days">Trial length (days)</label>
                <input id="trial_days" name="trial_days" type="number" min="{{ $minTrial }}" max="{{ $maxTrial }}" value="{{ old('trial_days', $trialDays) }}" required>
            </div>
            <button class="btn shrink" type="submit">Save</button>
        </div>
        <p class="hint">Currently <strong>{{ $trialDays }} days</strong>. Only libraries that sign up after you save get the new length; trials already running keep theirs. To change one library's trial, open it from Libraries.</p>
    </form>

    <div class="card">
        <h2>Subscription plans</h2>
        @foreach ($plans as $plan)
            <form method="post" action="{{ route('admin.settings.plan', $plan) }}" style="padding: 12px 0; {{ $loop->last ? '' : 'border-bottom: 1px solid var(--border);' }}">
                @csrf
                <div class="row">
                    <div>
                        <label for="name-{{ $plan->id }}">Name</label>
                        <input id="name-{{ $plan->id }}" name="name" type="text" maxlength="50" value="{{ $plan->name }}" required>
                    </div>
                    <div>
                        <label for="price-{{ $plan->id }}">Price (₹ for {{ $plan->duration_months }} {{ $plan->duration_months === 1 ? 'month' : 'months' }})</label>
                        <input id="price-{{ $plan->id }}" name="price" type="number" min="1" step="1" value="{{ (int) $plan->price }}" required>
                    </div>
                    <div class="shrink" style="padding-bottom: 10px;">
                        <label style="display: flex; align-items: center; gap: 8px; text-transform: none; letter-spacing: 0; font-size: 14px;">
                            <input type="checkbox" name="is_active" value="1" {{ $plan->is_active ? 'checked' : '' }} style="width: 18px; height: 18px;"> Available to buy
                        </label>
                    </div>
                    <button class="btn shrink" type="submit">Save</button>
                </div>
            </form>
        @endforeach
        <p class="hint">New prices apply to the next purchase. Libraries already on a plan keep what they paid for until it ends.</p>
    </div>
@endsection
