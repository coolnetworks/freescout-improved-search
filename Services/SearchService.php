<?php

namespace Modules\ImprovedSearch\Services;

use App\Conversation;
use App\Thread;
use App\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;

class SearchService
{
    /**
     * Supported search operators.
     */
    protected $operators = [
        'after', 'before', 'from', 'to', 'status', 'has', 'mailbox', 'assigned'
    ];

    /**
     * Perform an enhanced search across conversations.
     * Returns a LengthAwarePaginator to match FreeScout's expected format.
     */
    public function performSearch($query, $filters, $user)
    {
        if (strlen($query) < config('improvedsearch.min_query_length', 2)) {
            // Return empty string to fall back to default FreeScout search
            return '';
        }

        try {
            return $this->executeSearch($query, $filters, $user);
        } catch (\Exception $e) {
            \Log::error('ImprovedSearch: Search failed - ' . $e->getMessage());
            // Return empty string to fall back to default search on error
            return '';
        }
    }

    /**
     * Execute the actual search query.
     * Returns a LengthAwarePaginator with Conversation models.
     */
    protected function executeSearch($query, $filters, $user)
    {
        // Parse search operators from query
        $parsed = $this->parseSearchOperators($query);
        $searchTerms = $this->parseSearchTerms($parsed['query']);
        $operators = $parsed['operators'];

        // Merge parsed operators into filters
        $filters = array_merge($filters, $operators);

        $mailboxIds = $this->getAccessibleMailboxIds($user, $filters);

        if (empty($mailboxIds)) {
            // Return empty paginator
            return new LengthAwarePaginator([], 0, 50, 1);
        }

        return $this->enhancedLikeSearch($searchTerms, $parsed['query'], $filters, $mailboxIds, $user);
    }

    /**
     * Parse search operators from query string.
     * Supports: after:date, before:date, from:email, status:open, has:attachment
     */
    protected function parseSearchOperators($query)
    {
        $operators = [];
        $cleanQuery = $query;

        // Parse after:date
        if (preg_match('/\bafter:(\S+)/i', $query, $matches)) {
            $date = $this->parseDate($matches[1]);
            if ($date) {
                $operators['after'] = $date->format('Y-m-d 00:00:00');
            }
            $cleanQuery = str_replace($matches[0], '', $cleanQuery);
        }

        // Parse last:period - filters to that specific period (day, week, month, year)
        if (preg_match('/\blast:(\S+)/i', $query, $matches)) {
            $period = strtolower($matches[1]);
            $range = $this->parseDateRange($period);
            if ($range) {
                $operators['after'] = $range['start'];
                $operators['before'] = $range['end'];
            }
            $cleanQuery = str_replace($matches[0], '', $cleanQuery);
        }

        // Parse before:date
        if (preg_match('/\bbefore:(\S+)/i', $query, $matches)) {
            $date = $this->parseDate($matches[1]);
            if ($date) {
                $operators['before'] = $date->format('Y-m-d 23:59:59');
            }
            $cleanQuery = str_replace($matches[0], '', $cleanQuery);
        }

        // Parse from:email
        if (preg_match('/\bfrom:(\S+)/i', $query, $matches)) {
            $operators['from_email'] = $matches[1];
            $cleanQuery = str_replace($matches[0], '', $cleanQuery);
        }

        // Parse to:email
        if (preg_match('/\bto:(\S+)/i', $query, $matches)) {
            $operators['to_email'] = $matches[1];
            $cleanQuery = str_replace($matches[0], '', $cleanQuery);
        }

        // Parse status:open|closed|pending|spam
        if (preg_match('/\bstatus:(\S+)/i', $query, $matches)) {
            $operators['status_name'] = strtolower($matches[1]);
            $cleanQuery = str_replace($matches[0], '', $cleanQuery);
        }

        // Parse has:attachment
        if (preg_match('/\bhas:attachment/i', $query, $matches)) {
            $operators['attachments'] = 'yes';
            $cleanQuery = str_replace($matches[0], '', $cleanQuery);
        }

        // Parse assigned:me|unassigned|username
        if (preg_match('/\bassigned:(\S+)/i', $query, $matches)) {
            $operators['assigned'] = strtolower($matches[1]);
            $cleanQuery = str_replace($matches[0], '', $cleanQuery);
        }

        return [
            'query' => trim($cleanQuery),
            'operators' => $operators,
        ];
    }

