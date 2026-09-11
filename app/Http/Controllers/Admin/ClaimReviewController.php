<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\Rss\RssOwnershipInspector;
use App\Mail\ClaimVerificationCode;
use App\Models\Show;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class ClaimReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['state' => ['nullable', 'in:pending,verifying,review,disputed,verified,rejected,expired'], 'method' => ['nullable', 'in:email,description'], 'per_page' => ['nullable', 'integer', 'between:10,100']]);
        $query = DB::table('show_claims')->leftJoin('claim_challenges', 'claim_challenges.show_claim_id', '=', 'show_claims.id')->select('show_claims.*', 'claim_challenges.destination_masked')->when($data['state'] ?? null, fn ($q, $state) => $q->where('show_claims.state', $state), fn ($q) => $q->whereIn('show_claims.state', ['pending', 'verifying', 'review', 'disputed']))->when($data['method'] ?? null, fn ($q, $method) => $q->where('show_claims.method', $method));

        return ApiResponse::success($query->orderBy('show_claims.created_at')->paginate($data['per_page'] ?? 50)->withQueryString());
    }

    public function show(string $claim): JsonResponse
    {
        $row = DB::table('show_claims')
            ->join('shows', 'shows.id', '=', 'show_claims.show_id')
            ->join('creator_profiles', 'creator_profiles.id', '=', 'show_claims.creator_profile_id')
            ->leftJoin('claim_challenges', 'claim_challenges.show_claim_id', '=', 'show_claims.id')
            ->where('show_claims.id', $claim)
            ->select('show_claims.*', 'shows.title as show_title', 'creator_profiles.display_name as claimant_name', 'claim_challenges.destination_masked', 'claim_challenges.attempts', 'claim_challenges.max_attempts')
            ->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Claim not found.', 404);
        }

        return ApiResponse::success([
            'claim' => $row,
            'reviews' => DB::table('claim_reviews')->where('show_claim_id', $claim)->orderBy('created_at')->get(),
            'disputes' => DB::table('claim_disputes')->where('show_claim_id', $claim)->get(),
            'audits' => DB::table('audit_logs')->where('subject_type', 'App\\Models\\ShowClaim')->where('subject_id', $claim)->orderBy('created_at')->get(),
        ]);
    }

    public function live(string $claim, RssOwnershipInspector $inspector): JsonResponse
    {
        $row = DB::table('show_claims')->where('id', $claim)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Claim not found.', 404);
        } $evidence = $inspector->inspect(Show::findOrFail($row->show_id));
        $safe = ['resolved_url' => $evidence['resolved_url'], 'status' => $evidence['status'], 'fetched_at' => $evidence['fetched_at'], 'owner_email_masked' => $evidence['email'] ? RssOwnershipInspector::mask($evidence['email']) : null, 'description_excerpt' => mb_substr(strip_tags($evidence['description']), 0, 240)];
        DB::table('show_claims')->where('id', $claim)->update(['live_evidence' => json_encode($safe, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

        return ApiResponse::success($safe);
    }

    public function requestNewCode(string $claim, Request $request, RssOwnershipInspector $inspector): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $row = DB::table('show_claims')->where('id', $claim)->where('method', 'email')->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Email claim not found.', 404);
        }
        $show = Show::findOrFail($row->show_id);
        $ownership = $inspector->inspect($show);
        if (! $ownership['email']) {
            return ApiResponse::error('NOT_FOUND', 'The live feed has no owner email.', 422);
        }
        $plain = (string) random_int(100000, 999999);
        $expires = now()->addMinutes(config('claims.email_code_expiry_minutes'));
        DB::transaction(function () use ($row, $plain, $expires, $ownership, $request, $data): void {
            DB::table('claim_challenges')->where('show_claim_id', $row->id)->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
            DB::table('claim_challenges')->insert(['id' => (string) Str::ulid(), 'show_claim_id' => $row->id, 'type' => 'email', 'destination_encrypted' => encrypt($ownership['email']), 'destination_masked' => RssOwnershipInspector::mask($ownership['email']), 'code_hash' => hash('sha256', $plain), 'max_attempts' => config('claims.max_attempts'), 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($request, 'claim.code_reissued', 'App\\Models\\ShowClaim', $row->id, $data['reason'], [], ['expires_at' => $expires]);
        });
        Mail::to($ownership['email'])->queue((new ClaimVerificationCode($plain, $show->title))->afterCommit());

        return ApiResponse::success(['claim_id' => $row->id, 'masked_destination' => RssOwnershipInspector::mask($ownership['email']), 'challenge_expires_at' => $expires->toIso8601String()]);
    }

    public function update(string $claim, Request $request): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected,escalated'], 'reason' => ['required', 'string', 'min:10', 'max:2000'], 'evidence' => ['nullable', 'array']]);

        return DB::transaction(function () use ($claim, $request, $data): JsonResponse {
            $record = DB::table('show_claims')->where('id', $claim)->lockForUpdate()->first();
            if (! $record) {
                return ApiResponse::error('NOT_FOUND', 'Claim not found.', 404);
            }
            if ($data['decision'] === 'approved') {
                if ($record->method === 'description' && $record->state !== 'review') {
                    return ApiResponse::error('INVALID_STATE', 'Live RSS verification must pass before approval.', 422);
                }
                if (! DB::table('verified_show_claims')->insertOrIgnore(['show_id' => $record->show_id, 'show_claim_id' => $record->id, 'created_at' => now(), 'updated_at' => now()])) {
                    $winner = DB::table('verified_show_claims')->where('show_id', $record->show_id)->value('show_claim_id');
                    DB::table('claim_disputes')->insertOrIgnore(['id' => (string) Str::ulid(), 'show_id' => $record->show_id, 'show_claim_id' => $record->id, 'existing_claim_id' => $winner, 'reason' => 'Another claim was approved first.', 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('show_claims')->where('id', $claim)->update(['state' => 'disputed', 'updated_at' => now()]);

                    return ApiResponse::error('CONFLICT', 'Another claim owns this show; a dispute was opened.', 409);
                }
            }
            $state = ['approved' => 'verified', 'rejected' => 'rejected', 'escalated' => 'disputed'][$data['decision']];
            DB::table('show_claims')->where('id', $claim)->update(['state' => $state, 'verified_at' => $state === 'verified' ? now() : null, 'decision_reason' => $data['reason'], 'updated_at' => now()]);
            $reviewId = (string) Str::ulid();
            DB::table('claim_reviews')->insert(['id' => $reviewId, 'show_claim_id' => $claim, 'admin_id' => auth('admin')->id(), 'decision' => $data['decision'], 'reason' => $data['reason'], 'evidence' => json_encode($data['evidence'] ?? [], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($request, 'claim.'.$data['decision'], 'App\\Models\\ShowClaim', $claim, $data['reason'], ['state' => $record->state], ['state' => $state]);

            return ApiResponse::success(['claim_id' => $claim, 'state' => $state, 'audit_reference' => $reviewId]);
        });
    }

    private function audit(Request $request, string $action, string $type, string $id, string $reason, array $before, array $after): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'admin_id' => auth('admin')->id(), 'action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'reason' => $reason, 'before' => json_encode($before), 'after' => json_encode($after), 'request_id' => $request->attributes->get('request_id'), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
