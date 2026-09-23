<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Rss\RssOwnershipInspector;
use App\Jobs\VerifyDescriptionClaim;
use App\Mail\ClaimVerificationCode;
use App\Models\CreatorProfile;
use App\Models\Show;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class CreatorController extends Controller
{
    public function showClaim(string $claim, Request $request): JsonResponse
    {
        $creator = CreatorProfile::where('user_id', $request->user()->id)->first();
        $row = $creator ? DB::table('show_claims')->leftJoin('claim_challenges', 'claim_challenges.show_claim_id', '=', 'show_claims.id')->where('show_claims.id', $claim)->where('show_claims.creator_profile_id', $creator->id)->select('show_claims.id', 'show_claims.show_id', 'show_claims.method', 'show_claims.state', 'show_claims.expires_at', 'show_claims.verified_at', 'show_claims.decision_reason', 'show_claims.live_evidence', 'claim_challenges.destination_masked', 'claim_challenges.attempts', 'claim_challenges.max_attempts', 'claim_challenges.expires_at as challenge_expires_at')->orderByDesc('claim_challenges.created_at')->first() : null;

        return $row ? ApiResponse::success($row) : ApiResponse::error('NOT_FOUND', 'Claim not found.', 404);
    }

    public function resend(string $claim, Request $request, RssOwnershipInspector $inspector): JsonResponse
    {
        $creator = CreatorProfile::where('user_id', $request->user()->id)->first();
        $row = $creator ? DB::table('show_claims')->where('id', $claim)->where('creator_profile_id', $creator->id)->where('method', 'email')->whereIn('state', ['pending', 'verifying'])->first() : null;
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Claim not found.', 404);
        }
        if (now()->isAfter(Carbon::parse($row->expires_at))) {
            return ApiResponse::error('CONFLICT', 'Claim has expired.', 409);
        }
        $show = Show::findOrFail($row->show_id);
        $ownership = $inspector->inspect($show);
        if (! $ownership['email']) {
            return ApiResponse::error('NOT_FOUND', 'The feed no longer publishes an owner email.', 422);
        }
        $plain = (string) random_int(100000, 999999);
        $expires = now()->addMinutes(config('claims.email_code_expiry_minutes'));
        DB::transaction(function () use ($row, $plain, $expires, $ownership): void {
            DB::table('claim_challenges')->where('show_claim_id', $row->id)->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
            DB::table('claim_challenges')->insert(['id' => (string) Str::ulid(), 'show_claim_id' => $row->id, 'type' => 'email', 'destination_encrypted' => encrypt($ownership['email']), 'destination_masked' => RssOwnershipInspector::mask($ownership['email']), 'code_hash' => hash('sha256', $plain), 'max_attempts' => config('claims.max_attempts'), 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);
        });
        Mail::to($ownership['email'])->queue((new ClaimVerificationCode($plain, $show->title, $show->artwork_url, $show->author))->afterCommit());

        return ApiResponse::success(['claim_id' => $row->id, 'masked_destination' => RssOwnershipInspector::mask($ownership['email']), 'challenge_expires_at' => $expires->toIso8601String()], status: 202);
    }

    public function claim(Show $show, Request $request, RssOwnershipInspector $inspector): JsonResponse
    {
        $data = $request->validate(['method' => ['required', 'in:email,description']]);
        $ownership = $inspector->inspect($show);
        if ($data['method'] === 'email' && ! $ownership['email']) {
            return ApiResponse::error('NOT_FOUND', 'The feed does not publish an owner email.', 422);
        }
        $creator = CreatorProfile::firstOrCreate(['user_id' => $request->user()->id], ['display_name' => $request->user()->name]);
        $plain = $data['method'] === 'email' ? (string) random_int(100000, 999999) : 'PELEVO-VERIFY-'.strtoupper(Str::random(12));
        $id = (string) Str::ulid();
        $claimExpires = now()->addDays(config('claims.expiry_days'));
        $challengeExpires = $data['method'] === 'email' ? now()->addMinutes(config('claims.email_code_expiry_minutes')) : $claimExpires;
        DB::transaction(function () use ($id, $show, $creator, $data, $plain, $claimExpires, $challengeExpires, $ownership): void {
            DB::table('show_claims')->insert(['id' => $id, 'show_id' => $show->id, 'creator_profile_id' => $creator->id, 'method' => $data['method'], 'challenge_hash' => hash('sha256', $plain), 'expires_at' => $claimExpires, 'live_evidence' => json_encode(['resolved_url' => $ownership['resolved_url'], 'fetched_at' => $ownership['fetched_at']], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('claim_challenges')->insert(['id' => (string) Str::ulid(), 'show_claim_id' => $id, 'type' => $data['method'], 'destination_encrypted' => encrypt($data['method'] === 'email' ? $ownership['email'] : $plain), 'destination_masked' => $data['method'] === 'email' ? RssOwnershipInspector::mask($ownership['email']) : null, 'code_hash' => hash('sha256', $plain), 'max_attempts' => config('claims.max_attempts'), 'expires_at' => $challengeExpires, 'created_at' => now(), 'updated_at' => now()]);
        });
        if ($data['method'] === 'email') {
            Mail::to($ownership['email'])->queue((new ClaimVerificationCode($plain, $show->title, $show->artwork_url, $show->author))->afterCommit());
        }

        return ApiResponse::success(['claim_id' => $id, 'state' => 'pending', 'masked_destination' => $data['method'] === 'email' ? RssOwnershipInspector::mask($ownership['email']) : null, 'challenge' => $data['method'] === 'description' ? $plain : null, 'expires_at' => $claimExpires->toIso8601String(), 'challenge_expires_at' => $challengeExpires->toIso8601String()], status: 201);
    }

    public function verify(string $claim, Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:100']]);

        return DB::transaction(function () use ($claim, $data, $request): JsonResponse {
            $creator = CreatorProfile::where('user_id', $request->user()->id)->firstOrFail();
            $row = DB::table('show_claims')->where('id', $claim)->where('creator_profile_id', $creator->id)->where('method', 'email')->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('NOT_FOUND', 'Claim not found.', 404);
            } $challenge = DB::table('claim_challenges')->where('show_claim_id', $row->id)->lockForUpdate()->latest()->first();
            if (! $challenge || $challenge->consumed_at || now()->isAfter(Carbon::parse($challenge->expires_at)) || $challenge->attempts >= $challenge->max_attempts) {
                return ApiResponse::error('CONFLICT', 'Claim challenge has expired.', 409);
            } if (! hash_equals($challenge->code_hash, hash('sha256', $data['code']))) {
                DB::table('claim_challenges')->where('id', $challenge->id)->increment('attempts');

                return ApiResponse::error('VALIDATION', 'The verification code is invalid.', 422);
            } if (! DB::table('verified_show_claims')->insertOrIgnore(['show_id' => $row->show_id, 'show_claim_id' => $row->id, 'created_at' => now(), 'updated_at' => now()])) {
                $winner = DB::table('verified_show_claims')->where('show_id', $row->show_id)->value('show_claim_id');
                DB::table('claim_disputes')->insertOrIgnore(['id' => (string) Str::ulid(), 'show_id' => $row->show_id, 'show_claim_id' => $row->id, 'existing_claim_id' => $winner, 'reason' => 'Another claim verified first.', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('show_claims')->where('id', $row->id)->update(['state' => 'disputed', 'updated_at' => now()]);

                return ApiResponse::error('CONFLICT', 'This show already has a verified claim; a dispute was opened.', 409);
            } DB::table('claim_challenges')->where('id', $challenge->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
            DB::table('show_claims')->where('id', $row->id)->update(['state' => 'verified', 'verified_at' => now(), 'updated_at' => now()]);

            return ApiResponse::success(['claim_id' => $row->id, 'state' => 'verified']);
        });
    }

    public function confirmDescription(string $claim, Request $request): JsonResponse
    {
        $creator = CreatorProfile::where('user_id', $request->user()->id)->firstOrFail();
        $updated = DB::table('show_claims')->where('id', $claim)->where('creator_profile_id', $creator->id)->where('method', 'description')->where('state', 'pending')->update(['state' => 'verifying', 'updated_at' => now()]);
        if (! $updated) {
            return ApiResponse::error('NOT_FOUND', 'Claim not found.', 404);
        }
        VerifyDescriptionClaim::dispatch($claim);

        return ApiResponse::success(['claim_id' => $claim, 'state' => 'verifying'], status: 202);
    }

    public function studio(Request $request): JsonResponse
    {
        $own = CreatorProfile::where('user_id', $request->user()->id)->first();
        if ($own && DB::table('verified_show_claims')->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')->where('show_claims.creator_profile_id', $own->id)->exists()) {
            return ApiResponse::success($own);
        }
        $memberIds = DB::table('studios')->join('studio_members', 'studio_members.studio_id', '=', 'studios.id')->where('studio_members.user_id', $request->user()->id)->pluck('studios.creator_profile_id');
        $eligible = DB::table('verified_show_claims')->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')->whereIn('show_claims.creator_profile_id', $memberIds)->value('show_claims.creator_profile_id');
        $profile = $eligible ? CreatorProfile::find($eligible) : null;

        return $profile ? ApiResponse::success($profile) : ApiResponse::error('CLAIM_REQUIRED', 'A verified show claim is required.', 403);
    }
}
