<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class CreatorOperationsController extends Controller
{
    public function page(Request $request): Response
    {
        return Inertia::render('Admin/Creators', $this->workspace($request));
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->workspace($request));
    }

    public function show(string $creator): JsonResponse
    {
        $profile = DB::table('creator_profiles')->join('users', 'users.id', '=', 'creator_profiles.user_id')->where('creator_profiles.id', $creator)->select('creator_profiles.*', 'users.name', 'users.handle', 'users.status as user_status')->first();
        if (! $profile) {
            return ApiResponse::error('NOT_FOUND', 'Creator not found.', 404);
        }

        return ApiResponse::success(['creator' => $profile, 'studios' => DB::table('studios')->where('creator_profile_id', $creator)->get(), 'shows' => DB::table('show_claims')->join('shows', 'shows.id', '=', 'show_claims.show_id')->where('show_claims.creator_profile_id', $creator)->select('shows.id', 'shows.title', 'show_claims.state', 'show_claims.method', 'show_claims.verified_at')->get(), 'audience' => ['creator_followers' => DB::table('creator_followers')->where('creator_profile_id', $creator)->count()], 'claims' => DB::table('show_claims')->where('creator_profile_id', $creator)->latest()->get(), 'disputes' => DB::table('claim_disputes')->join('show_claims', 'show_claims.id', '=', 'claim_disputes.show_claim_id')->where('show_claims.creator_profile_id', $creator)->select('claim_disputes.*')->get(), 'tax' => DB::table('tax_profiles')->where('creator_profile_id', $creator)->select('id', 'country_code', 'state', 'created_at', 'updated_at')->first(), 'audits' => DB::table('audit_logs')->where('subject_type', 'App\\Models\\CreatorProfile')->where('subject_id', $creator)->latest()->get()]);
    }

    public function dispute(string $dispute, Request $request): JsonResponse
    {
        $data = $request->validate(['state' => ['required', 'in:open,investigating,resolved,dismissed'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $row = DB::table('claim_disputes')->where('id', $dispute)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Dispute not found.', 404);
        }
        DB::transaction(function () use ($row, $data, $request): void {
            DB::table('claim_disputes')->where('id', $row->id)->update(['state' => $data['state'], 'reason' => $data['reason'], 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'admin_id' => auth('admin')->id(), 'action' => 'claim_dispute.'.$data['state'], 'subject_type' => 'App\\Models\\ClaimDispute', 'subject_id' => $row->id, 'reason' => $data['reason'], 'before' => json_encode(['state' => $row->state]), 'after' => json_encode(['state' => $data['state']]), 'request_id' => $request->attributes->get('request_id'), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
        });

        return ApiResponse::success(['dispute_id' => $row->id, 'state' => $data['state']]);
    }

    private function workspace(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:30'], 'per_page' => ['nullable', 'integer', 'between:10,100']]);
        $query = DB::table('creator_profiles')->join('users', 'users.id', '=', 'creator_profiles.user_id')->select('creator_profiles.*', 'users.name', 'users.handle')->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('creator_profiles.display_name', 'like', "%{$term}%")->orWhere('users.handle', 'like', "%{$term}%")))->when($data['status'] ?? null, fn ($q, $state) => $q->where('creator_profiles.status', $state));

        return ['creators' => $query->orderByDesc('creator_profiles.updated_at')->paginate($data['per_page'] ?? 25)->withQueryString(), 'studios' => DB::table('studios')->select('studios.*')->selectSub(fn ($q) => $q->from('studio_members')->selectRaw('COUNT(*)')->whereColumn('studio_members.studio_id', 'studios.id'), 'member_count')->orderBy('name')->limit(100)->get(), 'disputes' => DB::table('claim_disputes')->join('shows', 'shows.id', '=', 'claim_disputes.show_id')->select('claim_disputes.*', 'shows.title as show_title')->whereIn('claim_disputes.state', ['open', 'investigating'])->orderBy('claim_disputes.created_at')->get(), 'filters' => $data, 'freshAt' => now()->toIso8601String()];
    }
}
