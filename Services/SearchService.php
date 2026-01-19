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

        // Parse last:day - filters to that specific day only (e.g., last:friday = just last Friday)
        if (preg_match('/\blast:(\S+)/i', $query, $matches)) {
            $date = $this->parseDate($matches[1]);
            if ($date) {
                $operators['after'] = $date->format('Y-m-d 00:00:00');
                $operators['before'] = $date->format('Y-m-d 23:59:59');
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
     * Enhanced LIKE search with relevance ranking.
     * Returns a LengthAwarePaginator with Conversation models.
     */
    protected function enhancedLikeSearch($searchTerms, $originalQuery, $filters, $mailboxIds, $user)
    {
        $likeOperator = \Helper::isPgSql() ? 'ILIKE' : 'LIKE';
        $perPage = config('improvedsearch.results_per_page', 50);
        $page = request()->input('page', 1);

        // Build the base query using Eloquent for proper model hydration
        $query = Conversation::select('conversations.*')
            ->leftJoin('threads', 'conversations.id', '=', 'threads.conversation_id')
            ->leftJoin('customers', 'conversations.customer_id', '=', 'customers.id')
            ->whereIn('conversations.mailbox_id', $mailboxIds);

        // Add search conditions only if there are search terms
        if (!empty($searchTerms)) {
            $query->where(function ($q) use ($searchTerms, $originalQuery, $likeOperator) {
                foreach ($searchTerms as $term) {
                    $termPattern = '%' . $term . '%';

                    $q->orWhere('conversations.subject', $likeOperator, $termPattern)
                        ->orWhere('conversations.customer_email', $likeOperator, $termPattern)
                        ->orWhere('threads.body', $likeOperator, $termPattern)
                        ->orWhere('threads.from', $likeOperator, $termPattern)
                        ->orWhere('threads.to', $likeOperator, $termPattern)
                        ->orWhere('threads.cc', $likeOperator, $termPattern)
                        ->orWhere('threads.bcc', $likeOperator, $termPattern)
                        ->orWhere('customers.first_name', $likeOperator, $termPattern)
                        ->orWhere('customers.last_name', $likeOperator, $termPattern);
                }

                // Exact match on conversation number/id
                $numericQuery = trim($originalQuery);
                if (is_numeric($numericQuery)) {
                    $q->orWhere('conversations.number', '=', $numericQuery)
                        ->orWhere('conversations.id', '=', $numericQuery);
                }
            });
        }

        // Apply standard filters
        $query = $this->applyFiltersToEloquent($query, $filters, $user);

        // Group by conversation ID to avoid duplicates from joins
        $query->groupBy('conversations.id');

        // Order by relevance (subject matches first) then by date
        $query->orderByRaw("CASE WHEN conversations.subject {$likeOperator} ? THEN 0 ELSE 1 END", ['%' . $originalQuery . '%'])
            ->orderByDesc('conversations.updated_at');

        // Return paginated results - this is what FreeScout expects
        return $query->paginate($perPage);
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
     * Get search suggestions based on history.
     */
    public function getSuggestions($query, $user, $limit = 5)
    {
        if (strlen($query) < 2) {
            return [];
        }

        try {
            // Check if search_history table exists
            if (!DB::getSchemaBuilder()->hasTable('search_history')) {
                return [];
            }

            $likeOperator = \Helper::isPgSql() ? 'ILIKE' : 'LIKE';

            $suggestions = DB::table('search_history')
                ->where('user_id', $user->id)
                ->where('query', $likeOperator, $query . '%')
                ->where('results_count', '>', 0)
                ->groupBy('query')
                ->orderByRaw('COUNT(*) DESC')
                ->limit($limit)
                ->pluck('query')
                ->toArray();

            return $suggestions;
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
     * Index a conversation for search (placeholder for future fulltext).
     */
    public function indexConversation($conversation)
    {
        // Placeholder - fulltext indexing disabled for now
    }

    /**
     * Index a thread for search (placeholder for future fulltext).
     */
    public function indexThread($thread)
    {
        // Placeholder - fulltext indexing disabled for now
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
