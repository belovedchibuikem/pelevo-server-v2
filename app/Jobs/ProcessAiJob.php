<?php

namespace App\Jobs;

use App\Integrations\Ai\AiProviderException;
use App\Integrations\Ai\BuiltinPrompts;
use App\Integrations\Ai\EpisodeBrief;
use App\Integrations\Ai\LanguageModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
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

    public function handle(LanguageModel $model): void
    {
        $job = DB::table('ai_jobs')->where('id', $this->jobId)->where('state', 'queued')->first();
        if (! $job || ! config('features.ai', false)) {
            return;
        }
        $input = json_decode($job->input, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($input)) {
            $this->finish($job->id, 'failed', 'The AI job input could not be read.');

            return;
        }
        $prompt = null;
        $version = $input['_prompt_version'] ?? null;
        if ($version !== null && $version !== '' && $version !== 0) {
            $prompt = DB::table('prompt_versions')->where('type', $job->type)->where('version', $version)->where('state', 'active')->first();
        }
        $instructions = is_object($prompt) && is_string($prompt->prompt) && trim($prompt->prompt) !== ''
            ? $prompt->prompt
            : BuiltinPrompts::system((string) $job->type);
        $episodeId = is_string($input['episode_id'] ?? null) ? $input['episode_id'] : null;

        try {
            DB::table('ai_jobs')->where('id', $job->id)->update(['state' => 'processing', 'updated_at' => now()]);
            $completion = $model->complete(
                $instructions,
                BuiltinPrompts::user((string) $job->type, EpisodeBrief::for($episodeId), $input),
            );
            $presentation = BuiltinPrompts::presentation((string) $job->type, $completion->text);
            $unsafe = preg_match('/(?:password|credit card|api[_ -]?key)/i', $presentation['text']) === 1;
            DB::transaction(function () use ($job, $presentation, $completion, $unsafe): void {
                DB::table('ai_jobs')->where('id', $job->id)->update([
                    'state' => $unsafe ? 'safety_review' : 'completed',
                    'output' => json_encode([
                        'text' => $presentation['text'],
                        'takeaways' => $presentation['takeaways'],
                        'topics' => $presentation['topics'],
                    ], JSON_THROW_ON_ERROR),
                    'cost_microusd' => 0,
                    'updated_at' => now(),
                ]);
                DB::table('ai_usage')->insertOrIgnore([
                    'id' => (string) Str::ulid(),
                    'ai_job_id' => $job->id,
                    'user_id' => $job->user_id,
                    'provider' => (string) config('services.ai.provider', 'openai'),
                    'model' => $model->model(),
                    'input_tokens' => $completion->inputTokens,
                    'output_tokens' => $completion->outputTokens,
                    'cost_microusd' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (AiProviderException $exception) {
            $this->finish($job->id, 'failed', $exception->getMessage());
            if ($exception->retryable) {
                throw $exception;
            }
        } catch (Throwable $exception) {
            $this->finish($job->id, 'failed', 'The AI provider did not return a result. Try again.');
            throw $exception;
        }
    }

    private function finish(string $id, string $state, string $message): void
    {
        DB::table('ai_jobs')->where('id', $id)->update([
            'state' => $state,
            'output' => json_encode(['text' => $message, 'takeaways' => [], 'topics' => []], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
}
