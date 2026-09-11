<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\SocialIdentityVerifier;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConnectedAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $owner = $request->user()->id;
        $accounts = DB::table('connected_accounts')->where('user_id', $owner)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'provider', 'email', 'created_at'])
            ->map(fn (object $row): array => $this->present($row));

        return ApiResponse::success($accounts, ['user_id' => $owner]);
    }

    public function store(Request $request, SocialIdentityVerifier $verifier): JsonResponse
    {
        $data = $request->validate(['provider' => ['required', 'in:apple,google'], 'token' => ['required', 'string', 'max:10000']]);
        $identity = $verifier->verify($data['provider'], $data['token']);
        if (! $identity) {
            return ApiResponse::error('SOCIAL_TOKEN_INVALID', 'The provider token could not be verified.', 401);
        }
        if (DB::table('connected_accounts')->where('provider', $data['provider'])->where('provider_subject', $identity->subject)->where('user_id', '!=', $request->user()->id)->exists()) {
            return ApiResponse::error('SOCIAL_ACCOUNT_CONFLICT', 'This provider identity is already linked.', 409);
        }
        $account = DB::table('connected_accounts')->where('user_id', $request->user()->id)->where('provider', $data['provider'])->first();
        if ($account) {
            DB::table('connected_accounts')->where('id', $account->id)->update(['provider_subject' => $identity->subject, 'email' => $identity->email, 'updated_at' => now()]);
        } else {
            DB::table('connected_accounts')->insert(['id' => (string) Str::ulid(), 'user_id' => $request->user()->id, 'provider' => $data['provider'], 'provider_subject' => $identity->subject, 'email' => $identity->email, 'created_at' => now(), 'updated_at' => now()]);
        }

        return ApiResponse::success($this->present(DB::table('connected_accounts')->where('user_id', $request->user()->id)->where('provider', $data['provider'])->first()), status: 201);
    }

    public function destroy(string $account, Request $request): JsonResponse
    {
        $deleted = DB::table('connected_accounts')->where('id', $account)->where('user_id', $request->user()->id)->delete();
        if (! $deleted) {
            return ApiResponse::error('NOT_FOUND', 'Connected account not found.', 404);
        }

        return ApiResponse::success(['disconnected' => true]);
    }

    private function present(?object $row): array
    {
        return [
            'id' => $row?->id,
            'provider' => $row?->provider,
            'email' => $row?->email,
            'created_at' => $row?->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
        ];
    }
}
