{{-- One line per admin change, in plain words. --}}
@if ($activity->isEmpty())
    <p class="muted" style="margin: 0;">No changes yet.</p>
@else
    <div class="table-wrap">
        <table>
            <thead><tr><th>When</th><th>Who</th>@if ($showLibrary)<th>Library</th>@endif<th>What changed</th></tr></thead>
            <tbody>
            @foreach ($activity as $entry)
                @php($d = $entry->details ?? [])
                <tr>
                    <td style="white-space: nowrap;">{{ $entry->created_at->format('j M Y, g:i A') }}</td>
                    <td>{{ $entry->user?->name ?? 'Deleted admin' }}</td>
                    @if ($showLibrary)
                        <td>@if ($entry->library)<a href="{{ route('admin.libraries.show', $entry->library) }}">{{ $entry->library->library_name }}</a>@else — @endif</td>
                    @endif
                    <td>
                        @switch($entry->action)
                            @case('subscription.adjusted')
                                {{ ($d['days'] ?? 0) > 0 ? 'Extended' : 'Shortened' }} {{ ($d['state_before'] ?? '') === 'trial' ? 'trial' : 'subscription' }} by {{ abs($d['days'] ?? 0) }} days: {{ \Illuminate\Support\Carbon::parse($d['from'])->format('j M Y') }} → {{ \Illuminate\Support\Carbon::parse($d['to'])->format('j M Y') }}
                                @if (! empty($d['reason']))<div class="muted">“{{ $d['reason'] }}”</div>@endif
                                @break
                            @case('settings.trial_days')
                                Free trial for new libraries: {{ $d['from'] }} → {{ $d['to'] }} days
                                @break
                            @case('plan.updated')
                                {{ ucfirst($d['plan'] ?? 'Plan') }} plan:
                                @foreach (['name' => 'name', 'price' => 'price', 'is_active' => 'available'] as $field => $word)
                                    @if (($d['before'][$field] ?? null) != ($d['after'][$field] ?? null))
                                        {{ $word }} {{ $field === 'is_active' ? (($d['before'][$field] ?? false) ? 'yes' : 'no') : $d['before'][$field] }} → {{ $field === 'is_active' ? (($d['after'][$field] ?? false) ? 'yes' : 'no') : $d['after'][$field] }}@if (! $loop->last); @endif
                                    @endif
                                @endforeach
                                @break
                            @default
                                {{ $entry->action }}
                        @endswitch
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
