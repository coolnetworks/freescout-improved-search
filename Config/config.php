<?php

return [
    'name' => 'ImprovedSearch',

    // Enable full-text search indexing (requires search_index table with FULLTEXT indexes)
    // Set to false to use enhanced LIKE search instead (recommended, works without extra setup)
    'enable_fulltext' => false,

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
];
