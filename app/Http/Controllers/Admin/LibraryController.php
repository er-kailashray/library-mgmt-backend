<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivity;
use App\Models\Library;
use App\Models\Member;
use App\Models\SubscriptionPayment;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Subscriptions\SubscriptionState;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;

class LibraryController extends Controller
{
    /** How far one adjustment can move a subscription's end date, either way. */
    public const MAX_ADJUST_DAYS = 365;

    private const PER_PAGE = 25;

    /** Status names as the admin panel shows them. */
    public const STATE_LABELS = [
        'trial' => 'On free trial',
        'active' => 'Paid',
        'grace' => 'Grace period',
        'expired' => 'Read-only',
    ];

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

        $paid = SubscriptionPayment::where('status', 'paid');

        return Inertia::render('admin/dashboard', [
            'counts' => $counts,
            'totals' => [
                'activeMembers' => Member::where('status', 'active')->count(),
                'newThisMonth' => Library::where('created_at', '>=', now()->startOfMonth())->count(),
                'revenueThisMonth' => (int) (clone $paid)->where('paid_at', '>=', now()->startOfMonth())->sum('amount'),
                'revenueTotal' => (int) (clone $paid)->sum('amount'),
            ],
            'search' => $search,
            'status' => $status,
            'libraries' => [
                'rows' => $libraries->getCollection()->map(fn ($row) => [
                    'id' => $row['library']->id,
                    'name' => $row['library']->library_name,
                    'owner' => $row['library']->user?->name,
                    'phone' => $row['library']->user?->phone,
                    'joined' => $row['library']->created_at->format('j M Y'),
                    'members' => $row['library']->active_members_count,
                    'state' => $row['state']->value,
                    'stateLabel' => self::STATE_LABELS[$row['state']->value],
                    'plan' => $row['subscription']->plan?->name,
                    'ends' => $row['subscription']->ends_at->format('j M Y'),
                ])->values(),
                'page' => $libraries->currentPage(),
                'lastPage' => $libraries->lastPage(),
                'total' => $libraries->total(),
                'prevUrl' => $libraries->previousPageUrl(),
                'nextUrl' => $libraries->nextPageUrl(),
            ],
        ])->withViewData(['noindex' => true]);
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

        $state = $this->subscriptions->stateOf($subscription);
        $daysLeft = (int) now()->startOfDay()->diffInDays($subscription->ends_at->copy()->startOfDay(), false);

        return Inertia::render('admin/library', [
            'library' => [
                'id' => $library->id,
                'name' => $library->library_name,
                'owner' => $library->user?->name,
                'phone' => $library->user?->phone,
                'address' => $library->address,
                'joined' => $library->created_at->format('j M Y'),
                'seats' => $library->seats_count,
                'batches' => $library->batches_count,
                'activeMembers' => $library->active_members_count,
                'totalMembers' => $library->total_members_count,
            ],
            'subscription' => [
                'state' => $state->value,
                'stateLabel' => self::STATE_LABELS[$state->value],
                'plan' => $subscription->plan?->name,
                'starts' => $subscription->starts_at->format('j M Y'),
                'ends' => $subscription->ends_at->format('j M Y'),
                'daysLeft' => $daysLeft,
                'readOnlyFrom' => $this->subscriptions->graceEndsAt($subscription)->format('j M Y'),
            ],
            'payments' => $subscription->payments()->with('plan')->latest()->get()->map(fn ($payment) => [
                'id' => $payment->id,
                'date' => ($payment->paid_at ?? $payment->created_at)->format('j M Y'),
                'plan' => $payment->plan?->name,
                'amount' => (int) $payment->amount,
                'status' => ucfirst(str_replace('_', ' ', $payment->status)),
                'invoice' => $payment->invoice_number,
            ]),
            'activity' => AdminActivity::with('user')->where('library_id', $library->id)->latest()->limit(20)->get()->map->toRow(),
            'maxAdjustDays' => self::MAX_ADJUST_DAYS,
        ])->withViewData(['noindex' => true]);
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
