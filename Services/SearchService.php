<?php

namespace Modules\ImprovedSearch\Services;

use App\Conversation;
use App\Thread;
use App\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class SearchService
{
    /**
     * Perform an enhanced search across conversations.
     */
    public function performSearch($query, $filters, $user)
    {
        if (strlen($query) < config('improvedsearch.min_query_length', 2)) {
            return null;
        }

        $cacheKey = $this->getCacheKey($query, $filters, $user->id);
        $cacheDuration = config('improvedsearch.cache_duration', 5);

        return Cache::remember($cacheKey, $cacheDuration * 60, function () use ($query, $filters, $user) {
            return $this->executeSearch($query, $filters, $user);
        });
    }

    /**
     * Execute the actual search query.
     */
    protected function executeSearch($query, $filters, $user)
    {
        $searchTerms = $this->parseSearchTerms($query);
        $mailboxIds = $this->getAccessibleMailboxIds($user, $filters);

        if (empty($mailboxIds)) {
            return collect();
        }

        // Check if full-text search is available and enabled
        if (config('improvedsearch.enable_fulltext') && $this->isFullTextAvailable()) {
            return $this->fullTextSearch($searchTerms, $filters, $mailboxIds, $user);
        }

        return $this->enhancedLikeSearch($searchTerms, $filters, $mailboxIds, $user);
    }

    /**
     * Full-text search implementation (MySQL).
     */
    protected function fullTextSearch($searchTerms, $filters, $mailboxIds, $user)
    {
        $searchString = implode(' ', $searchTerms);
        $weights = config('improvedsearch.search_weights', []);

        $query = DB::table('conversations as c')
            ->select([
                'c.id',
                'c.number',
                'c.subject',
                'c.customer_id',
                'c.mailbox_id',
                'c.status',
                'c.state',
                'c.created_at',
                'c.updated_at',
                DB::raw($this->buildRelevanceScore($searchString, $weights) . ' as relevance_score'),
            ])
            ->leftJoin('threads as t', 'c.id', '=', 't.conversation_id')
            ->leftJoin('customers as cu', 'c.customer_id', '=', 'cu.id')
            ->whereIn('c.mailbox_id', $mailboxIds)
            ->where(function ($q) use ($searchTerms, $searchString) {
                // Search in subject
                $q->whereRaw('MATCH(c.subject) AGAINST(? IN BOOLEAN MODE)', [$this->prepareFullTextQuery($searchString)])
                    // Search in thread body
                    ->orWhereRaw('MATCH(t.body) AGAINST(? IN BOOLEAN MODE)', [$this->prepareFullTextQuery($searchString)])
                    // Fallback LIKE for exact matches
                    ->orWhere('c.subject', 'LIKE', '%'.$searchString.'%')
                    ->orWhere('c.customer_email', 'LIKE', '%'.$searchString.'%')
                    ->orWhere('t.body', 'LIKE', '%'.$searchString.'%');

                // Search by conversation number
                if (is_numeric($searchString)) {
                    $q->orWhere('c.number', '=', $searchString)
                        ->orWhere('c.id', '=', $searchString);
                }
            })
            ->groupBy('c.id')
            ->orderByDesc('relevance_score')
            ->orderByDesc('c.updated_at');

        // Apply filters
        $query = $this->applyFilters($query, $filters, $user);

        $perPage = config('improvedsearch.results_per_page', 50);

        return Conversation::hydrate(
            $query->paginate($perPage)->items()
        );
    }

    /**
     * Enhanced LIKE search with relevance ranking.
     */
    protected function enhancedLikeSearch($searchTerms, $filters, $mailboxIds, $user)
    {
        $searchString = implode(' ', $searchTerms);
        $weights = config('improvedsearch.search_weights', []);
        $likeOperator = \Helper::isPgSql() ? 'ILIKE' : 'LIKE';

        $relevanceCase = $this->buildLikeRelevanceScore($searchString, $weights, $likeOperator);

        $query = DB::table('conversations as c')
            ->select([
                'c.*',
                DB::raw($relevanceCase . ' as relevance_score'),
            ])
            ->leftJoin('threads as t', 'c.id', '=', 't.conversation_id')
            ->leftJoin('customers as cu', 'c.customer_id', '=', 'cu.id')
            ->whereIn('c.mailbox_id', $mailboxIds)
            ->where(function ($q) use ($searchTerms, $searchString, $likeOperator) {
                foreach ($searchTerms as $term) {
                    $term = '%' . $term . '%';

                    $q->orWhere('c.subject', $likeOperator, $term)
                        ->orWhere('c.customer_email', $likeOperator, $term)
                        ->orWhere('t.body', $likeOperator, $term)
                        ->orWhere('t.from', $likeOperator, $term)
                        ->orWhere('t.to', $likeOperator, $term)
                        ->orWhere('t.cc', $likeOperator, $term)
                        ->orWhere('t.bcc', $likeOperator, $term)
                        ->orWhere('cu.first_name', $likeOperator, $term)
                        ->orWhere('cu.last_name', $likeOperator, $term)
                        ->orWhere(DB::raw("CONCAT(cu.first_name, ' ', cu.last_name)"), $likeOperator, $term);
                }

                // Exact match on conversation number/id
                if (is_numeric($searchString)) {
                    $q->orWhere('c.number', '=', $searchString)
                        ->orWhere('c.id', '=', $searchString);
                }
            })
            ->groupBy('c.id');

        // Apply sorting based on filter
        $sortBy = $filters['sort'] ?? 'relevance';
        switch ($sortBy) {
            case 'date_desc':
                $query->orderByDesc('c.updated_at');
                break;
            case 'date_asc':
                $query->orderBy('c.updated_at');
                break;
            case 'relevance':
            default:
                $query->orderByDesc('relevance_score')
                    ->orderByDesc('c.updated_at');
                break;
        }

        // Apply filters
        $query = $this->applyFilters($query, $filters, $user);

        $perPage = config('improvedsearch.results_per_page', 50);

        $results = $query->paginate($perPage);

        return Conversation::hydrate($results->items());
    }

    /**
     * Build relevance score for full-text search.
     */
    protected function buildRelevanceScore($searchString, $weights)
    {
        $cases = [];

        if (isset($weights['subject'])) {
            $cases[] = "(CASE WHEN c.subject LIKE '%{$searchString}%' THEN {$weights['subject']} ELSE 0 END)";
        }
        if (isset($weights['customer_email'])) {
            $cases[] = "(CASE WHEN c.customer_email LIKE '%{$searchString}%' THEN {$weights['customer_email']} ELSE 0 END)";
        }
        if (isset($weights['body'])) {
            $cases[] = "(CASE WHEN t.body LIKE '%{$searchString}%' THEN {$weights['body']} ELSE 0 END)";
        }
        if (isset($weights['customer_name'])) {
            $cases[] = "(CASE WHEN CONCAT(cu.first_name, ' ', cu.last_name) LIKE '%{$searchString}%' THEN {$weights['customer_name']} ELSE 0 END)";
        }

        return empty($cases) ? '0' : '(' . implode(' + ', $cases) . ')';
    }

    /**
     * Build relevance score for LIKE search.
     */
    protected function buildLikeRelevanceScore($searchString, $weights, $likeOperator)
    {
        $escapedSearch = addslashes($searchString);
        $cases = [];

        // Exact match in subject (highest weight)
        if (isset($weights['subject'])) {
            $weight = $weights['subject'];
            $cases[] = "(CASE WHEN c.subject {$likeOperator} '%{$escapedSearch}%' THEN {$weight} ELSE 0 END)";
            // Bonus for subject starting with search term
            $cases[] = "(CASE WHEN c.subject {$likeOperator} '{$escapedSearch}%' THEN " . ($weight * 2) . " ELSE 0 END)";
        }

        if (isset($weights['customer_email'])) {
            $weight = $weights['customer_email'];
            $cases[] = "(CASE WHEN c.customer_email {$likeOperator} '%{$escapedSearch}%' THEN {$weight} ELSE 0 END)";
        }

        if (isset($weights['body'])) {
            $weight = $weights['body'];
            $cases[] = "(CASE WHEN t.body {$likeOperator} '%{$escapedSearch}%' THEN {$weight} ELSE 0 END)";
        }

        if (isset($weights['customer_name'])) {
            $weight = $weights['customer_name'];
            $cases[] = "(CASE WHEN cu.first_name {$likeOperator} '%{$escapedSearch}%' THEN {$weight} ELSE 0 END)";
            $cases[] = "(CASE WHEN cu.last_name {$likeOperator} '%{$escapedSearch}%' THEN {$weight} ELSE 0 END)";
        }

        if (isset($weights['thread_from'])) {
            $weight = $weights['thread_from'];
            $cases[] = "(CASE WHEN t.from {$likeOperator} '%{$escapedSearch}%' THEN {$weight} ELSE 0 END)";
        }

        return empty($cases) ? '0' : '(' . implode(' + ', $cases) . ')';
    }

    /**
     * Apply advanced filters to the query.
     */
    protected function applyFilters($query, $filters, $user)
    {
        // Status filter
        if (!empty($filters['status'])) {
            $query->where('c.status', '=', $filters['status']);
        }

        // State filter
        if (!empty($filters['state'])) {
            $query->where('c.state', '=', $filters['state']);
        }

        // Assigned filter
        if (!empty($filters['assigned'])) {
            if ($filters['assigned'] === 'me') {
                $query->where('c.user_id', '=', $user->id);
            } elseif ($filters['assigned'] === 'unassigned') {
                $query->whereNull('c.user_id');
            } elseif (is_numeric($filters['assigned'])) {
                $query->where('c.user_id', '=', $filters['assigned']);
            }
        }

        // Date range filter
        if (!empty($filters['date_range'])) {
            $query = $this->applyDateRangeFilter($query, $filters['date_range']);
        }

        // After date
        if (!empty($filters['after'])) {
            $query->where('c.created_at', '>=', $filters['after']);
        }

        // Before date
        if (!empty($filters['before'])) {
            $query->where('c.created_at', '<=', $filters['before']);
        }

        // Has attachments
        if (!empty($filters['attachments'])) {
            if ($filters['attachments'] === 'yes') {
                $query->where('c.has_attachments', '=', 1);
            } elseif ($filters['attachments'] === 'no') {
                $query->where('c.has_attachments', '=', 0);
            }
        }

        // Has replies filter (new)
        if (!empty($filters['has_replies'])) {
            if ($filters['has_replies'] === 'yes') {
                $query->where('c.threads_count', '>', 1);
            } elseif ($filters['has_replies'] === 'no') {
                $query->where('c.threads_count', '<=', 1);
            }
        }

        // Type filter
        if (!empty($filters['type'])) {
            $query->where('c.type', '=', $filters['type']);
        }

        // Customer filter
        if (!empty($filters['customer'])) {
            $query->where('c.customer_id', '=', $filters['customer']);
        }

        return $query;
    }

    /**
     * Apply date range filter.
     */
    protected function applyDateRangeFilter($query, $range)
    {
        $now = now();

        switch ($range) {
            case 'today':
                $query->whereDate('c.created_at', $now->toDateString());
                break;
            case 'week':
                $query->where('c.created_at', '>=', $now->startOfWeek());
                break;
            case 'month':
                $query->where('c.created_at', '>=', $now->startOfMonth());
                break;
            case 'quarter':
                $query->where('c.created_at', '>=', $now->startOfQuarter());
                break;
            case 'year':
                $query->where('c.created_at', '>=', $now->startOfYear());
                break;
        }

        return $query;
    }

    /**
     * Add additional OR WHERE clauses for extended search.
     */
    public function addOrWhereClauses($queryBuilder, $filters, $query)
    {
        // This hook allows adding additional search conditions
        return $queryBuilder;
    }

    /**
     * Apply advanced filters hook.
     */
    public function applyAdvancedFilters($queryBuilder, $filters, $query)
    {
        return $this->applyFilters($queryBuilder, $filters, auth()->user());
    }

    /**
     * Index a conversation for search.
     */
    public function indexConversation($conversation)
    {
        if (!config('improvedsearch.enable_fulltext')) {
            return;
        }

        try {
            DB::table('search_index')->updateOrInsert(
                ['conversation_id' => $conversation->id],
                [
                    'mailbox_id' => $conversation->mailbox_id,
                    'customer_id' => $conversation->customer_id,
                    'subject' => $conversation->subject,
                    'customer_email' => $conversation->customer_email,
                    'customer_name' => $conversation->customer ? $conversation->customer->getFullName() : '',
                    'preview' => $conversation->preview,
                    'status' => $conversation->status,
                    'state' => $conversation->state,
                    'created_at' => $conversation->created_at,
                    'updated_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            \Log::error('ImprovedSearch: Failed to index conversation ' . $conversation->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Index a thread for search.
     */
    public function indexThread($thread)
    {
        if (!config('improvedsearch.enable_fulltext') || !$thread->conversation_id) {
            return;
        }

        try {
            // Update the conversation's body_index with all thread bodies
            $bodies = Thread::where('conversation_id', $thread->conversation_id)
                ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
                ->pluck('body')
                ->implode(' ');

            // Strip HTML tags and truncate
            $plainText = strip_tags($bodies);
            $plainText = substr($plainText, 0, 65000);

            DB::table('search_index')
                ->where('conversation_id', $thread->conversation_id)
                ->update([
                    'body_text' => $plainText,
                    'updated_at' => now(),
                ]);
        } catch (\Exception $e) {
            \Log::error('ImprovedSearch: Failed to index thread for conversation ' . $thread->conversation_id . ': ' . $e->getMessage());
        }
    }

    /**
     * Track search history.
     */
    public function trackSearchHistory($query, $user, $resultsCount)
    {
        if (empty($query) || strlen($query) < 2) {
            return;
        }

        try {
            DB::table('search_history')->insert([
                'user_id' => $user->id,
                'query' => substr($query, 0, 255),
                'results_count' => $resultsCount,
                'created_at' => now(),
            ]);

            // Cleanup old entries
            $maxHistory = config('improvedsearch.max_history', 50);
            $count = DB::table('search_history')
                ->where('user_id', $user->id)
                ->count();

            if ($count > $maxHistory) {
                $idsToDelete = DB::table('search_history')
                    ->where('user_id', $user->id)
                    ->orderBy('created_at')
                    ->limit($count - $maxHistory)
                    ->pluck('id');

                DB::table('search_history')->whereIn('id', $idsToDelete)->delete();
            }
        } catch (\Exception $e) {
            \Log::error('ImprovedSearch: Failed to track search history: ' . $e->getMessage());
        }
    }

    /**
     * Get search suggestions based on history and popular searches.
     */
    public function getSuggestions($query, $user, $limit = 5)
    {
        if (strlen($query) < 2) {
            return [];
        }

        $likeOperator = \Helper::isPgSql() ? 'ILIKE' : 'LIKE';

        // Get suggestions from user's history
        $userSuggestions = DB::table('search_history')
            ->where('user_id', $user->id)
            ->where('query', $likeOperator, $query . '%')
            ->where('results_count', '>', 0)
            ->groupBy('query')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit($limit)
            ->pluck('query')
            ->toArray();

        // Get popular suggestions from all users
        $popularSuggestions = DB::table('search_history')
            ->where('query', $likeOperator, $query . '%')
            ->where('results_count', '>', 0)
            ->groupBy('query')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit($limit)
            ->pluck('query')
            ->toArray();

        // Merge and deduplicate
        $suggestions = array_unique(array_merge($userSuggestions, $popularSuggestions));

        return array_slice($suggestions, 0, $limit);
    }

    /**
     * Parse search query into terms.
     */
    protected function parseSearchTerms($query)
    {
        $query = trim($query);

        // Extract quoted phrases
        $phrases = [];
        if (preg_match_all('/"([^"]+)"/', $query, $matches)) {
            $phrases = $matches[1];
            $query = preg_replace('/"[^"]+"/', '', $query);
        }

        // Split remaining query into words
        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);

        return array_merge($phrases, $words);
    }

    /**
     * Prepare query string for MySQL FULLTEXT boolean mode.
     */
    protected function prepareFullTextQuery($query)
    {
        $terms = $this->parseSearchTerms($query);
        $prepared = [];

        foreach ($terms as $term) {
            // Add + for required terms, * for prefix matching
            $term = preg_replace('/[^\w\s]/', '', $term);
            if (strlen($term) >= 3) {
                $prepared[] = '+' . $term . '*';
            }
        }

        return implode(' ', $prepared);
    }

    /**
     * Check if full-text search is available.
     */
    protected function isFullTextAvailable()
    {
        // Check if search_index table exists and has fulltext indexes
        try {
            return DB::getSchemaBuilder()->hasTable('search_index');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get accessible mailbox IDs for user.
     */
    protected function getAccessibleMailboxIds($user, $filters)
    {
        $mailboxIds = $user->mailboxesIdsCanView();

        // If specific mailbox filter is set
        if (!empty($filters['mailbox'])) {
            $filteredId = $filters['mailbox'];
            if (in_array($filteredId, $mailboxIds)) {
                return [$filteredId];
            }
            return [];
        }

        return $mailboxIds;
    }

    /**
     * Generate cache key for search results.
     */
    protected function getCacheKey($query, $filters, $userId)
    {
        $filterHash = md5(json_encode($filters));
        return "improvedsearch:{$userId}:{$filterHash}:" . md5($query);
    }

    /**
     * Clear search cache for a user.
     */
    public function clearCache($userId = null)
    {
        if ($userId) {
            // Clear specific user's cache
            Cache::forget("improvedsearch:{$userId}:*");
        } else {
            // Clear all search caches (requires cache tags support)
            Cache::flush();
        }
    }

    /**
     * Rebuild the entire search index.
     */
    public function rebuildIndex($progressCallback = null)
    {
        $total = Conversation::count();
        $processed = 0;

        Conversation::with('customer')->chunk(100, function ($conversations) use (&$processed, $progressCallback, $total) {
            foreach ($conversations as $conversation) {
                $this->indexConversation($conversation);
                $processed++;

                if ($progressCallback && $processed % 100 === 0) {
                    $progressCallback($processed, $total);
                }
            }
        });

        return $processed;
    }
}
