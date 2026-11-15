<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/** Props every web page (landing page, legal pages, admin panel) gets. */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** Only the public website is server-rendered (for search engines); the admin panel doesn't need it. */
    protected $withoutSsr = ['admin', 'admin/*'];

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'admin' => $user && $user->role === 'admin' ? ['name' => $user->name, 'phone' => $user->phone] : null,
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
            'links' => [
                'webApp' => rtrim((string) config('app.web_app_url'), '/') ?: null,
                'supportEmail' => config('library.support_email'),
                'supportPhone' => config('library.support_phone'),
                'playStore' => config('library.play_store_url') ?: null,
                'appStore' => config('library.app_store_url') ?: null,
            ],
        ];
    }
}
