(function () {
    'use strict';

    function initDashboard(container) {
        var tabs = Array.prototype.slice.call(container.querySelectorAll('[data-dashboard-tab]'));
        var panels = Array.prototype.slice.call(container.querySelectorAll('[data-dashboard-panel]'));
        var searchInput = container.querySelector('[data-dashboard-search]');

        function activateTab(targetId, trigger) {
            tabs.forEach(function (tab) {
                var isActive = tab === trigger || tab.getAttribute('data-target') === targetId;
                tab.classList.toggle('is-active', isActive);
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            panels.forEach(function (panel) {
                var isActive = panel.getAttribute('id') === targetId;
                panel.classList.toggle('is-active', isActive);
                if (isActive) {
                    panel.removeAttribute('hidden');
                } else {
                    panel.setAttribute('hidden', 'hidden');
                }
            });
        }

        function updateSearchResults() {
            if (!searchInput) {
                return;
            }

            var term = searchInput.value.trim().toLowerCase();

            panels.forEach(function (panel) {
                var cards = Array.prototype.slice.call(panel.querySelectorAll('[data-booking-card]'));
                var matches = 0;

                cards.forEach(function (card) {
                    var haystack = card.getAttribute('data-search-values') || '';
                    var isMatch = term.length === 0 || haystack.indexOf(term) !== -1;
                    card.style.display = isMatch ? '' : 'none';
                    if (isMatch) {
                        matches++;
                    }
                });

                var emptyNotice = panel.querySelector('[data-empty-message]');
                if (emptyNotice) {
                    emptyNotice.style.display = matches === 0 ? '' : 'none';
                }
            });
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                var targetId = tab.getAttribute('data-target');
                if (!targetId) {
                    return;
                }

                activateTab(targetId, tab);
                updateSearchResults();
            });
        });

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                updateSearchResults();
            });
        }

        if (tabs.length > 0) {
            var activeTab = null;
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].classList.contains('is-active')) {
                    activeTab = tabs[i];
                    break;
                }
            }

            var initialTarget = activeTab ? activeTab.getAttribute('data-target') : tabs[0].getAttribute('data-target');
            activateTab(initialTarget, activeTab || tabs[0]);
        }

        updateSearchResults();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var dashboards = Array.prototype.slice.call(document.querySelectorAll('[data-dashboard-container]'));
        dashboards.forEach(function (dashboard) {
            initDashboard(dashboard);
        });
    });
})();
