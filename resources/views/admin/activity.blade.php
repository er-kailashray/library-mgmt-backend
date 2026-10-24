@extends('admin.layout')

@section('title', 'Activity')

@section('content')
    <h1>Activity</h1>
    <p class="subtitle">Every change made in this admin panel, newest first.</p>

    <div class="card">
        @include('admin.partials.activity-list', ['activity' => $activity, 'showLibrary' => true])
    </div>

    @if ($activity->hasPages())
        <div class="pager">
            <span class="muted">Page {{ $activity->currentPage() }} of {{ $activity->lastPage() }}</span>
            <span class="row" style="flex: 0 0 auto;">
                @if (! $activity->onFirstPage())<a class="btn secondary shrink" href="{{ $activity->previousPageUrl() }}">Newer</a>@endif
                @if ($activity->hasMorePages())<a class="btn secondary shrink" href="{{ $activity->nextPageUrl() }}">Older</a>@endif
            </span>
        </div>
    @endif
@endsection
