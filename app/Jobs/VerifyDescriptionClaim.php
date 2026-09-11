<?php

namespace App\Jobs;

use App\Integrations\Rss\RssOwnershipInspector;
use App\Models\Show;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class VerifyDescriptionClaim implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly string $claimId)
    {
        $this->onQueue('rss');
    }

    public function uniqueId(): string
    {
        return $this->claimId;
    }

    /**
     * Execute the job.
     */
    public function handle(RssOwnershipInspector $inspector): void
    {
        $claim = DB::table('show_claims')->where('id', $this->claimId)->first();
        if (! $claim || $claim->method !== 'description' || ! in_array($claim->state, ['pending', 'verifying'], true)) {
            return;
        }
        if (now()->isAfter(Carbon::parse($claim->expires_at))) {
            DB::table('show_claims')->where('id', $claim->id)->update(['state' => 'expired', 'updated_at' => now()]);

            return;
        }
        $challenge = DB::table('claim_challenges')->where('show_claim_id', $claim->id)->latest()->first();
        $evidence = $inspector->inspect(Show::findOrFail($claim->show_id));
        $matched = $challenge && str_contains($evidence['description'], decrypt($challenge->destination_encrypted));
        $safeEvidence = [
            'resolved_url' => $evidence['resolved_url'],
            'status' => $evidence['status'],
            'fetched_at' => $evidence['fetched_at'],
            'owner_email_masked' => $evidence['email'] ? RssOwnershipInspector::mask($evidence['email']) : null,
            'matched' => $matched,
            'description_excerpt' => mb_substr(strip_tags($evidence['description']), 0, 240),
        ];
        DB::table('show_claims')->where('id', $claim->id)->update(['state' => $matched ? 'review' : 'pending', 'live_evidence' => json_encode($safeEvidence, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
    }
}
