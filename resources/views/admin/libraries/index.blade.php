@extends('admin.layout')

@section('title', 'Libraries')

@php
    $labels = ['all' => 'All libraries', 'trial' => 'On free trial', 'active' => 'Paid', 'grace' => 'Grace period', 'expired' => 'Read-only'];
@endphp

@section('content')
    <h1>Libraries</h1>
    <p class="subtitle">Every library on Librum and where its subscription stands. Open one to extend or shorten its trial or plan.</p>

    <div class="stats">
        @foreach ($labels as $key => $label)
            <a class="stat {{ $status === $key ? 'selected' : '' }}" href="{{ route('admin.libraries', array_filter(['status' => $key === 'all' ? null : $key, 'q' => $search ?: null])) }}">
                <div class="num">{{ $counts[$key] ?? 0 }}</div>
                <div class="lbl">{{ $label }}</div>
            </a>
        @endforeach
    </div>

    <form class="card" method="get" action="{{ route('admin.libraries') }}" style="padding: 14px;">
        @if ($status !== 'all')<input type="hidden" name="status" value="{{ $status }}">@endif
        <div class="row">
            <input type="search" name="q" value="{{ $search }}" placeholder="Search by library name, owner name or phone">
            <button class="btn shrink" type="submit">Search</button>
            @if ($search !== '')<a class="btn secondary shrink" href="{{ route('admin.libraries', array_filter(['status' => $status === 'all' ? null : $status])) }}">Clear</a>@endif
        </div>
    </form>

    <div class="card" style="padding: 0;">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Library</th><th>Owner</th><th>Joined</th><th>Members</th><th>Status</th><th>Ends</th></tr>
                </thead>
                <tbody>
                @forelse ($libraries as $row)
                    @php($library = $row['library'])
                    <tr class="link-row" onclick="location.href='{{ route('admin.libraries.show', $library) }}'">
                        <td><a href="{{ route('admin.libraries.show', $library) }}" style="font-weight: 800; text-decoration: none;">{{ $library->library_name }}</a><div class="muted">#{{ $library->id }}</div></td>
                        <td>{{ $library->user?->name }}<div class="muted">{{ $library->user?->phone }}</div></td>
                        <td>{{ $library->created_at->format('j M Y') }}</td>
                        <td>{{ $library->active_members_count }}</td>
                        <td>
                            <span class="badge {{ $row['state']->value }}">{{ $labels[$row['state']->value] }}</span>
                            @if ($row['subscription']->plan)<div class="muted">{{ $row['subscription']->plan->name }}</div>@endif
                        </td>
                        <td>{{ $row['subscription']->ends_at->format('j M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted" style="padding: 24px; text-align: center;">No libraries match.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($libraries->hasPages())
        <div class="pager">
            <span class="muted">Page {{ $libraries->currentPage() }} of {{ $libraries->lastPage() }}</span>
            <span class="row" style="flex: 0 0 auto;">
                @if (! $libraries->onFirstPage())<a class="btn secondary shrink" href="{{ $libraries->previousPageUrl() }}">Previous</a>@endif
                @if ($libraries->hasMorePages())<a class="btn secondary shrink" href="{{ $libraries->nextPageUrl() }}">Next</a>@endif
            </span>
        </div>
    @endif
@endsection
