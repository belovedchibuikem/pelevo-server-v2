<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class EarnCatalogController extends Controller
{
    public function page(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'listing' => ['nullable', 'in:earning,ordinary,all'],
            'niche' => ['nullable', 'integer'],
        ]);
        $listing = $filters['listing'] ?? 'earning';
        $shows = DB::table('shows')
            ->leftJoin('categories', 'categories.id', '=', 'shows.earn_category_id')
            ->select('shows.id', 'shows.title', 'shows.author', 'shows.status', 'shows.earn_enabled', 'shows.earn_category_id', 'shows.earn_position', 'categories.name as niche')
            ->when($listing === 'earning', fn ($query) => $query->where('shows.earn_enabled', true))
            ->when($listing === 'ordinary', fn ($query) => $query->where('shows.earn_enabled', false))
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->where(fn ($inner) => $inner->where('shows.title', 'like', "%{$term}%")->orWhere('shows.author', 'like', "%{$term}%")))
            ->when($filters['niche'] ?? null, fn ($query, int $niche) => $query->where('shows.earn_category_id', $niche))
            ->orderByRaw('CASE WHEN categories.name IS NULL THEN 1 ELSE 0 END')
            ->orderBy('categories.name')
            ->orderBy('shows.earn_position')
            ->orderBy('shows.title')
            ->orderBy('shows.id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/EarnCatalog', [
            'shows' => $shows,
            'categories' => DB::table('categories')->where('active', true)->orderBy('position')->orderBy('name')->get(['id', 'name']),
            'counts' => [
                'earning' => DB::table('shows')->where('earn_enabled', true)->count(),
                'ordinary' => DB::table('shows')->where(fn ($query) => $query->where('earn_enabled', false)->orWhereNull('earn_enabled'))->count(),
            ],
            'filters' => [
                'q' => $filters['q'] ?? '',
                'listing' => $listing,
                'niche' => isset($filters['niche']) ? (string) $filters['niche'] : '',
            ],
            'freshAt' => now()->toIso8601String(),
        ]);
    }
}
