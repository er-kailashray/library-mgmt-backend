<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivity;
use Inertia\Inertia;

/** Everything changed in the admin panel, newest first. */
class ActivityController extends Controller
{
    public function index()
    {
        $activity = AdminActivity::with(['user', 'library'])->latest()->paginate(50);

        return Inertia::render('admin/activity', [
            'activity' => [
                'rows' => $activity->getCollection()->map->toRow()->values(),
                'page' => $activity->currentPage(),
                'lastPage' => $activity->lastPage(),
                'prevUrl' => $activity->previousPageUrl(),
                'nextUrl' => $activity->nextPageUrl(),
            ],
        ])->withViewData(['noindex' => true]);
    }
}
