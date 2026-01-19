/**
 * Improved Search - FreeScout Module
 * Enhances the search experience with autocomplete, suggestions, and date picker
 */
(function() {
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
            // Try multiple selectors to find FreeScout's search input
            var selectors = [
                'input[name="q"]',
                '#search-query',
                '.search-query',
                '#q',
                'input.form-control[type="text"]',
                '.navbar-form input[type="text"]',
                '.search-form input[type="text"]',
                'form[action*="search"] input[type="text"]'
            ];

            for (var i = 0; i < selectors.length; i++) {
                this.searchInput = document.querySelector(selectors[i]);
                if (this.searchInput) {
                    console.log('ImprovedSearch: Found search input with selector:', selectors[i]);
                    break;
                }
            }

            if (!this.searchInput) {
                console.log('ImprovedSearch: No search input found');
                return;
            }

            this.createSuggestionsContainer();
            this.createDatePicker();
            this.bindEvents();
            console.log('ImprovedSearch: Initialized successfully');
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
            console.log('ImprovedSearch: Creating date picker');

            // Create date picker button (small icon inside the input area)
            this.datePickerBtn = document.createElement('button');
            this.datePickerBtn.type = 'button';
            this.datePickerBtn.className = 'improved-search-date-btn';
            this.datePickerBtn.innerHTML = '<i class="glyphicon glyphicon-calendar"></i>';
            this.datePickerBtn.title = 'Date filters';
            this.datePickerBtn.style.cssText = 'position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#888;cursor:pointer;padding:2px 5px;z-index:10;';

            // Create date picker panel
            this.datePickerPanel = document.createElement('div');
            this.datePickerPanel.className = 'improved-search-date-panel';
            this.datePickerPanel.style.cssText = 'display:none;position:absolute;background:#fff;border:1px solid #ddd;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.15);z-index:1001;padding:15px;min-width:280px;right:0;top:100%;margin-top:5px;';

            this.datePickerPanel.innerHTML = this.getDatePickerHTML();

            // Add button and panel to the input's parent
            var inputParent = this.searchInput.parentElement;
            inputParent.style.position = 'relative';
            inputParent.appendChild(this.datePickerBtn);
            inputParent.appendChild(this.datePickerPanel);
            console.log('ImprovedSearch: Date picker button appended to', inputParent.tagName);

            // Adjust input padding to make room for button
            this.searchInput.style.paddingRight = '30px';

            // Bind date picker events
            this.datePickerBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.toggleDatePicker();
            });

            // Bind quick filter clicks
            var quickFilters = this.datePickerPanel.querySelectorAll('.date-quick-filter');
            quickFilters.forEach(function(filter) {
                filter.addEventListener('click', function(e) {
                    e.preventDefault();
                    self.applyDateFilter(this.dataset.filter);
                });
            });

            // Bind custom date inputs
            var applyCustomBtn = this.datePickerPanel.querySelector('.apply-custom-date');
            if (applyCustomBtn) {
                applyCustomBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    self.applyCustomDate();
                });
            }

            // Close on outside click
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
            if (this.datePickerPanel.style.display === 'none') {
                this.datePickerPanel.style.display = 'block';
            } else {
                this.hideDatePicker();
            }
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
            if (afterInput.value) {
                filters.push('after:' + afterInput.value);
            }
            if (beforeInput.value) {
                filters.push('before:' + beforeInput.value);
            }

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

            // Remove existing date operators from the query
            currentValue = currentValue.replace(/\b(after|before|last):\S+\s*/gi, '').trim();

            // Add the new operator
            if (currentValue) {
                this.searchInput.value = operator + ' ' + currentValue;
            } else {
                this.searchInput.value = operator;
            }
        },

        bindEvents: function() {
            var self = this;

            this.searchInput.addEventListener('input', function(e) {
                clearTimeout(self.debounceTimer);
                self.debounceTimer = setTimeout(function() {
                    self.handleInput(e.target.value);
                }, self.debounceDelay);
            });

            this.searchInput.addEventListener('focus', function(e) {
                if (e.target.value.length >= self.minQueryLength) {
                    self.handleInput(e.target.value);
                }
            });

            this.searchInput.addEventListener('blur', function() {
                setTimeout(function() {
                    self.hideSuggestions();
                }, 200);
            });

            this.searchInput.addEventListener('keydown', function(e) {
                self.handleKeydown(e);
            });

            document.addEventListener('click', function(e) {
                if (!self.searchInput.contains(e.target) && !self.suggestionsContainer.contains(e.target)) {
                    self.hideSuggestions();
                }
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
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(suggestions) {
                self.showSuggestions(suggestions, query);
            })
            .catch(function(error) {
                console.error('ImprovedSearch: Failed to fetch suggestions', error);
            });
        },

        showSuggestions: function(suggestions, query) {
            if (!suggestions || suggestions.length === 0) {
                this.hideSuggestions();
                return;
            }

            var html = '';
            var self = this;

            suggestions.forEach(function(suggestion, index) {
                var highlighted = self.highlightMatch(suggestion, query);
                html += '<div class="improved-search-suggestion" data-index="' + index + '" data-value="' + self.escapeHtml(suggestion) + '" style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #eee;">' + highlighted + '</div>';
            });

            this.suggestionsContainer.innerHTML = html;
            this.suggestionsContainer.style.display = 'block';

            var items = this.suggestionsContainer.querySelectorAll('.improved-search-suggestion');
            items.forEach(function(item) {
                item.addEventListener('click', function() {
                    self.selectSuggestion(this.dataset.value);
                });

                item.addEventListener('mouseover', function() {
                    this.style.backgroundColor = '#f5f5f5';
                });

                item.addEventListener('mouseout', function() {
                    this.style.backgroundColor = '';
                });
            });
        },

        hideSuggestions: function() {
            this.suggestionsContainer.style.display = 'none';
            this.suggestionsContainer.innerHTML = '';
        },

        selectSuggestion: function(value) {
            this.searchInput.value = value;
            this.hideSuggestions();

            // Trigger search form submit
            var form = this.searchInput.closest('form');
            if (form) {
                form.submit();
            }
        },

        handleKeydown: function(e) {
            var items = this.suggestionsContainer.querySelectorAll('.improved-search-suggestion');

            if (items.length === 0) {
                return;
            }

            var current = this.suggestionsContainer.querySelector('.improved-search-suggestion.active');
            var currentIndex = current ? parseInt(current.dataset.index) : -1;

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (currentIndex < items.length - 1) {
                        this.setActiveItem(items, currentIndex + 1);
                    }
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    if (currentIndex > 0) {
                        this.setActiveItem(items, currentIndex - 1);
                    }
                    break;

                case 'Enter':
                    if (current) {
                        e.preventDefault();
                        this.selectSuggestion(current.dataset.value);
                    }
                    break;

                case 'Escape':
                    this.hideSuggestions();
                    this.hideDatePicker();
                    break;
            }
        },

        setActiveItem: function(items, index) {
            items.forEach(function(item) {
                item.classList.remove('active');
                item.style.backgroundColor = '';
            });

            if (items[index]) {
                items[index].classList.add('active');
                items[index].style.backgroundColor = '#f5f5f5';
                items[index].scrollIntoView({ block: 'nearest' });
            }
        },

        highlightMatch: function(text, query) {
            var regex = new RegExp('(' + this.escapeRegex(query) + ')', 'gi');
            return text.replace(regex, '<strong>$1</strong>');
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

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            ImprovedSearch.init();
        });
    } else {
        ImprovedSearch.init();
    }
})();
