<?php

namespace Modules\ImprovedSearch\Console;

use Illuminate\Console\Command;
use Modules\ImprovedSearch\Services\SearchService;
use Modules\ImprovedSearch\Services\MeilisearchEngine;

class RebuildSearchIndexCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'improvedsearch:rebuild
                            {--clear : Clear existing index before rebuilding}
                            {--engine= : Force specific engine (mysql or meilisearch)}';

    /**
     * The console command description.
     */
    protected $description = 'Rebuild the search index for all conversations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $engine = $this->option('engine') ?: config('improvedsearch.engine', 'mysql');

        if ($engine === 'meilisearch') {
            return $this->rebuildMeilisearch();
        }

        return $this->rebuildMysql();
    }

    /**
     * Rebuild MySQL-based index.
     */
    protected function rebuildMysql()
    {
        $searchService = app(SearchService::class);

        if ($this->option('clear')) {
            $this->info('Clearing existing search index...');
            try {
                \DB::table('search_index')->truncate();
            } catch (\Exception $e) {
                $this->warn('No search_index table to clear (using direct query mode)');
            }
        }

        $this->info('MySQL FULLTEXT search enabled - no index rebuild needed.');
        $this->info('Search queries source tables directly for real-time results.');

        // Clear cache to ensure fresh results
        $searchService->clearCache();
        $this->info('Search cache cleared.');

        return 0;
    }

    /**
     * Rebuild Meilisearch index.
     */
    protected function rebuildMeilisearch()
    {
        $meilisearch = new MeilisearchEngine();

        if (!$meilisearch->isAvailable()) {
            $this->error('Meilisearch is not available. Check your configuration:');
            $this->line('  1. Install package: composer require meilisearch/meilisearch-php');
            $this->line('  2. Set MEILISEARCH_HOST in .env');
            $this->line('  3. Set MEILISEARCH_KEY in .env (if using cloud)');
            return 1;
        }

        $this->info('Rebuilding Meilisearch index...');

        $progressBar = null;

        $result = $meilisearch->rebuildIndex(function ($current, $total) use (&$progressBar) {
            if (!$progressBar) {
                $progressBar = $this->output->createProgressBar($total);
                $progressBar->start();
            }
            $progressBar->setProgress($current);
        });

        if ($progressBar) {
            $progressBar->finish();
            $this->newLine();
        }

        if ($result['success']) {
            $this->info("Meilisearch index rebuilt successfully. Indexed {$result['indexed']} conversations.");
            return 0;
        } else {
            $this->error('Failed to rebuild index: ' . ($result['error'] ?? 'Unknown error'));
            return 1;
        }
    }
}
