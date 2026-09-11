<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ClaimWorkspaceController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Admin/Claims', [
            'claims' => DB::table('show_claims')
                ->join('shows', 'shows.id', '=', 'show_claims.show_id')
                ->join('creator_profiles', 'creator_profiles.id', '=', 'show_claims.creator_profile_id')
                ->leftJoin('claim_challenges', 'claim_challenges.show_claim_id', '=', 'show_claims.id')
                ->whereIn('show_claims.state', ['pending', 'verifying', 'review', 'disputed'])
                ->select('show_claims.*', 'shows.title as show_title', 'creator_profiles.display_name as claimant_name', 'claim_challenges.destination_masked', 'claim_challenges.attempts', 'claim_challenges.max_attempts')
                ->orderBy('show_claims.created_at')
                ->limit(100)
                ->get(),
            'freshAt' => now()->toIso8601String(),
        ]);
    }
}
