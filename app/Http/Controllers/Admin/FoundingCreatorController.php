<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\FoundingCreators;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FoundingCreatorController extends Controller
{
    private const EXPORT_COLUMNS = [
        'id' => 'ID',
        'created_at' => 'Submitted at',
        'name' => 'Name',
        'show_name' => 'Podcast/show name',
        'show_url' => 'Show link',
        'email' => 'Email',
        'social_handle' => 'Instagram or X handle',
        'publish_frequency' => 'Publishing frequency',
        'notes' => 'Anything else',
        'state' => 'Status',
        'admin_note' => 'Internal note',
        'reviewed_at' => 'Last reviewed at',
        'submission_count' => 'Submissions',
    ];

    public function page(Request $request): Response
    {
        $filters = $this->filters($request);
        $page = $this->query($filters)
            ->orderBy('created_at', $filters['direction'])
            ->paginate((int) $filters['per_page'])
            ->withQueryString();

        return Inertia::render('Admin/FoundingCreators', [
            'applications' => $page->toArray(),
            'counts' => DB::table('founding_creator_applications')->selectRaw('state, COUNT(*) as total')->groupBy('state')->pluck('total', 'state'),
            'total' => DB::table('founding_creator_applications')->count(),
            'filters' => $filters,
            'states' => FoundingCreators::options(FoundingCreators::STATES),
            'frequencies' => FoundingCreators::options(FoundingCreators::FREQUENCIES),
            'freshAt' => now()->toIso8601String(),
        ]);
    }

    public function update(Request $request, string $application): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', Rule::in(array_keys(FoundingCreators::STATES))],
            'admin_note' => ['nullable', 'string', 'max:5000'],
        ]);

        return DB::transaction(function () use ($data, $application, $request): JsonResponse {
            $row = DB::table('founding_creator_applications')->where('id', $application)->lockForUpdate()->first();
            abort_unless($row, 404);
            $values = [
                'state' => $data['state'],
                'admin_note' => $data['admin_note'] ?? null,
                'reviewed_by_admin_id' => $request->user('admin')->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ];
            DB::table('founding_creator_applications')->where('id', $application)->update($values);
            $audit = (string) Str::ulid();
            DB::table('audit_logs')->insert([
                'id' => $audit,
                'admin_id' => $request->user('admin')->id,
                'subject_type' => 'founding_creator_application',
                'subject_id' => $application,
                'action' => 'founding_creator.updated',
                'reason' => 'Status set to '.FoundingCreators::STATES[$data['state']],
                'before' => json_encode(['state' => $row->state, 'admin_note' => $row->admin_note]),
                'after' => json_encode(['state' => $values['state'], 'admin_note' => $values['admin_note']]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ApiResponse::success(['audit_reference' => $audit]);
        }, 3);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->query($filters)->select(array_keys(self::EXPORT_COLUMNS))->orderBy('created_at', $filters['direction']);
        app(WorkspaceToolsController::class)->audit($request->user('admin')->id, 'founding_creator.exported', (string) Str::ulid(), 'Exported founding creator sign-ups', ['filters' => $filters, 'row_count' => (clone $query)->count()]);

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, array_values(self::EXPORT_COLUMNS), ',', '"', '');
            foreach ($query->lazy(500) as $row) {
                $row = (array) $row;
                $row['publish_frequency'] = FoundingCreators::FREQUENCIES[$row['publish_frequency']] ?? '';
                $row['state'] = FoundingCreators::STATES[$row['state']] ?? $row['state'];
                fputcsv($stream, array_map(function ($value): string {
                    $text = (string) ($value ?? '');

                    return preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u', $text) ? "'".$text : $text;
                }, $row), ',', '"', '');
            }
            fclose($stream);
        }, 'pelevo-founding-creators-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array{q: string, state: string, frequency: string, date_from: string, date_to: string, direction: string, per_page: int}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(array_keys(FoundingCreators::STATES))],
            'frequency' => ['nullable', Rule::in(array_keys(FoundingCreators::FREQUENCIES))],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', Rule::in([25, 50, 100])],
        ]);

        return [
            'q' => $validated['q'] ?? '',
            'state' => $validated['state'] ?? '',
            'frequency' => $validated['frequency'] ?? '',
            'date_from' => $validated['date_from'] ?? '',
            'date_to' => $validated['date_to'] ?? '',
            'direction' => $validated['direction'] ?? 'desc',
            'per_page' => (int) ($validated['per_page'] ?? 25),
        ];
    }

    /**
     * @param  array{q: string, state: string, frequency: string, date_from: string, date_to: string}  $filters
     */
    private function query(array $filters): Builder
    {
        $query = DB::table('founding_creator_applications');
        if ($filters['q'] !== '') {
            $term = '%'.$filters['q'].'%';
            $query->where(fn (Builder $nested) => $nested->where('name', 'like', $term)->orWhere('show_name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('social_handle', 'like', $term)->orWhere('show_url', 'like', $term));
        }
        if ($filters['state'] !== '') {
            $query->where('state', $filters['state']);
        }
        if ($filters['frequency'] !== '') {
            $query->where('publish_frequency', $filters['frequency']);
        }
        if ($filters['date_from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query;
    }
}
