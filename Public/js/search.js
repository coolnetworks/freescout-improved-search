/**
 * Improved Search - FreeScout Module
 * Enhances the search experience with autocomplete and suggestions
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

        init: function() {
            this.searchInput = document.querySelector('input[name="q"], #search-input, .search-input');

            if (!this.searchInput) {
                return;
            }

            this.createSuggestionsContainer();
            this.bindEvents();
        },

        createSuggestionsContainer: function() {
            this.suggestionsContainer = document.createElement('div');
            this.suggestionsContainer.className = 'improved-search-suggestions';
            this.suggestionsContainer.style.cssText = 'display:none;position:absolute;background:#fff;border:1px solid #ddd;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.15);max-height:300px;overflow-y:auto;z-index:1000;width:100%;';

            var parent = this.searchInput.parentElement;
            parent.style.position = 'relative';
            parent.appendChild(this.suggestionsContainer);
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
