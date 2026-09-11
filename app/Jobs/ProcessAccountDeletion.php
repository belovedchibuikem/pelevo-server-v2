<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProcessAccountDeletion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $requestId)
    {
        $this->onQueue('privacy');
    }

    public function uniqueId(): string
    {
        return $this->requestId;
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $request = DB::table('account_deletion_requests')->where('id', $this->requestId)->where('state', 'queued')->where('scheduled_for', '<=', now())->lockForUpdate()->first();
            if (! $request) {
                return;
            }
            DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->where('tokenable_id', $request->user_id)->delete();
            DB::table('refresh_tokens')->where('user_id', $request->user_id)->update(['revoked_at' => now()]);
            DB::table('connected_accounts')->where('user_id', $request->user_id)->delete();
            DB::table('user_profiles')->where('user_id', $request->user_id)->update(['bio' => null, 'avatar_url' => null, 'updated_at' => now()]);
            DB::table('users')->where('id', $request->user_id)->update(['name' => 'Deleted user', 'email' => 'deleted+'.Str::lower((string) Str::ulid()).'@invalid.pelevo', 'handle' => null, 'phone' => null, 'country_code' => null, 'password' => Str::random(80), 'status' => 'deleted', 'updated_at' => now()]);
            DB::table('account_deletion_requests')->where('id', $request->id)->update(['state' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
        });
    }
}
