<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivity;
use App\Models\Library;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Subscriptions\SubscriptionState;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class LibraryController extends Controller
{
    /** How far one adjustment can move a subscription's end date, either way. */
    public const MAX_ADJUST_DAYS = 365;

    private const PER_PAGE = 25;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /** Every library with its owner and subscription status; search by name or phone, filter by status. */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');

        $rows = Library::query()
            ->with(['user', 'subscription.plan'])
            ->withCount(['members as active_members_count' => fn ($query) => $query->where('status', 'active')])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('library_name', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($owner) => $owner->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->get()
            ->map(function (Library $library) {
                $subscription = $this->subscriptions->forLibrary($library);

                return [
                    'library' => $library,
                    'subscription' => $subscription,
                    'state' => $this->subscriptions->stateOf($subscription),
                ];
            });

        $counts = ['all' => $rows->count()] + collect(SubscriptionState::cases())
            ->mapWithKeys(fn (SubscriptionState $state) => [$state->value => $rows->where('state', $state)->count()])
            ->all();

        if ($status !== 'all') {
            $rows = $rows->filter(fn ($row) => $row['state']->value === $status)->values();
        }

        $page = LengthAwarePaginator::resolveCurrentPage();
        $libraries = new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return view('admin.libraries.index', compact('libraries', 'counts', 'search', 'status'));
    }

    public function show(Library $library)
    {
        $library->load(['user'])->loadCount([
            'members as active_members_count' => fn ($query) => $query->where('status', 'active'),
            'members as total_members_count',
            'seats',
            'batches',
        ]);
        $subscription = $this->subscriptions->forLibrary($library);

        return view('admin.libraries.show', [
            'library' => $library,
            'subscription' => $subscription,
            'state' => $this->subscriptions->stateOf($subscription),
            'graceEndsAt' => $this->subscriptions->graceEndsAt($subscription),
            'payments' => $subscription->payments()->with('plan')->latest()->get(),
            'activity' => AdminActivity::with('user')->where('library_id', $library->id)->latest()->limit(20)->get(),
            'maxAdjustDays' => self::MAX_ADJUST_DAYS,
        ]);
    }

    /**
     * Moves the end of this library's trial or plan by a number of days (negative shortens it).
     * Only this library is affected; the default trial for new sign-ups is under Settings.
     */
    public function adjust(Request $request, Library $library)
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'not_in:0', 'between:-'.self::MAX_ADJUST_DAYS.','.self::MAX_ADJUST_DAYS],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $subscription = $this->subscriptions->forLibrary($library);
        $from = $subscription->ends_at->copy();
        $to = $from->copy()->addDays((int) $data['days']);

        if ($to->lte($subscription->starts_at)) {
            return back()->withErrors(['days' => 'That would end it before it started ('.$subscription->starts_at->format('j M Y').').'])->withInput();
        }

        $stateBefore = $this->subscriptions->stateOf($subscription);
        $subscription->update(['ends_at' => $to]);

        AdminActivity::record('subscription.adjusted', $library, [
            'days' => (int) $data['days'],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'state_before' => $stateBefore->value,
            'reason' => $data['reason'] ?? null,
        ]);

        $verb = $data['days'] > 0 ? 'Extended' : 'Shortened';

        return back()->with('status', "{$verb} by ".abs((int) $data['days']).' days. Now ends '.$to->format('j M Y').'.');
    }
}
