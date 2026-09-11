<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SupportController extends Controller
{
    public function help(): JsonResponse
    {
        return $this->cms('help');
    }

    public function about(): JsonResponse
    {
        return $this->cms('about');
    }

    public function guidelines(): JsonResponse
    {
        return $this->cms('guidelines');
    }

    public function cms(string $slug): JsonResponse
    {
        abort_unless(in_array($slug, ['help', 'about', 'terms', 'privacy', 'guidelines'], true), 404);
        $page = DB::table('cms_pages')->where('slug', $slug)->where('state', 'published')
            ->whereNotNull('published_at')->where('published_at', '<=', now())
            ->select('id', 'slug', 'title', 'body', 'version', 'published_at', 'updated_at')->first();
        if (! $page) {
            return ApiResponse::error('NOT_FOUND', 'This page has not been published yet.', 404);
        }
        $parts = preg_split('/^##[\t ]+(.+)\r?$/m', $page->body, -1, PREG_SPLIT_DELIM_CAPTURE);
        $sections = [];
        for ($index = 1; $index < count($parts); $index += 2) {
            $sections[] = ['title' => trim($parts[$index]), 'body' => trim($parts[$index + 1] ?? '')];
        }

        return ApiResponse::success([...((array) $page), 'body_format' => 'plain_text', 'intro' => trim($parts[0]), 'sections' => $sections]);
    }

    public function ticket(Request $request): JsonResponse
    {
        $data = $request->validate(['subject' => ['required', 'string', 'max:191'], 'message' => ['required', 'string', 'max:5000'], 'priority' => ['sometimes', 'in:normal,high'], 'client_request_id' => ['sometimes', 'uuid']]);
        $data['priority'] ??= 'normal';

        return $this->submission($request, 'support_tickets', $data);
    }

    public function feedback(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:bug,idea,rating'], 'rating' => ['required_if:type,rating', 'nullable', 'integer', 'between:1,5'], 'message' => ['required', 'string', 'max:5000'], 'client_request_id' => ['sometimes', 'uuid']]);
        $data['rating'] = isset($data['rating']) ? (int) $data['rating'] : null;

        return $this->submission($request, 'feedback', $data);
    }

    private function submission(Request $request, string $table, array $data): JsonResponse
    {
        return DB::transaction(function () use ($request, $table, $data): JsonResponse {
            $payload = collect($data)->except('client_request_id')->all();
            ksort($payload);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $key = $data['client_request_id'] ?? null;
            if ($key !== null) {
                $existing = DB::table($table)->where('user_id', $request->user()->id)->where('client_request_id', $key)->first();
                if ($existing) {
                    if (! hash_equals($existing->request_hash ?? '', $hash)) {
                        return ApiResponse::error('IDEMPOTENCY_CONFLICT', 'This request identifier was already used for different content.', 409);
                    }

                    return ApiResponse::success(['id' => $existing->id, 'state' => $existing->state], status: 201);
                }
            }
            $id = (string) Str::ulid();
            $state = $table === 'support_tickets' ? 'open' : 'new';
            DB::table($table)->insert([
                'id' => $id, 'user_id' => $request->user()->id, ...$data, 'request_hash' => $hash, 'state' => $state,
                ...($table === 'support_tickets' ? ['sla_due_at' => now()->addHours($data['priority'] === 'high' ? 4 : 24)] : []),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return ApiResponse::success(['id' => $id, 'state' => $state], status: 201);
        });
    }
}
