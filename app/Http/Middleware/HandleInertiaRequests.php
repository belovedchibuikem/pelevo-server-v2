<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function rootView(Request $request): string
    {
        return $request->is('admin', 'admin/*') ? 'app' : 'marketing';
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'flash' => [
                'status' => $request->session()->get('status'),
                'success' => $request->session()->get('success'),
            ],
        ];
    }
}
