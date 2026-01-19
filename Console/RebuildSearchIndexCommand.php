<?php

namespace Modules\ImprovedSearch\Console;

use Illuminate\Console\Command;
use Modules\ImprovedSearch\Services\SearchService;

class RebuildSearchIndexCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'improvedsearch:rebuild
                            {--clear : Clear existing index before rebuilding}';

    /**
     * The console command description.
     */
    protected $description = 'Rebuild the search index for all conversations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $searchService = app(SearchService::class);

        if ($this->option('clear')) {
            $this->info('Clearing existing search index...');
            \DB::table('search_index')->truncate();
        }

        $this->info('Rebuilding search index...');

        $progressBar = null;

        $processed = $searchService->rebuildIndex(function ($current, $total) use (&$progressBar) {
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

        $this->info("Search index rebuilt successfully. Processed {$processed} conversations.");

        return 0;
    }
}
