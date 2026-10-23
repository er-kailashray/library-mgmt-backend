<?php

namespace App\Http\Controllers;

use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function show(Request $request)
    {
        return response()->json([
            'status' => true,
            'data' => $this->subscriptions->present($this->library($request)),
        ]);
    }
}
