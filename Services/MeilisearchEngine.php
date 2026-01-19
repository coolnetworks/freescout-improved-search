<?php

namespace Modules\ImprovedSearch\Services;

use App\Conversation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * Meilisearch search engine for improved search.
 * Optional backend - requires meilisearch/meilisearch-php package.
 *
 * To enable:
 * 1. Install: composer require meilisearch/meilisearch-php
 * 2. Set IMPROVED_SEARCH_ENGINE=meilisearch in .env
 * 3. Set MEILISEARCH_HOST and MEILISEARCH_KEY in .env
 * 4. Run: php artisan improvedsearch:index to build initial index
 */
class MeilisearchEngine
{
    protected $client = null;
    protected $index = null;
    protected $configured = false;

    public function __construct()
    {
        $this->configured = $this->initializeClient();
    }

    /**
     * Check if Meilisearch is available and configured.
     */
    public function isAvailable()
    {
        return $this->configured && $this->client !== null;
    }

    /**
     * Initialize the Meilisearch client.
     */
    protected function initializeClient()
    {
        // Check if meilisearch package is installed
        if (!class_exists('\Meilisearch\Client')) {
            Log::warning('ImprovedSearch: Meilisearch package not installed. Run: composer require meilisearch/meilisearch-php');
            return false;
        }

        $host = config('improvedsearch.meilisearch.host');
        $key = config('improvedsearch.meilisearch.key');

        if (empty($host)) {
            return false;
        }

        try {
            $this->client = new \Meilisearch\Client($host, $key);
            $this->index = $this->client->index(config('improvedsearch.meilisearch.index', 'freescout_conversations'));
            return true;
        } catch (\Exception $e) {
            Log::error('ImprovedSearch: Failed to connect to Meilisearch: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Perform a search using Meilisearch.
     */
    public function search($query, $filters, $mailboxIds, $page = 1, $perPage = 50)
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            // Build Meilisearch filter string
            $filterParts = [];

            // Mailbox filter
            if (!empty($mailboxIds)) {
                $filterParts[] = 'mailbox_id IN [' . implode(',', $mailboxIds) . ']';
            }

            // Status filter
            if (!empty($filters['status'])) {
                $filterParts[] = 'status = ' . (int) $filters['status'];
            }

            // Date filters
            if (!empty($filters['after'])) {
                $timestamp = strtotime($filters['after']);
                if ($timestamp) {
                    $filterParts[] = 'created_at >= ' . $timestamp;
                }
            }

            if (!empty($filters['before'])) {
                $timestamp = strtotime($filters['before']);
                if ($timestamp) {
                    $filterParts[] = 'created_at <= ' . $timestamp;
                }
            }

            // Assigned filter
            if (!empty($filters['assigned'])) {
                if ($filters['assigned'] === 'unassigned') {
                    $filterParts[] = 'user_id IS NULL';
                } elseif (is_numeric($filters['assigned'])) {
                    $filterParts[] = 'user_id = ' . (int) $filters['assigned'];
                }
            }

            // Has attachments
            if (!empty($filters['attachments']) && $filters['attachments'] === 'yes') {
                $filterParts[] = 'has_attachments = true';
            }

            $searchParams = [
                'limit' => $perPage,
                'offset' => ($page - 1) * $perPage,
                'attributesToRetrieve' => ['id', 'conversation_id'],
                'attributesToHighlight' => ['subject', 'body_text', 'customer_email'],
                'highlightPreTag' => '<mark>',
                'highlightPostTag' => '</mark>',
            ];

            if (!empty($filterParts)) {
                $searchParams['filter'] = implode(' AND ', $filterParts);
            }

            $results = $this->index->search($query, $searchParams);

            // Get conversation IDs from results
            $conversationIds = array_column($results->getHits(), 'conversation_id');

            if (empty($conversationIds)) {
                return new LengthAwarePaginator([], 0, $perPage, $page);
            }

            // Fetch actual Conversation models
            $conversations = Conversation::whereIn('id', $conversationIds)
                ->orderByRaw('FIELD(id, ' . implode(',', $conversationIds) . ')')
                ->get();

            return new LengthAwarePaginator(
                $conversations,
                $results->getEstimatedTotalHits(),
                $perPage,
                $page,
                ['path' => request()->url()]
            );
        } catch (\Exception $e) {
            Log::error('ImprovedSearch: Meilisearch search failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Index a conversation in Meilisearch.
     */
    public function indexConversation($conversation)
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $document = $this->buildDocument($conversation);
            $this->index->addDocuments([$document], 'id');
            return true;
        } catch (\Exception $e) {
            Log::error('ImprovedSearch: Failed to index conversation ' . $conversation->id . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a conversation from the index.
     */
    public function deleteConversation($conversationId)
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $this->index->deleteDocument($conversationId);
            return true;
        } catch (\Exception $e) {
            Log::error('ImprovedSearch: Failed to delete conversation ' . $conversationId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build the full index from scratch.
     */
    public function rebuildIndex($progressCallback = null)
    {
        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => 'Meilisearch not available'];
        }

        try {
            // Configure index settings
            $this->configureIndex();

            // Clear existing index
            $this->index->deleteAllDocuments();

            // Index all conversations in batches
            $batchSize = 100;
            $total = Conversation::count();
            $indexed = 0;

            Conversation::with(['customer', 'threads'])->chunk($batchSize, function ($conversations) use (&$indexed, $total, $progressCallback) {
                $documents = [];

                foreach ($conversations as $conversation) {
                    $documents[] = $this->buildDocument($conversation);
                    $indexed++;
                }

                $this->index->addDocuments($documents, 'id');

                if ($progressCallback) {
                    $progressCallback($indexed, $total);
                }
            });

            return ['success' => true, 'indexed' => $indexed];
        } catch (\Exception $e) {
            Log::error('ImprovedSearch: Failed to rebuild index: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Configure index settings for optimal search.
     */
    protected function configureIndex()
    {
        $settings = [
            'searchableAttributes' => [
                'subject',
                'customer_email',
                'customer_name',
                'body_text',
                'thread_from',
            ],
            'filterableAttributes' => [
                'mailbox_id',
                'customer_id',
                'user_id',
                'status',
                'state',
                'has_attachments',
                'created_at',
            ],
            'sortableAttributes' => [
                'created_at',
                'updated_at',
            ],
            'rankingRules' => [
                'words',
                'typo',
                'proximity',
                'attribute',
                'sort',
                'exactness',
            ],
            'typoTolerance' => config('improvedsearch.meilisearch.typo_tolerance', [
                'enabled' => true,
                'minWordSizeForTypos' => [
                    'oneTypo' => 4,
                    'twoTypos' => 8,
                ],
            ]),
        ];

        $this->index->updateSettings($settings);
    }

    /**
     * Build a document for indexing.
     */
    protected function buildDocument($conversation)
    {
        $bodyText = '';
        $threadFrom = '';

        if ($conversation->threads) {
            foreach ($conversation->threads as $thread) {
                if ($thread->body) {
                    $bodyText .= ' ' . strip_tags($thread->body);
                }
                if ($thread->from && empty($threadFrom)) {
                    $threadFrom = $thread->from;
                }
            }
        }

        $customerName = '';
        $customerEmail = $conversation->customer_email ?? '';

        if ($conversation->customer) {
            $customerName = trim($conversation->customer->first_name . ' ' . $conversation->customer->last_name);
            if (empty($customerEmail)) {
                $customerEmail = $conversation->customer->email ?? '';
            }
        }

        return [
            'id' => $conversation->id,
            'conversation_id' => $conversation->id,
            'number' => $conversation->number,
            'mailbox_id' => $conversation->mailbox_id,
            'customer_id' => $conversation->customer_id,
            'user_id' => $conversation->user_id,
            'subject' => $conversation->subject ?? '',
            'customer_email' => $customerEmail,
            'customer_name' => $customerName,
            'body_text' => trim($bodyText),
            'thread_from' => $threadFrom,
            'status' => $conversation->status,
            'state' => $conversation->state,
            'has_attachments' => (bool) $conversation->has_attachments,
            'created_at' => $conversation->created_at ? $conversation->created_at->timestamp : 0,
            'updated_at' => $conversation->updated_at ? $conversation->updated_at->timestamp : 0,
        ];
    }

    /**
     * Get index statistics.
     */
    public function getStats()
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            return $this->index->getStats();
        } catch (\Exception $e) {
            return null;
        }
    }
}
