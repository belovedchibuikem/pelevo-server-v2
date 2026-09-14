<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Authorize Horizon with the admin guard.
     *
     * Horizon's default Gate::check() uses the web user. Admins are on a
     * separate session guard, so a zero-argument viewHorizon callback is
     * treated as guest-denied in production and always returns 403.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(function ($request): bool {
            if (app()->environment('local')) {
                return true;
            }

            $admin = $request->user('admin') ?? auth('admin')->user();

            return \App\Support\AdminAccess::allows($admin, 'audit.view');
        });
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?object $user = null): bool {
            $admin = auth('admin')->user();

            return \App\Support\AdminAccess::allows($admin, 'audit.view');
        });
    }
}
