<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class ProcessAiJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly string $jobId)
    {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $job = DB::table('ai_jobs')->where('id', $this->jobId)->where('state', 'queued')->first();
        if (! $job || ! config('features.ai', false)) {
            return;
        }
        $input = json_decode($job->input, true, flags: JSON_THROW_ON_ERROR);
        $prompt = DB::table('prompt_versions')->where('type', $job->type)->where('version', $input['_prompt_version'])->where('state', 'active')->first();
        if (! $prompt || ! config('services.ai.url')) {
            DB::table('ai_jobs')->where('id', $job->id)->update(['state' => 'failed', 'updated_at' => now()]);

            return;
        }
        try {
            DB::table('ai_jobs')->where('id', $job->id)->update(['state' => 'processing', 'updated_at' => now()]);
            $response = Http::withToken((string) config('services.ai.token'))->connectTimeout(3)->timeout(60)->post(config('services.ai.url'), ['model' => config('services.ai.model'), 'prompt' => $prompt->prompt, 'input' => $input])->throw()->json();
            $output = (string) data_get($response, 'output', '');
            $unsafe = preg_match('/(?:password|credit card|api[_ -]?key)/i', $output) === 1;
            DB::transaction(function () use ($job, $response, $output, $unsafe): void {
                DB::table('ai_jobs')->where('id', $job->id)->update(['state' => $unsafe ? 'safety_review' : 'completed', 'output' => json_encode(['text' => $output], JSON_THROW_ON_ERROR), 'cost_microusd' => (int) data_get($response, 'usage.cost_microusd', 0), 'updated_at' => now()]);
                DB::table('ai_usage')->insertOrIgnore(['id' => (string) Str::ulid(), 'ai_job_id' => $job->id, 'user_id' => $job->user_id, 'provider' => (string) config('services.ai.provider', 'configured'), 'model' => (string) config('services.ai.model'), 'input_tokens' => (int) data_get($response, 'usage.input_tokens', 0), 'output_tokens' => (int) data_get($response, 'usage.output_tokens', 0), 'cost_microusd' => (int) data_get($response, 'usage.cost_microusd', 0), 'created_at' => now(), 'updated_at' => now()]);
            });
        } catch (Throwable $exception) {
            DB::table('ai_jobs')->where('id', $job->id)->update(['state' => 'failed', 'updated_at' => now()]);
            throw $exception;
        }
    }
}
