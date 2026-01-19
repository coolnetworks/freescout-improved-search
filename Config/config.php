<?php

return [
    'name' => 'ImprovedSearch',

    // Search engine: 'mysql' (default) or 'meilisearch'
    // MySQL: No external dependencies, uses FULLTEXT indexes
    // Meilisearch: Requires external server, faster for large datasets
    'engine' => env('IMPROVED_SEARCH_ENGINE', 'mysql'),

    // Enable MySQL FULLTEXT search (faster, requires running migration)
    // Run: php artisan migrate to create FULLTEXT indexes
    'enable_fulltext' => true,

    // Enable fuzzy matching with SOUNDEX (finds phonetically similar words)
    // Helps find results even with typos (e.g., "Jon" matches "John")
    'enable_fuzzy' => true,

    // Minimum characters to trigger search
    'min_query_length' => 2,

    // Maximum results per page
    'results_per_page' => 50,

    // Enable search suggestions
    'enable_suggestions' => true,

    // Cache search results (minutes)
    'cache_duration' => 5,

    // Fields to search with their weights (higher = more important)
    'search_weights' => [
        'subject' => 10,
        'customer_email' => 8,
        'customer_name' => 6,
        'body' => 4,
        'thread_from' => 3,
        'thread_to' => 2,
        'thread_cc' => 1,
    ],

    // Enable search history tracking
    'track_history' => true,

    // Maximum search history entries per user
    'max_history' => 50,

    // Index update mode: 'realtime', 'queue', 'scheduled'
    'index_mode' => 'realtime',

    // Meilisearch configuration (only used when engine = 'meilisearch')
    'meilisearch' => [
        // Meilisearch server URL (cloud or self-hosted)
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),

        // API key for Meilisearch (required for cloud, optional for local)
        'key' => env('MEILISEARCH_KEY', ''),

        // Index name for conversations
        'index' => env('MEILISEARCH_INDEX', 'freescout_conversations'),

        // Typo tolerance settings
        'typo_tolerance' => [
            'enabled' => true,
            'min_word_size_one_typo' => 4,
            'min_word_size_two_typos' => 8,
        ],
    ],
];
