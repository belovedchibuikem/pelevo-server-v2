<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Operations\HealthProbe;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class OperationsController extends Controller
{
    public function page(HealthProbe $probe): Response
    {
        return Inertia::render('Admin/Operations', [
            'diagnostics' => $probe->inspect(),
            'manualTasks' => config('operations.manual_tasks'),
            'recentOperations' => DB::table('admin_operation_requests')->latest()->limit(30)->get(),
            'recentFeedSyncs' => DB::table('feed_sync_runs')->latest('started_at')->limit(30)->get(),
            'failedJobs' => DB::table('failed_jobs')->latest('failed_at')->limit(25)->get(['uuid', 'connection', 'queue', 'exception', 'failed_at'])->map(fn ($job): array => [
                'uuid' => $job->uuid,
                'connection' => $job->connection,
                'queue' => $job->queue,
                'failed_at' => $job->failed_at,
                'summary' => Str::limit(Str::before($job->exception, "\n"), 300),
            ]),
            'setup' => [
                'scheduler' => '* * * * * cd /var/www/pelevo/api && php artisan schedule:run --no-interaction >> /dev/null 2>&1',
                'horizon' => 'sudo systemctl enable --now pelevo-horizon.service',
                'verify' => 'php artisan schedule:list && php artisan horizon:status',
            ],
        ]);
    }

    public function diagnostics(HealthProbe $probe): JsonResponse
    {
        return ApiResponse::success($probe->inspect());
    }

    public function readiness(Request $request, HealthProbe $probe): JsonResponse
    {
        $token = (string) config('operations.readiness_token');
        abort_if($token === '', 404);
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        $health = $probe->inspect();

        return ApiResponse::success($health, status: $health['healthy'] ? 200 : 503);
    }

    public function run(Request $request, string $task): JsonResponse
    {
        $allowedTasks = array_keys(config('operations.manual_tasks'));
        abort_unless(in_array($task, $allowedTasks, true), 404);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $existing = DB::table('admin_operation_requests')->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return ApiResponse::success(['operation' => $existing], status: 200);
        }

        $operationId = (string) Str::ulid();
        DB::table('admin_operation_requests')->insert([
            'id' => $operationId,
            'admin_id' => $request->user('admin')->id,
            'action' => 'schedule.run',
            'target' => $task,
            'reason' => $data['reason'],
            'state' => 'running',
            'idempotency_key' => $data['idempotency_key'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $exitCode = Artisan::call($task);
            $result = ['exit_code' => $exitCode, 'output' => Str::limit(trim(Artisan::output()), 2000)];
            DB::table('admin_operation_requests')->where('id', $operationId)->update(['state' => $exitCode === 0 ? 'completed' : 'failed', 'result' => json_encode($result), 'updated_at' => now()]);
            $this->audit($request, 'operations.schedule_run', $operationId, $data['reason'], ['task' => $task, ...$result]);

            return ApiResponse::success(['operation' => DB::table('admin_operation_requests')->find($operationId)], status: $exitCode === 0 ? 202 : 500);
        } catch (Throwable $exception) {
            DB::table('admin_operation_requests')->where('id', $operationId)->update(['state' => 'failed', 'result' => json_encode(['error' => 'Task execution failed.']), 'updated_at' => now()]);
            $this->audit($request, 'operations.schedule_run_failed', $operationId, $data['reason'], ['task' => $task]);
            report($exception);

            return ApiResponse::error('OPERATION_FAILED', 'The task could not be started. Review the worker and application logs.', 500);
        }
    }

    public function retry(Request $request, string $job): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        abort_unless(DB::table('failed_jobs')->where('uuid', $job)->exists(), 404);
        $existing = DB::table('admin_operation_requests')->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return ApiResponse::success(['operation' => $existing]);
        }

        $operationId = (string) Str::ulid();
        $exitCode = Artisan::call('queue:retry', ['id' => [$job]]);
        DB::table('admin_operation_requests')->insert([
            'id' => $operationId,
            'admin_id' => $request->user('admin')->id,
            'action' => 'queue.retry',
            'target' => $job,
            'reason' => $data['reason'],
            'state' => $exitCode === 0 ? 'completed' : 'failed',
            'idempotency_key' => $data['idempotency_key'],
            'result' => json_encode(['exit_code' => $exitCode]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request, 'operations.failed_job_retried', $operationId, $data['reason'], ['job_uuid' => $job, 'exit_code' => $exitCode]);

        return ApiResponse::success(['operation' => DB::table('admin_operation_requests')->find($operationId)], status: 202);
    }

    private function audit(Request $request, string $action, string $subjectId, string $reason, array $after): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'admin_id' => $request->user('admin')->id,
            'action' => $action,
            'subject_type' => 'App\\Models\\AdminOperationRequest',
            'subject_id' => $subjectId,
            'reason' => $reason,
            'after' => json_encode($after),
            'request_id' => $request->attributes->get('request_id'),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
