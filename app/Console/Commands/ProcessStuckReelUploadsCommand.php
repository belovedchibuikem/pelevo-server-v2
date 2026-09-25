<?php

namespace App\Console\Commands;

use App\Contracts\MediaProbe;
use App\Jobs\ProcessReelUpload;
use App\Models\MediaUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class ProcessStuckReelUploadsCommand extends Command
{
    protected $signature = 'pelevo:process-reel-uploads
        {--sync : Run processing in this process instead of waiting for Horizon}
        {--retry-failed : Also retry uploads that already failed}
        {--limit=8 : Maximum stuck uploads to handle}';

    protected $description = 'Show and recover reel uploads stuck in queued/processing after the file already left the phone.';

    public function handle(MediaProbe $probe): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $states = ['queued', 'processing'];
        if ($this->option('retry-failed')) {
            $states[] = 'failed';
        }
        $listed = MediaUpload::query()
            ->whereIn('state', ['queued', 'processing', 'failed'])
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id', 'disk', 'path', 'state', 'failure_reason', 'updated_at']);
        $stuck = $listed->filter(fn (MediaUpload $upload): bool => in_array($upload->state, $states, true));

        try {
            $mediaDepth = Queue::connection()->size('media');
            $defaultDepth = Queue::connection()->size('default');
        } catch (Throwable) {
            $mediaDepth = 'n/a';
            $defaultDepth = 'n/a';
        }

        $this->table(['Check', 'Value'], [
            ['queue connection', (string) config('queue.default')],
            ['media queue depth', (string) $mediaDepth],
            ['default queue depth', (string) $defaultDepth],
            ['stuck uploads', (string) $listed->count()],
        ]);

        if ($listed->isEmpty()) {
            $this->info('No queued, processing, or failed reel uploads.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'disk', 'state', 'updated', 'failure'],
            $listed->map(fn (MediaUpload $upload): array => [
                $upload->id,
                $upload->disk,
                $upload->state,
                optional($upload->updated_at)?->toDateTimeString() ?? '',
                mb_substr((string) $upload->failure_reason, 0, 80),
            ])->all(),
        );

        if ($stuck->isEmpty()) {
            $this->comment('Nothing to recover. Pass --retry-failed to retry failed uploads.');

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;
        foreach ($stuck as $upload) {
            if ($upload->state === 'failed' && ! $this->option('retry-failed')) {
                continue;
            }
            try {
                if ($this->option('sync')) {
                    (new ProcessReelUpload($upload->id))->handle($probe);
                    $this->line('Processed '.$upload->id.' → '.$upload->fresh()?->state);
                } else {
                    ProcessReelUpload::dispatch($upload->id)
                        ->onQueue(ProcessReelUpload::queueForDisk($upload->disk));
                    $this->line('Queued '.$upload->id.' on '.ProcessReelUpload::queueForDisk($upload->disk));
                }
                $ok++;
            } catch (Throwable $error) {
                $failed++;
                Log::error('reel.upload.recover.failed', [
                    'id' => $upload->id,
                    'message' => $error->getMessage(),
                ]);
                $this->error($upload->id.': '.$error->getMessage());
            }
        }

        $this->info("Recovered {$ok}, failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
