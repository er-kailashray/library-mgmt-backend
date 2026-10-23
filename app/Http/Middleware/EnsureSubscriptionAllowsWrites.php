<?php

namespace App\Http\Middleware;

use App\Services\Subscriptions\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only mode for libraries whose subscription has expired: reads (GET/HEAD) always pass, so
 * the owner can still see everything; anything that changes data is refused with 402 and a
 * machine-readable code the app turns into the "choose a plan" screen.
 *
 * Runs after ResolveLibrary, which puts the library on the request.
 */
class EnsureSubscriptionAllowsWrites
{
    public const EXPIRED_CODE = 'subscription_expired';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $library = $request->attributes->get('library');
        $subscription = $this->subscriptions->forLibrary($library);

        if (! $this->subscriptions->stateOf($subscription)->canWrite()) {
            return response()->json([
                'status' => false,
                'code' => self::EXPIRED_CODE,
                'message' => 'Your subscription has expired, so the app is read-only. Choose a plan to continue.',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        return $next($request);
    }
}
