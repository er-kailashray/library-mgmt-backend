@extends('admin.layout')

@section('title', $library->library_name)

@php
    $labels = ['trial' => 'On free trial', 'active' => 'Paid', 'grace' => 'Grace period', 'expired' => 'Read-only'];
    $daysLeft = (int) now()->startOfDay()->diffInDays($subscription->ends_at->copy()->startOfDay(), false);
@endphp

@section('content')
    <p style="margin: 0 0 12px;"><a href="{{ route('admin.libraries') }}" class="muted" style="text-decoration: none; font-weight: 700;">← All libraries</a></p>
    <h1>{{ $library->library_name }}</h1>
    <p class="subtitle">Library #{{ $library->id }} · joined Librum {{ $library->created_at->format('j M Y') }}</p>

    <div class="row" style="align-items: stretch;">
        <div class="card">
            <h2>Owner & library</h2>
            <dl>
                <dt>Owner</dt><dd>{{ $library->user?->name }}</dd>
                <dt>Phone</dt><dd>{{ $library->user?->phone }}</dd>
                <dt>Address</dt><dd>{{ $library->address ?: '—' }}</dd>
                <dt>Seats</dt><dd>{{ $library->seats_count }}</dd>
                <dt>Batches</dt><dd>{{ $library->batches_count }}</dd>
                <dt>Members</dt><dd>{{ $library->active_members_count }} active · {{ $library->total_members_count }} in total</dd>
            </dl>
        </div>

        <div class="card">
            <h2>Subscription</h2>
            <dl>
                <dt>Status</dt><dd><span class="badge {{ $state->value }}">{{ $labels[$state->value] }}</span></dd>
                <dt>Plan</dt><dd>{{ $subscription->plan?->name ?? 'Free trial' }}</dd>
                <dt>Started</dt><dd>{{ $subscription->starts_at->format('j M Y') }}</dd>
                <dt>Ends</dt><dd>{{ $subscription->ends_at->format('j M Y') }} <span class="muted">({{ $daysLeft >= 0 ? $daysLeft.' days left' : abs($daysLeft).' days ago' }})</span></dd>
                <dt>Read-only from</dt><dd>{{ $graceEndsAt->format('j M Y') }}</dd>
            </dl>
        </div>
    </div>

    <form class="card" method="post" action="{{ route('admin.libraries.adjust', $library) }}">
        @csrf
        <h2>Extend or shorten {{ $subscription->plan ? 'this plan' : 'this trial' }}</h2>
        <div class="row">
            <div>
                <label for="days">Days</label>
                <input id="days" name="days" type="number" min="-{{ $maxAdjustDays }}" max="{{ $maxAdjustDays }}" step="1" value="{{ old('days') }}" placeholder="e.g. 30 to extend, -10 to shorten" required>
            </div>
            <div style="flex: 2;">
                <label for="reason">Reason (for the activity log)</label>
                <input id="reason" name="reason" type="text" maxlength="200" value="{{ old('reason') }}" placeholder="e.g. Launch offer, asked for more time">
            </div>
            <button class="btn shrink" type="submit">Apply</button>
        </div>
        <p class="hint">Moves the end date ({{ $subscription->ends_at->format('j M Y') }}) for this library only. The default trial for new libraries is under Settings.</p>
    </form>

    <div class="card">
        <h2>Subscription payments</h2>
        @if ($payments->isEmpty())
            <p class="muted" style="margin: 0;">No payments yet.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Date</th><th>Plan</th><th>Amount</th><th>Status</th><th>Invoice</th></tr></thead>
                    <tbody>
                    @foreach ($payments as $payment)
                        <tr>
                            <td>{{ ($payment->paid_at ?? $payment->created_at)->format('j M Y') }}</td>
                            <td>{{ $payment->plan?->name }}</td>
                            <td>₹{{ number_format($payment->amount) }}</td>
                            <td>{{ ucfirst($payment->status) }}</td>
                            <td>{{ $payment->invoice_number ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h2>Admin changes</h2>
        @include('admin.partials.activity-list', ['activity' => $activity, 'showLibrary' => false])
    </div>
@endsection
