<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminSearchController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $term = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q'];
        $permissions = \App\Support\AdminAccess::permissions($request->user('admin'));
        $can = fn (string $permission): bool => $permissions->contains($permission);
        $groups = [];

        if ($can('users.view')) {
            $groups[] = $this->group('Users', DB::table('users')->where(fn (Builder $query) => $query->where('name', 'like', "%{$term}%")->orWhere('handle', 'like', "%{$term}%"))->select('id', 'name as title', 'handle as subtitle')->limit(6)->get(), fn ($row): string => '/admin/users/'.$row->id);
            $groups[] = $this->group('Support', DB::table('support_tickets')->where(fn (Builder $query) => $query->where('subject', 'like', "%{$term}%")->orWhere('id', 'like', "%{$term}%"))->select('id', 'subject as title', 'state as subtitle')->limit(6)->get(), fn ($row): string => '/admin/support/tickets/'.$row->id);
        }
        if ($can('catalog.write')) {
            $groups[] = $this->group('Shows', DB::table('shows')->where(fn (Builder $query) => $query->where('title', 'like', "%{$term}%")->orWhere('rss_url', 'like', "%{$term}%"))->select('id', 'title', 'author as subtitle')->limit(6)->get(), fn ($row): string => '/admin/records/shows/'.$row->id);
            $groups[] = $this->group('Episodes', DB::table('episodes')->where(fn (Builder $query) => $query->where('title', 'like', "%{$term}%")->orWhere('guid', 'like', "%{$term}%"))->select('id', 'title', 'guid as subtitle')->limit(6)->get(), fn ($row): string => '/admin/records/episodes/'.$row->id);
        }
        if ($can('claims.decide')) {
            $rows = DB::table('show_claims')->join('shows', 'shows.id', '=', 'show_claims.show_id')->where(fn (Builder $query) => $query->where('shows.title', 'like', "%{$term}%")->orWhere('show_claims.id', 'like', "%{$term}%"))->select('show_claims.id', 'shows.title', 'show_claims.state as subtitle')->limit(6)->get();
            $groups[] = $this->group('Claims', $rows, fn ($row): string => '/admin/claims?claim='.$row->id);
        }
        if ($can('moderation.act')) {
            $groups[] = $this->group('Reels', DB::table('reels')->where(fn (Builder $query) => $query->where('caption', 'like', "%{$term}%")->orWhere('id', 'like', "%{$term}%"))->select('id', 'caption as title', 'state as subtitle')->limit(6)->get(), fn ($row): string => '/admin/records/reels/'.$row->id);
        }
        if ($can('finance.view')) {
            $groups[] = $this->group('Withdrawals', DB::table('withdrawals')->where('id', 'like', "%{$term}%")->select('id', 'id as title', 'state as subtitle')->limit(6)->get(), fn ($row): string => '/admin/records/withdrawals/'.$row->id);
            $groups[] = $this->group('Creator payouts', DB::table('creator_payouts')->where('id', 'like', "%{$term}%")->select('id', 'id as title', 'state as subtitle')->limit(6)->get(), fn ($row): string => '/admin/records/payouts/'.$row->id);
        }

        return ApiResponse::success(['groups' => collect($groups)->filter(fn (array $group): bool => $group['items'] !== [])->values()]);
    }

    private function group(string $label, Collection $rows, callable $href): array
    {
        return ['label' => $label, 'items' => $rows->map(fn ($row): array => ['title' => $row->title ?: 'Untitled', 'subtitle' => $row->subtitle ?: $label, 'href' => $href($row)])->all()];
    }
}
