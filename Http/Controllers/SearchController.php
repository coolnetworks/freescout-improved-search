<?php

namespace Modules\ImprovedSearch\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ImprovedSearch\Services\SearchService;

class SearchController extends Controller
{
    protected $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    /**
     * Get search suggestions for autocomplete.
     */
    public function suggestions(Request $request)
    {
        $query = $request->get('q', '');
        $user = auth()->user();

        if (!$user || strlen($query) < 2) {
            return response()->json([]);
        }

        $suggestions = $this->searchService->getSuggestions($query, $user);

        return response()->json($suggestions);
    }

    /**
     * Clear search cache.
     */
    public function clearCache(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $this->searchService->clearCache($user->id);

        return response()->json(['success' => true, 'message' => 'Search cache cleared']);
    }

    /**
     * Get search history for current user.
     */
    public function history(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([]);
        }

        $limit = $request->get('limit', 10);

        $history = \DB::table('search_history')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['query', 'results_count', 'created_at']);

        return response()->json($history);
    }

    /**
     * Clear search history for current user.
     */
    public function clearHistory(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        \DB::table('search_history')->where('user_id', $user->id)->delete();

        return response()->json(['success' => true, 'message' => 'Search history cleared']);
    }

    /**
     * Rebuild search index (admin only).
     */
    public function rebuildIndex(Request $request)
    {
        $user = auth()->user();

        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        try {
            $processed = $this->searchService->rebuildIndex();

            return response()->json([
                'success' => true,
                'message' => "Search index rebuilt. Processed {$processed} conversations.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to rebuild index: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get search statistics (admin only).
     */
    public function statistics(Request $request)
    {
        $user = auth()->user();

        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $stats = [
            'total_searches' => \DB::table('search_history')->count(),
            'unique_queries' => \DB::table('search_history')->distinct('query')->count(),
            'searches_today' => \DB::table('search_history')
                ->whereDate('created_at', today())
                ->count(),
            'top_queries' => \DB::table('search_history')
                ->select('query', \DB::raw('COUNT(*) as count'))
                ->groupBy('query')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
            'indexed_conversations' => \DB::table('search_index')->count(),
        ];

        return response()->json($stats);
    }
}
