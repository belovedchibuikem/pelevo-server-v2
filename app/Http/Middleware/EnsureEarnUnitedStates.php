<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\EarnRegion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEarnUnitedStates
{
    public function __construct(private readonly EarnRegion $region) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->region->allows($request)) {
            return $next($request);
        }

        return ApiResponse::error(
            'REGION_RESTRICTED',
            'Earn is available only from a United States connection.',
            403,
        );
    }
}
