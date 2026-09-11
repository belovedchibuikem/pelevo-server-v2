<?php

namespace App\Providers;

use App\Contracts\MediaProbe;
use App\Contracts\MediaTranscoder;
use App\Contracts\SocialIdentityVerifier;
use App\Integrations\PodcastIndex\PodcastIndexAuthenticator;
use App\Integrations\PodcastIndex\PodcastIndexClient;
use App\Services\FfmpegMediaTranscoder;
use App\Services\FfprobeMediaProbe;
use App\Services\HttpSocialIdentityVerifier;
use App\Services\IntegrationSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MediaProbe::class, FfprobeMediaProbe::class);
        $this->app->bind(MediaTranscoder::class, FfmpegMediaTranscoder::class);
        $this->app->bind(SocialIdentityVerifier::class, HttpSocialIdentityVerifier::class);

        // Shared Podcast Index auth + client for every request in the app lifecycle.
        $this->app->singleton(PodcastIndexAuthenticator::class);
        $this->app->singleton(PodcastIndexClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        app(IntegrationSettings::class)->applyToConfig();

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('contact', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('operations', fn (Request $request) => [
            Limit::perMinute(5)->by('admin:'.($request->user('admin')?->id ?? 'guest')),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);

        Inertia::share('adminAuth', function (Request $request): ?array {
            $admin = $request->user('admin');
            if (! $admin) {
                return null;
            }

            return [
                'name' => $admin->name,
                'environment' => app()->environment(),
                'email' => $admin->email,
                'permissions' => DB::table('admin_role')
                    ->join('permission_role', 'permission_role.role_id', '=', 'admin_role.role_id')
                    ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                    ->where('admin_role.admin_id', $admin->id)
                    ->distinct()
                    ->orderBy('permissions.name')
                    ->pluck('permissions.name')
                    ->all(),
            ];
        });
    }
}