    /**
     * Parse a period string into start and end dates for last: operator.
     * Returns array with 'start' and 'end' keys.
     */
    protected function parseDateRange($period)
    {
        try {
            switch ($period) {
                case 'week':
                    // Last week (Mon-Sun of previous week)
                    $start = Carbon::now()->subWeek()->startOfWeek();
                    $end = Carbon::now()->subWeek()->endOfWeek();
                    return ['start' => $start->format('Y-m-d 00:00:00'), 'end' => $end->format('Y-m-d 23:59:59')];

                case 'month':
                    // Last month
                    $start = Carbon::now()->subMonth()->startOfMonth();
                    $end = Carbon::now()->subMonth()->endOfMonth();
                    return ['start' => $start->format('Y-m-d 00:00:00'), 'end' => $end->format('Y-m-d 23:59:59')];

                case 'year':
                    // Last year
                    $start = Carbon::now()->subYear()->startOfYear();
                    $end = Carbon::now()->subYear()->endOfYear();
                    return ['start' => $start->format('Y-m-d 00:00:00'), 'end' => $end->format('Y-m-d 23:59:59')];

                case 'today':
                    $date = Carbon::today();
                    return ['start' => $date->format('Y-m-d 00:00:00'), 'end' => $date->format('Y-m-d 23:59:59')];

                case 'yesterday':
                    $date = Carbon::yesterday();
                    return ['start' => $date->format('Y-m-d 00:00:00'), 'end' => $date->format('Y-m-d 23:59:59')];
            }

            // Day names (friday, monday, etc.) - just that specific day
            $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
            if (in_array($period, $days)) {
                $date = Carbon::parse("last {$period}");
                return ['start' => $date->format('Y-m-d 00:00:00'), 'end' => $date->format('Y-m-d 23:59:59')];
            }

            // Try parsing as a specific date
            $date = Carbon::parse($period);
            return ['start' => $date->format('Y-m-d 00:00:00'), 'end' => $date->format('Y-m-d 23:59:59')];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Parse a date string into Carbon instance.
     * Supports: YYYY-MM-DD, today, yesterday, last week, last month, etc.
     */
    protected function parseDate($dateStr)
    {
        $dateStr = strtolower(trim($dateStr));

        try {
            // Relative dates
            switch ($dateStr) {
                case 'today':
                    return Carbon::today();
                case 'yesterday':
                    return Carbon::yesterday();
                case 'tomorrow':
                    return Carbon::tomorrow();
                case 'week':
                case 'thisweek':
                case 'this-week':
                    return Carbon::now()->startOfWeek();
                case 'lastweek':
                case 'last-week':
                    return Carbon::now()->subWeek()->startOfWeek();
                case 'month':
                case 'thismonth':
                case 'this-month':
                    return Carbon::now()->startOfMonth();
                case 'lastmonth':
                case 'last-month':
                    return Carbon::now()->subMonth()->startOfMonth();
                case 'year':
                case 'thisyear':
                case 'this-year':
                    return Carbon::now()->startOfYear();
                case 'lastyear':
                case 'last-year':
                    return Carbon::now()->subYear()->startOfYear();
            }

            // Try relative formats like "7days", "2weeks", "3months"
            if (preg_match('/^(\d+)(days?|weeks?|months?|years?)$/i', $dateStr, $matches)) {
                $num = (int) $matches[1];
                $unit = strtolower($matches[2]);

                if (strpos($unit, 'day') !== false) {
                    return Carbon::now()->subDays($num);
                } elseif (strpos($unit, 'week') !== false) {
                    return Carbon::now()->subWeeks($num);
                } elseif (strpos($unit, 'month') !== false) {
                    return Carbon::now()->subMonths($num);
                } elseif (strpos($unit, 'year') !== false) {
                    return Carbon::now()->subYears($num);
                }
            }

            // Day names (last monday, last friday, etc.)
            $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
            if (in_array($dateStr, $days)) {
                return Carbon::parse("last {$dateStr}");
            }

            // Try standard date formats
            return Carbon::parse($dateStr);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Map status name to status constant.
     */
    protected function mapStatusName($statusName)
    {
        $map = [
            'active' => Conversation::STATUS_ACTIVE,
            'open' => Conversation::STATUS_ACTIVE,
            'pending' => Conversation::STATUS_PENDING,
            'closed' => Conversation::STATUS_CLOSED,
            'spam' => Conversation::STATUS_SPAM,
        ];

        return $map[$statusName] ?? null;
    }

    /**
     * Check if FULLTEXT indexes exist on the tables.
     */
    protected function hasFulltextIndexes()
    {
        static $hasIndexes = null;

        if ($hasIndexes !== null) {
            return $hasIndexes;
        }

        if (\Helper::isPgSql()) {
            $hasIndexes = false;
            return false;
        }

        try {
            $result = DB::select("SHOW INDEX FROM conversations WHERE Key_name = 'conversations_subject_ft'");
            $hasIndexes = !empty($result);
        } catch (\Exception $e) {
            $hasIndexes = false;
        }

        return $hasIndexes;
    }

    /**
     * Enhanced search with FULLTEXT support, fuzzy matching, and relevance scoring.
     * Returns a LengthAwarePaginator with Conversation models.
     */
    protected function enhancedLikeSearch($searchTerms, $originalQuery, $filters, $mailboxIds, $user)
    {
        $perPage = config('improvedsearch.results_per_page', 50);
        $useFulltext = config('improvedsearch.enable_fulltext', false) && $this->hasFulltextIndexes();
        $cacheDuration = config('improvedsearch.cache_duration', 5);

        // Generate cache key for this search
        $cacheKey = $this->getCacheKey($originalQuery, $filters, $mailboxIds, request()->get('page', 1));

        // Try to get cached results
        if ($cacheDuration > 0) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Build the base query using Eloquent for proper model hydration
        $query = Conversation::select('conversations.*')
            ->leftJoin('threads', 'conversations.id', '=', 'threads.conversation_id')
            ->leftJoin('customers', 'conversations.customer_id', '=', 'customers.id')
            ->whereIn('conversations.mailbox_id', $mailboxIds);

        // Add search conditions only if there are search terms
        if (!empty($searchTerms) && !empty($originalQuery)) {
            if ($useFulltext && !$this->isPgSql()) {
                // Use MySQL FULLTEXT search for speed with relevance scoring
                $query = $this->applyFulltextSearchWithRelevance($query, $originalQuery, $searchTerms);
            } else {
                // Fall back to enhanced LIKE search with fuzzy matching
                $query = $this->applyLikeSearchWithRelevance($query, $searchTerms, $originalQuery);
            }
        }

        // Apply standard filters
        $query = $this->applyFiltersToEloquent($query, $filters, $user);

        // Group by conversation ID to avoid duplicates from joins
        $query->groupBy('conversations.id');

        // Return paginated results - this is what FreeScout expects
        $results = $query->paginate($perPage);

        // Cache the results
        if ($cacheDuration > 0) {
            Cache::put($cacheKey, $results, now()->addMinutes($cacheDuration));
        }

        return $results;
    }

    /**
     * Generate a cache key for search results.
     */
    protected function getCacheKey($query, $filters, $mailboxIds, $page)
    {
        $filterHash = md5(serialize($filters) . serialize($mailboxIds));
        return 'improved_search_' . md5($query) . '_' . $filterHash . '_p' . $page;
    }

    /**
     * Apply MySQL FULLTEXT search with relevance scoring.
     * Results are ordered by relevance score: exact matches > subject matches > body matches.
     */
    protected function applyFulltextSearchWithRelevance($query, $originalQuery, $searchTerms)
    {
        $booleanQuery = $this->prepareFulltextQuery($originalQuery);
        $naturalQuery = $this->prepareNaturalQuery($originalQuery);

        // Add relevance score columns for ordering
        $query->selectRaw("
            (
                CASE WHEN conversations.subject = ? THEN 100 ELSE 0 END +
                CASE WHEN conversations.customer_email = ? THEN 80 ELSE 0 END +
                CASE WHEN conversations.number = ? THEN 90 ELSE 0 END +
                IFNULL(MATCH(conversations.subject) AGAINST(? IN NATURAL LANGUAGE MODE), 0) * 10 +
                IFNULL(MATCH(threads.body) AGAINST(? IN NATURAL LANGUAGE MODE), 0) * 2
            ) as relevance_score
        ", [$originalQuery, $originalQuery, $originalQuery, $naturalQuery, $naturalQuery]);

        $query->where(function ($q) use ($booleanQuery, $originalQuery, $searchTerms) {
            // FULLTEXT search on subject
            $q->whereRaw("MATCH(conversations.subject) AGAINST(? IN BOOLEAN MODE)", [$booleanQuery]);

            // FULLTEXT search on thread body
            $q->orWhereRaw("MATCH(threads.body) AGAINST(? IN BOOLEAN MODE)", [$booleanQuery]);

            // Also search email and names with LIKE for exact matching
            $likePattern = '%' . $originalQuery . '%';
            $q->orWhere('conversations.customer_email', 'LIKE', $likePattern)
              ->orWhere('threads.from', 'LIKE', $likePattern)
              ->orWhere('customers.first_name', 'LIKE', $likePattern)
              ->orWhere('customers.last_name', 'LIKE', $likePattern);

            // Exact match on conversation number/id
            if (is_numeric(trim($originalQuery))) {
                $q->orWhere('conversations.number', '=', trim($originalQuery))
                  ->orWhere('conversations.id', '=', trim($originalQuery));
            }

            // Partial word matching for better typo tolerance
            foreach ($searchTerms as $term) {
                if (strlen($term) >= 3) {
                    // Match word beginnings (like autocomplete)
                    $q->orWhere('conversations.subject', 'LIKE', $term . '%')
                      ->orWhere('conversations.subject', 'LIKE', '% ' . $term . '%');
                }
            }
        });

        // Order by relevance score (best matches first), then by date
        $query->orderByDesc('relevance_score')
              ->orderByDesc('conversations.updated_at');

        return $query;
    }

    /**
     * Prepare query for NATURAL LANGUAGE MODE (for relevance scoring).
     */
    protected function prepareNaturalQuery($query)
    {
        // Just clean up and return the query for natural language mode
        return preg_replace('/[+\-><\(\)~*\"@]+/', ' ', trim($query));
    }

    /**
     * Apply MySQL FULLTEXT search (legacy method for compatibility).
     */
    protected function applyFulltextSearch($query, $originalQuery, $searchTerms)
    {
        return $this->applyFulltextSearchWithRelevance($query, $originalQuery, $searchTerms);
    }

    /**
     * Prepare query for FULLTEXT BOOLEAN MODE.
     * Adds wildcards for partial matching.
     */
    protected function prepareFulltextQuery($query)
    {
        $terms = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        $prepared = [];

        foreach ($terms as $term) {
            // Skip very short terms (MySQL minimum is usually 3-4 chars)
            if (strlen($term) < 2) {
                continue;
            }

            // Escape special FULLTEXT characters
            $term = preg_replace('/[+\-><\(\)~*\"@]+/', ' ', $term);
            $term = trim($term);

            if (!empty($term)) {
                // Add wildcard for partial matching
                $prepared[] = '+' . $term . '*';
            }
        }

        // If no valid terms, return original query
        if (empty($prepared)) {
            return $query . '*';
        }

        return implode(' ', $prepared);
    }

    /**
     * Apply LIKE search with relevance scoring and enhanced fuzzy matching.
     */
    protected function applyLikeSearchWithRelevance($query, $searchTerms, $originalQuery)
    {
        $likeOperator = \Helper::isPgSql() ? 'ILIKE' : 'LIKE';
        $useFuzzy = config('improvedsearch.enable_fuzzy', true);

        // Add relevance scoring for non-FULLTEXT searches
        $relevanceCase = $this->buildLikeRelevanceScore($searchTerms, $originalQuery);
        if (!empty($relevanceCase)) {
            $query->selectRaw("({$relevanceCase}) as relevance_score");
        }

        $query->where(function ($q) use ($searchTerms, $originalQuery, $likeOperator, $useFuzzy) {
            foreach ($searchTerms as $term) {
                $termPattern = '%' . $term . '%';

                // Standard LIKE search
                $q->orWhere('conversations.subject', $likeOperator, $termPattern)
                    ->orWhere('conversations.customer_email', $likeOperator, $termPattern)
                    ->orWhere('threads.body', $likeOperator, $termPattern)
                    ->orWhere('threads.from', $likeOperator, $termPattern)
                    ->orWhere('threads.to', $likeOperator, $termPattern)
                    ->orWhere('threads.cc', $likeOperator, $termPattern)
                    ->orWhere('threads.bcc', $likeOperator, $termPattern)
                    ->orWhere('customers.first_name', $likeOperator, $termPattern)
                    ->orWhere('customers.last_name', $likeOperator, $termPattern);

                // Enhanced fuzzy matching for typos
                if ($useFuzzy && strlen($term) >= 3) {
                    if (!$this->isPgSql()) {
                        // SOUNDEX for phonetic similarity (handles most typos)
                        $q->orWhereRaw("SOUNDEX(conversations.subject) = SOUNDEX(?)", [$term])
                          ->orWhereRaw("SOUNDEX(customers.first_name) = SOUNDEX(?)", [$term])
                          ->orWhereRaw("SOUNDEX(customers.last_name) = SOUNDEX(?)", [$term]);
                    }

                    // Generate common typo patterns for additional fuzzy matching
                    $typoPatterns = $this->generateTypoPatterns($term);
                    foreach ($typoPatterns as $pattern) {
                        $q->orWhere('conversations.subject', $likeOperator, '%' . $pattern . '%')
                          ->orWhere('customers.first_name', $likeOperator, '%' . $pattern . '%')
                          ->orWhere('customers.last_name', $likeOperator, '%' . $pattern . '%');
                    }
                }

                // Partial word matching (beginning of words)
                if (strlen($term) >= 2) {
                    $q->orWhere('conversations.subject', $likeOperator, $term . '%')
                      ->orWhere('conversations.subject', $likeOperator, '% ' . $term . '%');
                }
            }

            // Exact match on conversation number/id
            $numericQuery = trim($originalQuery);
            if (is_numeric($numericQuery)) {
                $q->orWhere('conversations.number', '=', $numericQuery)
                    ->orWhere('conversations.id', '=', $numericQuery);
            }
        });

        // Order by relevance, then date
        if (!empty($relevanceCase)) {
            $query->orderByDesc('relevance_score');
        }
        $query->orderByDesc('conversations.updated_at');

        return $query;
    }

    /**
     * Build relevance score SQL for LIKE-based searches.
     */
    protected function buildLikeRelevanceScore($searchTerms, $originalQuery)
    {
        if (empty($searchTerms)) {
            return '0';
        }

        $cases = [];
        $escapedQuery = addslashes($originalQuery);

        // Exact match in subject = highest score
        $cases[] = "CASE WHEN conversations.subject = '{$escapedQuery}' THEN 100 ELSE 0 END";

        // Exact match in email = high score
        $cases[] = "CASE WHEN conversations.customer_email = '{$escapedQuery}' THEN 80 ELSE 0 END";

        // Subject contains exact query = good score
        $cases[] = "CASE WHEN conversations.subject LIKE '%{$escapedQuery}%' THEN 50 ELSE 0 END";

        foreach ($searchTerms as $term) {
            $escapedTerm = addslashes($term);
            // Subject starts with term = moderate score
            $cases[] = "CASE WHEN conversations.subject LIKE '{$escapedTerm}%' THEN 30 ELSE 0 END";
        }

        return implode(' + ', $cases);
    }

    /**
     * Generate common typo patterns for fuzzy matching.
     * Creates patterns that match single-character transpositions, insertions, deletions.
     */
    protected function generateTypoPatterns($term)
    {
        $patterns = [];
        $len = strlen($term);

        // Skip if term too short
        if ($len < 3) {
            return $patterns;
        }

        // Limit number of patterns to avoid query explosion
        $maxPatterns = 3;

        // Character transpositions (swapped adjacent chars) - most common typo
        for ($i = 0; $i < $len - 1 && count($patterns) < $maxPatterns; $i++) {
            $swapped = substr($term, 0, $i) . $term[$i + 1] . $term[$i] . substr($term, $i + 2);
            if ($swapped !== $term) {
                $patterns[] = $swapped;
            }
        }

        // Single character wildcards (missing or wrong char) - handles deletion/substitution
        for ($i = 0; $i < $len && count($patterns) < $maxPatterns; $i++) {
            $patterns[] = substr($term, 0, $i) . '_' . substr($term, $i + 1);
        }

        return array_unique($patterns);
    }

    /**
     * Apply LIKE search with fuzzy matching using SOUNDEX (legacy method).
     */
    protected function applyLikeSearch($query, $searchTerms, $originalQuery)
    {
        return $this->applyLikeSearchWithRelevance($query, $searchTerms, $originalQuery);
    }

    /**
     * Check if using PostgreSQL.
     */
    protected function isPgSql()
    {
        return \Helper::isPgSql();
    }

    /**
     * Apply filters to Eloquent query (not DB query builder).
     */
    protected function applyFiltersToEloquent($query, $filters, $user)
    {
        // Status filter
        if (!empty($filters['status'])) {
            $query->where('conversations.status', '=', $filters['status']);
        }

        // State filter
        if (!empty($filters['state'])) {
            $query->where('conversations.state', '=', $filters['state']);
        }

        // Assigned filter
        if (!empty($filters['assigned'])) {
            if ($filters['assigned'] === 'me') {
                $query->where('conversations.user_id', '=', $user->id);
            } elseif ($filters['assigned'] === 'unassigned') {
                $query->whereNull('conversations.user_id');
            } elseif (is_numeric($filters['assigned'])) {
                $query->where('conversations.user_id', '=', $filters['assigned']);
            }
        }

        // After date
        if (!empty($filters['after'])) {
            $query->where('conversations.created_at', '>=', $filters['after']);
        }

        // Before date
        if (!empty($filters['before'])) {
            $query->where('conversations.created_at', '<=', $filters['before']);
        }

        // Has attachments
        if (!empty($filters['attachments'])) {
            if ($filters['attachments'] === 'yes') {
                $query->where('conversations.has_attachments', '=', 1);
            } elseif ($filters['attachments'] === 'no') {
                $query->where('conversations.has_attachments', '=', 0);
            }
        }

        // Type filter
        if (!empty($filters['type'])) {
            $query->where('conversations.type', '=', $filters['type']);
        }

        // Customer filter
        if (!empty($filters['customer'])) {
            $query->where('conversations.customer_id', '=', $filters['customer']);
        }

        // Subject filter (exact in subject)
        if (!empty($filters['subject'])) {
            $likeOperator = \Helper::isPgSql() ? 'ILIKE' : 'LIKE';
            $query->where('conversations.subject', $likeOperator, '%' . $filters['subject'] . '%');
        }

        // Body filter (search in thread body)
        if (!empty($filters['body'])) {
            $likeOperator = \Helper::isPgSql() ? 'ILIKE' : 'LIKE';
            $query->where('threads.body', $likeOperator, '%' . $filters['body'] . '%');
        }

        // Status by name (from search operator status:open)
        if (!empty($filters['status_name'])) {
            $statusId = $this->mapStatusName($filters['status_name']);
            if ($statusId !== null) {
                $query->where('conversations.status', '=', $statusId);
            }
        }

        // From email filter (from search operator from:email)
        if (!empty($filters['from_email'])) {
            $likeOperator = \Helper::isPgSql() ? 'ILIKE' : 'LIKE';
            $query->where(function ($q) use ($filters, $likeOperator) {
                $q->where('conversations.customer_email', $likeOperator, '%' . $filters['from_email'] . '%')
                    ->orWhere('threads.from', $likeOperator, '%' . $filters['from_email'] . '%');
            });
        }

        // To email filter (from search operator to:email)
        if (!empty($filters['to_email'])) {
            $likeOperator = \Helper::isPgSql() ? 'ILIKE' : 'LIKE';
            $query->where('threads.to', $likeOperator, '%' . $filters['to_email'] . '%');
        }

        return $query;
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
            // Check if search_history table exists
            if (!DB::getSchemaBuilder()->hasTable('search_history')) {
                return;
            }

            DB::table('search_history')->insert([
                'user_id' => $user->id,
                'query' => substr($query, 0, 255),
                'results_count' => $resultsCount ?? 0,
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
     * Get search suggestions based on actual data and history.
     * Returns categorized suggestions: customers, subjects, conversations.
     */
    public function getSuggestions($query, $user, $limit = 8)
    {
        if (strlen($query) < 2) {
            return [];
        }

        try {
            $likeOperator = $this->isPgSql() ? 'ILIKE' : 'LIKE';
            $mailboxIds = $this->getAccessibleMailboxIds($user, []);
            $suggestions = [];

            // 1. Search for matching customer emails
            $customers = DB::table('customers')
                ->where(function ($q) use ($query, $likeOperator) {
                    $q->where('email', $likeOperator, $query . '%')
                      ->orWhere('first_name', $likeOperator, $query . '%')
                      ->orWhere('last_name', $likeOperator, $query . '%');
                })
                ->limit(3)
                ->get(['email', 'first_name', 'last_name']);

            foreach ($customers as $customer) {
                $name = trim($customer->first_name . ' ' . $customer->last_name);
                $suggestions[] = [
                    'type' => 'customer',
                    'text' => $customer->email,
                    'label' => $name ? "{$name} ({$customer->email})" : $customer->email,
                    'query' => 'from:' . $customer->email,
                ];
            }

            // 2. Search for matching conversation numbers (if numeric)
            if (is_numeric($query)) {
                $conversations = Conversation::whereIn('mailbox_id', $mailboxIds)
                    ->where(function ($q) use ($query) {
                        $q->where('number', 'LIKE', $query . '%')
                          ->orWhere('id', '=', $query);
                    })
                    ->limit(3)
                    ->get(['id', 'number', 'subject']);

                foreach ($conversations as $conv) {
                    $suggestions[] = [
                        'type' => 'conversation',
                        'text' => '#' . $conv->number,
                        'label' => "#{$conv->number}: " . \Str::limit($conv->subject, 40),
                        'query' => (string) $conv->number,
                    ];
                }
            }

            // 3. Search for matching subjects
            $subjects = Conversation::whereIn('mailbox_id', $mailboxIds)
                ->where('subject', $likeOperator, '%' . $query . '%')
                ->orderByDesc('updated_at')
                ->limit(3)
                ->get(['id', 'number', 'subject']);

            foreach ($subjects as $conv) {
                // Avoid duplicates
                $exists = false;
                foreach ($suggestions as $s) {
                    if (isset($s['text']) && $s['text'] === '#' . $conv->number) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $suggestions[] = [
                        'type' => 'subject',
                        'text' => \Str::limit($conv->subject, 50),
                        'label' => "#{$conv->number}: " . \Str::limit($conv->subject, 40),
                        'query' => $query,
                    ];
                }
            }

            // 4. Add search history suggestions
            if (DB::getSchemaBuilder()->hasTable('search_history')) {
                $history = DB::table('search_history')
                    ->where('user_id', $user->id)
                    ->where('query', $likeOperator, $query . '%')
                    ->where('results_count', '>', 0)
                    ->groupBy('query')
                    ->orderByRaw('COUNT(*) DESC')
                    ->limit(2)
                    ->pluck('query')
                    ->toArray();

                foreach ($history as $h) {
                    $suggestions[] = [
                        'type' => 'history',
                        'text' => $h,
                        'label' => $h,
                        'query' => $h,
                    ];
                }
            }

            // Limit total suggestions
            return array_slice($suggestions, 0, $limit);
        } catch (\Exception $e) {
            \Log::error('ImprovedSearch: Failed to get suggestions: ' . $e->getMessage());
            return [];
        }
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
     * Highlight search terms in text.
     * Returns text with matching terms wrapped in <mark> tags.
     */
    public function highlightTerms($text, $searchTerms, $maxLength = 200)
    {
        if (empty($text) || empty($searchTerms)) {
            return \Str::limit($text, $maxLength);
        }

        // Find the best snippet containing search terms
        $text = strip_tags($text);
        $snippet = $this->findBestSnippet($text, $searchTerms, $maxLength);

        // Highlight each term
        foreach ($searchTerms as $term) {
            if (strlen($term) >= 2) {
                $pattern = '/(' . preg_quote($term, '/') . ')/i';
                $snippet = preg_replace($pattern, '<mark>$1</mark>', $snippet);
            }
        }

        return $snippet;
    }

    /**
     * Find the best snippet of text containing search terms.
     */
    protected function findBestSnippet($text, $searchTerms, $maxLength)
    {
        // If text is short enough, return it all
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        // Find position of first matching term
        $firstPos = strlen($text);
        foreach ($searchTerms as $term) {
            $pos = stripos($text, $term);
            if ($pos !== false && $pos < $firstPos) {
                $firstPos = $pos;
            }
        }

        // Calculate snippet boundaries
        $start = max(0, $firstPos - ($maxLength / 4));
        $end = min(strlen($text), $start + $maxLength);

        // Adjust start to not cut words
        if ($start > 0) {
            $start = strpos($text, ' ', $start);
            if ($start === false) {
                $start = 0;
            }
        }

        $snippet = substr($text, $start, $end - $start);

        // Add ellipsis if truncated
        if ($start > 0) {
            $snippet = '...' . ltrim($snippet);
        }
        if ($end < strlen($text)) {
            $snippet = rtrim($snippet) . '...';
        }

        return $snippet;
    }

    /**
     * Clear search cache for a specific conversation or all cache.
     */
    public function clearCache($conversationId = null)
    {
        if ($conversationId === null) {
            // Clear all search cache (use pattern matching if cache driver supports it)
            try {
                // For file/database cache, we can't easily clear by pattern
                // So we rely on natural expiration
                Cache::flush();
            } catch (\Exception $e) {
                \Log::warning('ImprovedSearch: Could not clear cache: ' . $e->getMessage());
            }
        }
    }

    /**
     * Index a conversation for search (clears relevant cache).
     */
    public function indexConversation($conversation)
    {
        // Clear cache when conversation is updated
        $this->clearCache($conversation->id ?? null);
    }

    /**
     * Index a thread for search (clears relevant cache).
     */
    public function indexThread($thread)
    {
        // Clear cache when thread is updated
        if ($thread->conversation_id) {
            $this->clearCache($thread->conversation_id);
        }
    }

    /**
     * Add additional OR WHERE clauses (hook support).
     */
    public function addOrWhereClauses($queryBuilder, $filters, $query)
    {
        return $queryBuilder;
    }

    /**
     * Apply advanced filters (hook support).
     */
    public function applyAdvancedFilters($queryBuilder, $filters, $query)
    {
        return $queryBuilder;
    }
}
