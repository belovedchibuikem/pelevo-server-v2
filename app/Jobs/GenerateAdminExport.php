<?php

namespace App\Jobs;

use App\Http\Controllers\Admin\ModuleWorkspaceController;
use App\Http\Controllers\Admin\WorkspaceToolsController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateAdminExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $exportId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('admin-export:'.$this->exportId))->expireAfter(80)];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $export = DB::table('admin_exports')->find($this->exportId);
        if (! $export || in_array($export->state, ['completed', 'expired'], true)) {
            return;
        }
        if (now()->gte($export->expires_at) || ! WorkspaceToolsController::allowed($export->admin_id, $export->module)) {
            DB::table('admin_exports')->where('id', $export->id)->update(['state' => 'expired', 'updated_at' => now()]);

            return;
        }
        DB::table('admin_exports')->where('id', $export->id)->update(['state' => 'processing', 'updated_at' => now()]);
        $stream = fopen('php://temp/maxmemory:2097152', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Unable to open export stream.');
        }
        $count = 0;
        try {
            $query = app(ModuleWorkspaceController::class)->exportQuery($export->module, json_decode($export->filters, true, flags: JSON_THROW_ON_ERROR));
            fputcsv($stream, $query->columns, ',', '"', '');
            foreach ($query->lazy(500) as $row) {
                fputcsv($stream, array_map(function ($value): string {
                    $text = (string) ($value ?? '');

                    return preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u', $text) ? "'".$text : $text;
                }, (array) $row), ',', '"', '');
                $count++;
            }
            rewind($stream);
            $path = 'admin-exports/'.$export->id.'.csv';
            if (! Storage::disk($export->disk)->put($path, $stream)) {
                throw new RuntimeException('Unable to store export.');
            }
            DB::transaction(function () use ($export, $path, $count): void {
                DB::table('admin_exports')->where('id', $export->id)->update(['state' => 'completed', 'path' => $path, 'row_count' => $count, 'updated_at' => now()]);
                app(WorkspaceToolsController::class)->audit($export->admin_id, 'export.completed', $export->id, $export->reason, ['row_count' => $count]);
            });
        } finally {
            fclose($stream);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $export = DB::table('admin_exports')->where('id', $this->exportId)->lockForUpdate()->first();
            if (! $export || in_array($export->state, ['completed', 'expired', 'failed'], true)) {
                return;
            }
            DB::table('admin_exports')->where('id', $this->exportId)->update(['state' => 'failed', 'updated_at' => now()]);
            app(WorkspaceToolsController::class)->audit($export->admin_id, 'export.failed', $export->id, $export->reason, ['module' => $export->module]);
        });
    }
}
