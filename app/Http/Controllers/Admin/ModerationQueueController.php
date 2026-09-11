<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ModerationQueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['nullable', 'in:reels,reports,comments,appeals,sanctions,live'], 'state' => ['nullable', 'string', 'max:40'], 'q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'between:10,100']]);
        $type = $data['type'] ?? 'reels';
        $query = match ($type) {
            'reports' => DB::table('content_reports')->orderBy('created_at'),
            'comments' => DB::table('comments')->whereNotNull('hidden_at')->orderByDesc('updated_at'),
            'appeals' => DB::table('appeals')->where('state', $data['state'] ?? 'open')->orderBy('created_at'),
            'sanctions' => DB::table('user_sanctions')->where('state', $data['state'] ?? 'active')->orderByDesc('created_at'),
            'live' => DB::table('live_sessions')->when($data['state'] ?? null, fn ($q, $state) => $q->where('state', $state))->orderByDesc('updated_at'),
            default => DB::table('reels')->whereIn('state', [$data['state'] ?? 'pending_review'])->orderBy('updated_at'),
        };

        return ApiResponse::success($query->paginate($data['per_page'] ?? 50)->withQueryString());
    }

    public function page(): Response
    {
        return Inertia::render('Admin/Moderation', [
            'reels' => DB::table('reels')->whereIn('state', ['processing', 'pending_review', 'failed'])->orderBy('updated_at')->limit(50)->get(),
            'reports' => DB::table('content_reports')->where('state', 'open')->orderBy('created_at')->limit(50)->get(),
            'live' => DB::table('live_sessions')->where('state', 'live')->orderByDesc('started_at')->limit(20)->get(),
            'appeals' => DB::table('appeals')->whereIn('state', ['open', 'escalated'])->orderBy('created_at')->limit(50)->get(),
            'sanctions' => DB::table('user_sanctions')->where('state', 'active')->orderByDesc('created_at')->limit(50)->get(),
            'brokenLinks' => DB::table('reel_episode_links')->leftJoin('episodes', 'episodes.id', '=', 'reel_episode_links.episode_id')->join('reels', 'reels.id', '=', 'reel_episode_links.reel_id')->where(fn ($q) => $q->whereNull('episodes.id')->orWhere('episodes.availability', '!=', 'available'))->select('reels.id', 'reels.caption', 'reel_episode_links.episode_id')->limit(50)->get(),
            'recentTakedowns' => DB::table('live_takedowns')->join('live_sessions', 'live_sessions.id', '=', 'live_takedowns.live_session_id')->select('live_takedowns.*', 'live_sessions.title')->latest('live_takedowns.created_at')->limit(20)->get(),
            'freshAt' => now()->toIso8601String(),
        ]);
    }
}
