<?php

namespace Modules\ImprovedSearch\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Factory;
use Modules\ImprovedSearch\Services\SearchService;
use Modules\ImprovedSearch\Console\RebuildSearchIndexCommand;

class ImprovedSearchServiceProvider extends ServiceProvider
{
    /**
     * Boot the application events.
     */
    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->registerHooks();
        $this->registerCommands();
        $this->registerPublicAssets();
        $this->createAssetSymlink();
    }

    /**
     * Create symlink for public assets if it doesn't exist.
     */
    protected function createAssetSymlink()
    {
        $target = __DIR__.'/../Public';
        $link = public_path('modules/improvedsearch');

        if (!file_exists($link) && file_exists($target)) {
            $parentDir = dirname($link);
            if (!file_exists($parentDir)) {
                @mkdir($parentDir, 0755, true);
            }
            @symlink($target, $link);
        }
    }

    /**
     * Register console commands.
     */
    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildSearchIndexCommand::class,
            ]);
        }
    }

    /**
     * Register public assets symlink.
     */
    protected function registerPublicAssets()
    {
        $this->publishes([
            __DIR__.'/../Public' => public_path('modules/improvedsearch'),
        ], 'public');
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        $this->app->register(RouteServiceProvider::class);

        // Register SearchService as singleton
        $this->app->singleton(SearchService::class, function ($app) {
            return new SearchService();
        });
    }

    /**
     * Register config.
     */
    protected function registerConfig()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php', 'improvedsearch'
        );
    }

    /**
     * Register views.
     */
    protected function registerViews()
    {
        $viewPath = resource_path('views/modules/improvedsearch');
        $sourcePath = __DIR__.'/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath
        ], 'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/improvedsearch';
        }, \Config::get('view.paths')), [$sourcePath]), 'improvedsearch');
    }

    /**
     * Register Eventy hooks to extend search functionality.
     * Note: We don't override the main search to maintain compatibility.
     * Instead we add suggestions, history tracking, and extra features.
     */
    protected function registerHooks()
    {
        // Override the main search with improved relevance-ranked search
        // Returns a LengthAwarePaginator to match FreeScout's expected format
        \Eventy::addFilter('search.conversations.perform', function ($result, $query, $filters, $user) {
            // If result is already set by another module, don't override
            if ($result !== '' && $result !== null) {
                return $result;
            }

            $searchService = app(SearchService::class);
            return $searchService->performSearch($query, $filters, $user);
        }, 20, 4);

        // Hook into conversation save to update search index
        \Eventy::addAction('conversation.created', function ($conversation) {
            if (config('improvedsearch.index_mode') === 'realtime') {
                $searchService = app(SearchService::class);
                $searchService->indexConversation($conversation);
            }
        }, 20, 1);

        \Eventy::addAction('conversation.updated', function ($conversation) {
            if (config('improvedsearch.index_mode') === 'realtime') {
                $searchService = app(SearchService::class);
                $searchService->indexConversation($conversation);
            }
        }, 20, 1);

        // Hook into thread creation to update index
        \Eventy::addAction('thread.created', function ($thread) {
            if (config('improvedsearch.index_mode') === 'realtime') {
                $searchService = app(SearchService::class);
                $searchService->indexThread($thread);
            }
        }, 20, 1);

        // Add module settings page
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections['improvedsearch'] = [
                'title' => __('Improved Search'),
                'icon' => 'search',
                'order' => 350,
            ];
            return $sections;
        }, 20, 1);

        // Add JavaScript for search enhancements - try multiple hooks
        $jsCallback = function () {
            $jsPath = base_path('Modules/ImprovedSearch/Public/js/search.js');
            $version = file_exists($jsPath) ? filemtime($jsPath) : time();
            echo '<script src="'.asset('modules/improvedsearch/js/search.js').'?v='.$version.'"></script>';
        };

        // Try different hook names that FreeScout might use
        \Eventy::addAction('javascripts', $jsCallback, 20);
        \Eventy::addAction('scripts', $jsCallback, 20);
        \Eventy::addFilter('layout.body_bottom', function ($html) use ($jsCallback) {
            ob_start();
            $jsCallback();
            return $html . ob_get_clean();
        }, 20);
        \Eventy::addFilter('layout.footer', function ($html) use ($jsCallback) {
            ob_start();
            $jsCallback();
            return $html . ob_get_clean();
        }, 20);

        // Track search history
        \Eventy::addAction('search.performed', function ($query, $user, $resultsCount) {
            if (config('improvedsearch.track_history')) {
                $searchService = app(SearchService::class);
                $searchService->trackSearchHistory($query, $user, $resultsCount);
            }
        }, 20, 3);
    }

    /**
     * Extend the filters list with additional options.
     */
    protected function extendFiltersList($filtersList, $mode, $filters, $query)
    {
        if ($mode !== 'customers') {
            // Add relevance sorting option
            $filtersList[] = [
                'type' => 'relevance',
                'label' => __('Sort by Relevance'),
                'values' => [
                    '' => __('Default'),
                    'relevance' => __('Relevance'),
                    'date_desc' => __('Newest First'),
                    'date_asc' => __('Oldest First'),
                ],
            ];

            // Add date range filter
            $filtersList[] = [
                'type' => 'date_range',
                'label' => __('Date Range'),
                'values' => [
                    '' => __('Any Time'),
                    'today' => __('Today'),
                    'week' => __('This Week'),
                    'month' => __('This Month'),
                    'quarter' => __('This Quarter'),
                    'year' => __('This Year'),
                ],
            ];

            // Add has replies filter
            $filtersList[] = [
                'type' => 'has_replies',
                'label' => __('Has Replies'),
                'values' => [
                    '' => __('Any'),
                    'yes' => __('Yes'),
                    'no' => __('No'),
                ],
            ];
        }

        return $filtersList;
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides()
    {
        return [SearchService::class];
    }
}
