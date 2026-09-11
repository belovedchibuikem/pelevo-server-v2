<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class UserOperationsController extends Controller
{
    public function show(User $user): Response
    {
        return Inertia::render('Admin/UserDetail', [
            'user' => ['id' => $user->id, 'name' => $user->name, 'handle' => $user->handle, 'email' => $this->maskEmail($user->email), 'phone' => $this->maskPhone($user->phone), 'country_code' => $user->country_code, 'status' => $user->status, 'email_verified' => $user->email_verified_at !== null, 'onboarded_at' => $user->onboarded_at, 'created_at' => $user->created_at],
            'profile' => DB::table('user_profiles')->where('user_id', $user->id)->select('bio', 'avatar_url', 'locale', 'timezone', 'updated_at')->first(),
            'devices' => DB::table('devices')->where('user_id', $user->id)->select('id', 'name', 'platform', 'trust_state', 'last_seen_at', 'last_active_at', 'revoked_at')->latest('last_seen_at')->limit(50)->get(),
            'activity' => ['followed_shows' => DB::table('follows')->where('user_id', $user->id)->count(), 'library_items' => DB::table('episode_saves')->where('user_id', $user->id)->count(), 'playback_records' => DB::table('playback_progress')->where('user_id', $user->id)->count(), 'reels' => DB::table('creator_profiles')->join('reels', 'reels.creator_profile_id', '=', 'creator_profiles.id')->where('creator_profiles.user_id', $user->id)->count()],
            'accounts' => DB::table('financial_accounts')->where('owner_type', User::class)->where('owner_id', $user->id)->select('id', 'type', 'unit', 'balance', 'updated_at')->get(),
            'premium' => DB::table('premium_entitlements')->where('user_id', $user->id)->select('id', 'state', 'starts_at', 'ends_at', 'created_at')->latest('created_at')->limit(20)->get(),
            'referrals' => DB::table('referrals')->where(fn ($query) => $query->where('referrer_id', $user->id)->orWhere('referred_id', $user->id))->select('id', 'state', 'created_at', 'updated_at')->latest()->limit(30)->get(),
            'sanctions' => DB::table('user_sanctions')->where('user_id', $user->id)->select('id', 'type', 'state', 'reason', 'expires_at', 'revoked_at', 'created_at')->latest()->limit(30)->get(),
            'support' => DB::table('support_tickets')->where('user_id', $user->id)->select('id', 'subject', 'state', 'priority', 'sla_due_at', 'updated_at')->latest()->limit(30)->get(),
            'privacy' => ['exports' => DB::table('data_export_requests')->where('user_id', $user->id)->select('id', 'state', 'expires_at', 'completed_at', 'created_at')->latest()->limit(20)->get(), 'deletions' => DB::table('account_deletion_requests')->where('user_id', $user->id)->select('id', 'state', 'reason', 'scheduled_for', 'completed_at', 'created_at')->latest()->limit(20)->get()],
            'audit' => DB::table('audit_logs')->where('subject_type', User::class)->where('subject_id', $user->id)->select('id', 'action', 'reason', 'before', 'after', 'created_at')->latest()->limit(50)->get(),
            'freshAt' => now()->toIso8601String(),
        ]);
    }

    public function access(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:disable,restore,force_logout'], 'reason' => ['required', 'string', 'min:10', 'max:2000'], 'confirmation' => ['nullable', 'string', 'max:191']]);
        if ($data['action'] === 'disable' && $data['confirmation'] !== ($user->handle ?: $user->id)) {
            return ApiResponse::error('CONFIRMATION_MISMATCH', 'Type the user handle or identifier exactly to disable this account.', 422, ['confirmation' => ['The confirmation does not match.']]);
        }

        return DB::transaction(function () use ($data, $request, $user): JsonResponse {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $before = ['status' => $locked->status];
            if ($data['action'] === 'disable') {
                $locked->update(['status' => 'disabled']);
                $this->revokeSessions($locked);
            } elseif ($data['action'] === 'restore') {
                $locked->update(['status' => 'active']);
            } else {
                $this->revokeSessions($locked);
            }
            $audit = (string) Str::ulid();
            DB::table('audit_logs')->insert(['id' => $audit, 'admin_id' => $request->user('admin')->id, 'action' => 'user.'.$data['action'], 'subject_type' => User::class, 'subject_id' => $locked->id, 'reason' => $data['reason'], 'before' => json_encode($before), 'after' => json_encode(['status' => $locked->status, 'sessions_revoked' => $data['action'] !== 'restore']), 'request_id' => $request->attributes->get('request_id'), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

            return ApiResponse::success(['user_id' => $locked->id, 'status' => $locked->status, 'audit_reference' => $audit]);
        });
    }

    private function revokeSessions(User $user): void
    {
        DB::table('refresh_tokens')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        DB::table('devices')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 1).str_repeat('*', max(2, mb_strlen($name) - 1)).'@'.$domain;
    }

    private function maskPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        return str_repeat('*', max(0, strlen($phone) - 4)).substr($phone, -4);
    }
}
