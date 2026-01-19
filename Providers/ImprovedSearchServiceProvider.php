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

        // Add JavaScript for search enhancements using layout.body_bottom action
        $script = $this->getSearchScript();
        \Eventy::addAction('layout.body_bottom', function () use ($script) {
            echo '<script type="text/javascript">' . $script . '</script>';
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
     * Get inline JavaScript for search enhancements.
     */
    protected function getSearchScript()
    {
        return <<<'JS'
(function(){
    'use strict';
    var ImprovedSearch = {
        suggestionsUrl: '/improvedsearch/suggestions',
        debounceTimer: null,
        debounceDelay: 300,
        minQueryLength: 2,
        suggestionsContainer: null,
        searchInput: null,
        datePickerPanel: null,
        datePickerBtn: null,

        init: function() {
            console.log('ImprovedSearch: init started');
            var selectors = [
                'input[name="q"]',
                '#search-query',
                '.search-query',
                '#q',
                '.navbar-form input[type="text"]',
                '.search-form input[type="text"]',
                'form[action*="search"] input[type="text"]'
            ];
            for (var i = 0; i < selectors.length; i++) {
                this.searchInput = document.querySelector(selectors[i]);
                if (this.searchInput) {
                    console.log('ImprovedSearch: Found input with selector:', selectors[i]);
                    break;
                }
            }
            if (!this.searchInput) {
                console.log('ImprovedSearch: No search input found');
                return;
            }
            console.log('ImprovedSearch: Creating components');
            this.createSuggestionsContainer();
            this.createDatePicker();
            this.bindEvents();
            console.log('ImprovedSearch: Init complete');
        },

        createSuggestionsContainer: function() {
            this.suggestionsContainer = document.createElement('div');
            this.suggestionsContainer.className = 'improved-search-suggestions';
            this.suggestionsContainer.style.cssText = 'display:none;position:absolute;background:#fff;border:1px solid #ddd;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.15);max-height:300px;overflow-y:auto;z-index:1000;width:100%;top:100%;left:0;';
            var parent = this.searchInput.parentElement;
            parent.style.position = 'relative';
            parent.appendChild(this.suggestionsContainer);
        },

        createDatePicker: function() {
            var self = this;
            console.log('ImprovedSearch: createDatePicker started');

            this.datePickerBtn = document.createElement('button');
            this.datePickerBtn.type = 'button';
            this.datePickerBtn.className = 'improved-search-date-btn';
            this.datePickerBtn.innerHTML = '<i class="glyphicon glyphicon-calendar"></i>';
            this.datePickerBtn.title = 'Date filters';
            this.datePickerBtn.style.cssText = 'position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#888;cursor:pointer;padding:2px 5px;z-index:10;';
            console.log('ImprovedSearch: Button created:', this.datePickerBtn);

            this.datePickerPanel = document.createElement('div');
            this.datePickerPanel.className = 'improved-search-date-panel';
            this.datePickerPanel.style.cssText = 'display:none;position:absolute;background:#fff;border:1px solid #ddd;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.15);z-index:1001;padding:15px;min-width:280px;right:0;top:100%;margin-top:5px;';
            this.datePickerPanel.innerHTML = this.getDatePickerHTML();

            var inputParent = this.searchInput.parentElement;
            console.log('ImprovedSearch: Parent element:', inputParent, 'tagName:', inputParent ? inputParent.tagName : 'null');
            inputParent.style.position = 'relative';
            inputParent.appendChild(this.datePickerBtn);
            inputParent.appendChild(this.datePickerPanel);
            this.searchInput.style.paddingRight = '30px';
            console.log('ImprovedSearch: Button appended, checking if in DOM:', document.contains(this.datePickerBtn));

            this.datePickerBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.toggleDatePicker();
            });

            var quickFilters = this.datePickerPanel.querySelectorAll('.date-quick-filter');
            quickFilters.forEach(function(filter) {
                filter.addEventListener('click', function(e) {
                    e.preventDefault();
                    self.applyDateFilter(this.dataset.filter);
                });
            });

            var applyCustomBtn = this.datePickerPanel.querySelector('.apply-custom-date');
            if (applyCustomBtn) {
                applyCustomBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    self.applyCustomDate();
                });
            }

            document.addEventListener('click', function(e) {
                if (!self.datePickerPanel.contains(e.target) && e.target !== self.datePickerBtn && !self.datePickerBtn.contains(e.target)) {
                    self.hideDatePicker();
                }
            });
        },

        getDatePickerHTML: function() {
            return '<div class="date-picker-content">' +
                '<div style="margin-bottom:12px;font-weight:600;color:#333;">Quick Filters</div>' +
                '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:15px;">' +
                    '<a href="#" class="btn btn-xs btn-default date-quick-filter" data-filter="last:today">Today</a>' +
                    '<a href="#" class="btn btn-xs btn-default date-quick-filter" data-filter="last:yesterday">Yesterday</a>' +
                    '<a href="#" class="btn btn-xs btn-default date-quick-filter" data-filter="last:week">Last Week</a>' +
                    '<a href="#" class="btn btn-xs btn-default date-quick-filter" data-filter="last:month">Last Month</a>' +
                '</div>' +
                '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:15px;">' +
                    '<a href="#" class="btn btn-xs btn-default date-quick-filter" data-filter="after:7days">Past 7 Days</a>' +
                    '<a href="#" class="btn btn-xs btn-default date-quick-filter" data-filter="after:30days">Past 30 Days</a>' +
                    '<a href="#" class="btn btn-xs btn-default date-quick-filter" data-filter="after:90days">Past 90 Days</a>' +
                '</div>' +
                '<hr style="margin:10px 0;">' +
                '<div style="margin-bottom:8px;font-weight:600;color:#333;">Custom Range</div>' +
                '<div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;">' +
                    '<div style="flex:1;">' +
                        '<label style="font-size:11px;color:#666;display:block;margin-bottom:3px;">After</label>' +
                        '<input type="date" class="form-control input-sm date-after" style="width:100%;">' +
                    '</div>' +
                    '<div style="flex:1;">' +
                        '<label style="font-size:11px;color:#666;display:block;margin-bottom:3px;">Before</label>' +
                        '<input type="date" class="form-control input-sm date-before" style="width:100%;">' +
                    '</div>' +
                '</div>' +
                '<button class="btn btn-primary btn-sm apply-custom-date" style="width:100%;">Apply</button>' +
                '<hr style="margin:10px 0;">' +
                '<div style="font-size:11px;color:#888;">' +
                    '<div style="margin-bottom:4px;font-weight:600;">Tip: Type operators directly</div>' +
                    '<code style="font-size:10px;">last:friday</code>, <code style="font-size:10px;">after:2024-01-01</code>, <code style="font-size:10px;">before:lastmonth</code>' +
                '</div>' +
            '</div>';
        },

        toggleDatePicker: function() {
            this.datePickerPanel.style.display = this.datePickerPanel.style.display === 'none' ? 'block' : 'none';
        },

        hideDatePicker: function() {
            this.datePickerPanel.style.display = 'none';
        },

        applyDateFilter: function(filter) {
            this.insertOperator(filter);
            this.hideDatePicker();
            this.searchInput.focus();
        },

        applyCustomDate: function() {
            var afterInput = this.datePickerPanel.querySelector('.date-after');
            var beforeInput = this.datePickerPanel.querySelector('.date-before');
            var filters = [];
            if (afterInput.value) filters.push('after:' + afterInput.value);
            if (beforeInput.value) filters.push('before:' + beforeInput.value);
            if (filters.length > 0) {
                this.insertOperator(filters.join(' '));
                afterInput.value = '';
                beforeInput.value = '';
            }
            this.hideDatePicker();
            this.searchInput.focus();
        },

        insertOperator: function(operator) {
            var currentValue = this.searchInput.value.trim();
            currentValue = currentValue.replace(/\b(after|before|last):\S+\s*/gi, '').trim();
            this.searchInput.value = currentValue ? operator + ' ' + currentValue : operator;
        },

        bindEvents: function() {
            var self = this;
            this.searchInput.addEventListener('input', function(e) {
                clearTimeout(self.debounceTimer);
                self.debounceTimer = setTimeout(function() {
                    self.handleInput(e.target.value);
                }, self.debounceDelay);
            });
            this.searchInput.addEventListener('blur', function() {
                setTimeout(function() { self.hideSuggestions(); }, 200);
            });
            this.searchInput.addEventListener('keydown', function(e) {
                self.handleKeydown(e);
            });
        },

        handleInput: function(query) {
            if (query.length < this.minQueryLength) {
                this.hideSuggestions();
                return;
            }
            this.fetchSuggestions(query);
        },

        fetchSuggestions: function(query) {
            var self = this;
            fetch(this.suggestionsUrl + '?q=' + encodeURIComponent(query), {
                method: 'GET',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
            .then(function(r) { return r.json(); })
            .then(function(suggestions) { self.showSuggestions(suggestions, query); })
            .catch(function(e) { console.error('ImprovedSearch error', e); });
        },

        showSuggestions: function(suggestions, query) {
            if (!suggestions || suggestions.length === 0) {
                this.hideSuggestions();
                return;
            }
            var html = '';
            var self = this;
            suggestions.forEach(function(s, i) {
                html += '<div class="improved-search-suggestion" data-index="' + i + '" data-value="' + self.escapeHtml(s) + '" style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #eee;">' + self.highlightMatch(s, query) + '</div>';
            });
            this.suggestionsContainer.innerHTML = html;
            this.suggestionsContainer.style.display = 'block';
            this.suggestionsContainer.querySelectorAll('.improved-search-suggestion').forEach(function(item) {
                item.addEventListener('click', function() { self.selectSuggestion(this.dataset.value); });
                item.addEventListener('mouseover', function() { this.style.backgroundColor = '#f5f5f5'; });
                item.addEventListener('mouseout', function() { this.style.backgroundColor = ''; });
            });
        },

        hideSuggestions: function() {
            this.suggestionsContainer.style.display = 'none';
            this.suggestionsContainer.innerHTML = '';
        },

        selectSuggestion: function(value) {
            this.searchInput.value = value;
            this.hideSuggestions();
            var form = this.searchInput.closest('form');
            if (form) form.submit();
        },

        handleKeydown: function(e) {
            var items = this.suggestionsContainer.querySelectorAll('.improved-search-suggestion');
            if (items.length === 0) return;
            var current = this.suggestionsContainer.querySelector('.improved-search-suggestion.active');
            var idx = current ? parseInt(current.dataset.index) : -1;
            if (e.key === 'ArrowDown' && idx < items.length - 1) { e.preventDefault(); this.setActiveItem(items, idx + 1); }
            else if (e.key === 'ArrowUp' && idx > 0) { e.preventDefault(); this.setActiveItem(items, idx - 1); }
            else if (e.key === 'Enter' && current) { e.preventDefault(); this.selectSuggestion(current.dataset.value); }
            else if (e.key === 'Escape') { this.hideSuggestions(); this.hideDatePicker(); }
        },

        setActiveItem: function(items, index) {
            items.forEach(function(item) { item.classList.remove('active'); item.style.backgroundColor = ''; });
            if (items[index]) { items[index].classList.add('active'); items[index].style.backgroundColor = '#f5f5f5'; }
        },

        highlightMatch: function(text, query) {
            return text.replace(new RegExp('(' + this.escapeRegex(query) + ')', 'gi'), '<strong>$1</strong>');
        },

        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        escapeRegex: function(text) {
            return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { ImprovedSearch.init(); });
    } else {
        ImprovedSearch.init();
    }
})();
JS;
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides()
    {
        return [SearchService::class];
    }
}
