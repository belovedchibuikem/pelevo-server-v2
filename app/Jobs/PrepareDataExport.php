<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PrepareDataExport implements ShouldBeUnique, ShouldQueue
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
        $request = DB::table('data_export_requests')->where('id', $this->requestId)->where('state', 'queued')->first();
        if (! $request) {
            return;
        }
        $payload = ['generated_at' => now()->toIso8601String(), 'user' => DB::table('users')->where('id', $request->user_id)->first(), 'profile' => DB::table('user_profiles')->where('user_id', $request->user_id)->first(), 'preferences' => DB::table('user_preferences')->where('user_id', $request->user_id)->first(), 'interests' => DB::table('user_interests')->where('user_id', $request->user_id)->pluck('interest'), 'connected_accounts' => DB::table('connected_accounts')->where('user_id', $request->user_id)->select('provider', 'email', 'created_at')->get(), 'devices' => DB::table('devices')->where('user_id', $request->user_id)->select('id', 'name', 'platform', 'last_seen_at', 'created_at')->get()];
        $path = 'private/exports/'.$request->id.'.json';
        $disk = config('exports.user_disk');
        Storage::disk($disk)->put($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        DB::table('data_export_requests')->where('id', $request->id)->update(['state' => 'completed', 'disk' => $disk, 'path' => $path, 'expires_at' => now()->addHours(config('exports.retention_hours')), 'completed_at' => now(), 'updated_at' => now()]);
    }
}
