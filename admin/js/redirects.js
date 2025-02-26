document.addEventListener('DOMContentLoaded', function() {
    var sdmPluginUrl = (typeof SDM_Data !== 'undefined' && SDM_Data.pluginUrl) ? SDM_Data.pluginUrl : '';

    var currentScreen = document.querySelector('body').className.match(/spintax-manager_page_sdm-redirects/);
    if (!currentScreen) {
        return;
    }

    var mainNonceField = document.getElementById('sdm-main-nonce');
    var mainNonce = mainNonceField ? mainNonceField.value : '';

    var projectSelector = document.getElementById('sdm-project-selector');
    var currentProjectId = projectSelector ? parseInt(projectSelector.value) : 0;

    var isRedirectsLoaded = false;

    let sortDirection = {};
    let lastSortedColumn = null;

    function initializeSortState() {
        sortDirection['domain'] = 'asc';
        sortDirection['redirect_status'] = 'asc';
    }

    function fetchRedirects(projectId, sortColumn = 'domain', sortDirectionParam = 'asc') {
        if (isRedirectsLoaded) {
            console.log('Redirects already loading, skipping...');
            return;
        }
        isRedirectsLoaded = true;

        var formData = new FormData();
        formData.append('action', 'sdm_fetch_redirects_list');
        formData.append('project_id', projectId);
        formData.append('sort_column', sortColumn);
        formData.append('sort_direction', sortDirectionParam);
        formData.append('sdm_main_nonce_field', mainNonce);

        var container = document.getElementById('sdm-redirects-container');
        container.innerHTML = '<p><span class="spinner"></span> Loading...</p>';

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                container.innerHTML = data.data.html;
                initializeDynamicListeners();
                initializeSorting();
                addTooltipsToArrows();
            } else {
                showRedirectsNotice('error', data.data);
            }
            isRedirectsLoaded = false;
        })
        .catch(function(error) {
            console.error('Fetch redirects list error:', error);
            showRedirectsNotice('error', 'Ajax request failed.');
            container.innerHTML = '<p class="error">Error loading redirects.</p>';
            isRedirectsLoaded = false;
        });
    }

    if (currentProjectId > 0) {
        initializeSortState();
        fetchRedirects(currentProjectId);
    }

    if (projectSelector) {
        projectSelector.addEventListener('change', function() {
            isRedirectsLoaded = false;
            if (this.value > 0) {
                initializeSortState();
                fetchRedirects(this.value);
            } else {
                document.getElementById('sdm-redirects-container').innerHTML =
                    '<p style="margin: 20px 0; color: #666;">Please select a project to view its redirects.</p>';
            }
        });
    }

    var syncButton = document.getElementById('sdm-sync-cloudflare');
    if (syncButton) {
        syncButton.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to sync all redirects with CloudFlare for this project?')) return;

            var messageArea = document.getElementById('sdm-cloudflare-message');
            messageArea.innerHTML = '<span class="spinner"></span> Syncing...';
            messageArea.className = 'sdm-status';

            var formData = new FormData();
            formData.append('action', 'sdm_sync_redirects_to_cloudflare');
            formData.append('project_id', currentProjectId);
            formData.append('sdm_main_nonce_field', mainNonce);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    messageArea.innerHTML = 'Sync completed successfully.';
                    messageArea.className = 'sdm-status success';
                    fetchRedirects(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc');
                } else {
                    messageArea.innerHTML = 'Error: ' + data.data;
                    messageArea.className = 'sdm-status error';
                }
            })
            .catch(function(error) {
                console.error('Sync redirects error:', error);
                messageArea.innerHTML = 'Network error.';
                messageArea.className = 'sdm-status error';
            });
        });
    }

    function initializeDynamicListeners() {
        var deleteButtons = document.querySelectorAll('.sdm-delete');
        deleteButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                if (!confirm('Are you sure you want to delete this redirect?')) return;

                var redirectId = this.getAttribute('data-redirect-id');
                var spinner = document.createElement('span');
                spinner.className = 'spinner is-active';
                spinner.style.float = 'none';
                spinner.style.margin = '0 5px';
                this.innerHTML = '';
                this.appendChild(spinner);

                var formData = new FormData();
                formData.append('action', 'sdm_delete_redirect');
                formData.append('redirect_id', redirectId);
                formData.append('sdm_main_nonce_field', mainNonce);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    spinner.remove();
                    if (data.success) {
                        showRedirectsNotice('updated', data.data.message);
                        fetchRedirects(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc');
                    } else {
                        showRedirectsNotice('error', data.data);
                    }
                })
                .catch(function(error) {
                    console.error('Delete redirect error:', error);
                    showRedirectsNotice('error', 'Ajax request failed.');
                    spinner.remove();
                });
            });
        });

        var createButtons = document.querySelectorAll('.sdm-create-redirect');
        createButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                if (!confirm('Are you sure you want to create a default redirect for this domain?')) return;

                var domainId = this.getAttribute('data-domain-id');
                var spinner = document.createElement('span');
                spinner.className = 'spinner is-active';
                spinner.style.float = 'none';
                spinner.style.margin = '0 5px';
                this.innerHTML = '';
                this.appendChild(spinner);

                var formData = new FormData();
                formData.append('action', 'sdm_create_default_redirect');
                formData.append('domain_id', domainId);
                formData.append('project_id', currentProjectId);
                formData.append('sdm_main_nonce_field', mainNonce);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    spinner.remove();
                    if (data.success) {
                        showRedirectsNotice('updated', data.data.message);
                        fetchRedirects(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc');
                    } else {
                        showRedirectsNotice('error', data.data);
                    }
                })
                .catch(function(error) {
                    console.error('Create default redirect error:', error);
                    showRedirectsNotice('error', 'Ajax request failed.');
                    spinner.remove();
                });
            });
        });

        var massActionSelectSite = document.querySelectorAll('.sdm-mass-action-select-site');
        massActionSelectSite.forEach(function(select) {
            var siteId = select.getAttribute('data-site-id');
            var applyBtn = document.querySelector('.sdm-mass-action-apply-site[data-site-id="' + siteId + '"]');
            if (!applyBtn) return;

            applyBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var action = select.value;
                if (!action) {
                    alert('Please select a mass action.');
                    return;
                }
                var selected = [];
                document.querySelectorAll('.sdm-redirect-checkbox[data-site-id="' + siteId + '"]:checked').forEach(function(cb) {
                    selected.push(cb.value);
                });
                if (selected.length === 0) {
                    alert('No redirects selected for this site.');
                    return;
                }

                if (action === 'create_default') {
                    if (!confirm('Are you sure you want to create default redirects for the selected domains in this site?')) return;
                    massActionAPI('sdm_mass_create_default_redirects', selected, applyBtn);
                } else if (action === 'mass_delete') {
                    if (!confirm('Are you sure you want to delete the selected redirects?')) return;
                    massActionAPI('sdm_mass_delete_redirects', selected, applyBtn);
                } else if (action === 'sync_cloudflare') {
                    if (!confirm('Are you sure you want to sync the selected redirects with CloudFlare for this site?')) return;
                    massActionAPI('sdm_mass_sync_redirects_to_cloudflare', selected, applyBtn);
                }
            });
        });

        function massActionAPI(actionName, domainIds, buttonEl) {
            var spinner = document.createElement('span');
            spinner.className = 'spinner is-active';
            spinner.style.float = 'none';
            spinner.style.margin = '0 5px';
            buttonEl.disabled = true;
            var originalText = buttonEl.innerHTML;
            buttonEl.innerHTML = '';
            buttonEl.appendChild(spinner);

            var formData = new FormData();
            formData.append('action', actionName);
            formData.append('domain_ids', JSON.stringify(domainIds));
            formData.append('project_id', currentProjectId);
            formData.append('sdm_main_nonce_field', mainNonce);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                buttonEl.disabled = false;
                buttonEl.innerHTML = originalText;
                if (data.success) {
                    showRedirectsNotice('updated', data.data.message);
                    fetchRedirects(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc');
                } else {
                    showRedirectsNotice('error', data.data);
                }
                spinner.remove();
            })
            .catch(function(error) {
                console.error('Mass action error:', error);
                showRedirectsNotice('error', 'Ajax request failed.');
                buttonEl.disabled = false;
                buttonEl.innerHTML = originalText;
                spinner.remove();
            });
        }

        var selectAllSiteRedirects = document.querySelectorAll('.sdm-select-all-site-redirects');
        selectAllSiteRedirects.forEach(function(checkbox) {
            var siteId = checkbox.getAttribute('data-site-id');
            checkbox.addEventListener('change', function() {
                var checked = this.checked;
                document.querySelectorAll('.sdm-redirect-checkbox[data-site-id="' + siteId + '"]')
                    .forEach(function(cb) {
                        cb.checked = checked;
                    });
            });
        });

        document.body.addEventListener('click', function(e) {
            if (e.target.closest('.sdm-redirect-type-display')) {
                var cell = e.target.closest('.sdm-redirect-type-cell');
                if (!cell) return;
                var selector = cell.querySelector('.sdm-redirect-type-selector');
                if (!selector) return;
                selector.style.display = (selector.style.display === 'none' || !selector.style.display)
                    ? 'inline-block'
                    : 'none';
            }

            if (e.target.closest('.sdm-type-option')) {
                var btn = e.target.closest('.sdm-type-option');
                var newType = btn.getAttribute('data-value');
                var cell = btn.closest('.sdm-redirect-type-cell');
                var redirectId = cell.getAttribute('data-redirect-id');
                var oldType = cell.getAttribute('data-current-type');

                if (!redirectId) return;
                if (newType === oldType) {
                    cell.querySelector('.sdm-redirect-type-selector').style.display = 'none';
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'sdm_update_redirect_type');
                formData.append('redirect_id', redirectId);
                formData.append('new_type', newType);
                formData.append('sdm_main_nonce_field', mainNonce);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        cell.setAttribute('data-current-type', newType);
                        var displaySpan = cell.querySelector('.sdm-redirect-type-display');
                        displaySpan.classList.remove('sdm-redirect-type-' + oldType);
                        displaySpan.classList.add('sdm-redirect-type-' + newType);
                        displaySpan.innerHTML = btn.innerHTML;

                        var targetSpan = cell.querySelector('.sdm-target-domain');
                        if (targetSpan && data.data.target_url) {
                            targetSpan.textContent = data.data.target_url;
                        }

                        cell.querySelector('.sdm-redirect-type-selector').style.display = 'none';
                        showRedirectsNotice('updated', data.data.message);
                    } else {
                        console.error(data.data || 'Error updating redirect type');
                        showRedirectsNotice('error', data.data);
                    }
                })
                .catch(function(error) {
                    console.error('AJAX error:', error);
                    showRedirectsNotice('error', 'Ajax request failed.');
                });
            }
        });
    }

    function initializeSorting() {
        var sortableHeaders = document.querySelectorAll('.sdm-sortable');
        sortableHeaders.forEach(function(header) {
            header.addEventListener('click', function(e) {
                e.preventDefault();
                let columnName = this.dataset.column;
                let direction = sortDirection[columnName] === 'asc' ? 'desc' : 'asc';

                sortableHeaders.forEach(function(col) {
                    if (col !== this) {
                        col.classList.remove('sdm-sorted-asc', 'sdm-sorted-desc');
                        sortDirection[col.dataset.column] = 'asc';
                    }
                }.bind(this));

                this.classList.remove('sdm-sorted-asc', 'sdm-sorted-desc');
                this.classList.add('sdm-sorted-' + direction);
                sortDirection[columnName] = direction;

                if (currentProjectId > 0) {
                    fetchRedirects(currentProjectId, columnName, direction);
                }
                lastSortedColumn = columnName;
            });
        });

        var urlParams = new URLSearchParams(window.location.search);
        var sortColumn = urlParams.get('sort_column') || '';
        var sortDirectionParam = urlParams.get('sort_direction') || 'asc';
        if (sortColumn && ['domain', 'redirect_status'].includes(sortColumn)) {
            var header = document.querySelector('.sdm-sortable[data-column="' + sortColumn + '"]');
            if (header) {
                header.classList.remove('sdm-sorted-asc', 'sdm-sorted-desc');
                header.classList.add('sdm-sorted-' + sortDirectionParam);
                sortDirection[sortColumn] = sortDirectionParam;
                fetchRedirects(currentProjectId, sortColumn, sortDirectionParam);
            }
        }
    }

    function addTooltipsToArrows() {
        var arrows = document.querySelectorAll('.sdm-redirect-arrow');
        arrows.forEach(function(arrow) {
            var type = arrow.getAttribute('data-redirect-type');
            var tooltipText = '';
            switch (type) {
                case 'main':
                    tooltipText = 'Main redirect';
                    break;
                case 'glue':
                    tooltipText = 'Glue redirect (underlink)';
                    break;
                case 'hidden':
                    tooltipText = 'Hidden redirect';
                    break;
                default:
                    tooltipText = 'No redirect';
            }
            arrow.setAttribute('title', tooltipText);
        });
    }

    function showRedirectsNotice(type, message) {
        var noticeContainer = document.getElementById('sdm-redirects-notice');
        if (!noticeContainer) return;
        var cssClass = (type === 'error') ? 'notice-error' : 'notice-success';
        noticeContainer.innerHTML =
            '<div class="notice ' + cssClass + ' is-dismissible"><p>' +
            message +
            '</p><button class="notice-dismiss" type="button">×</button></div>';

        var dismissBtn = noticeContainer.querySelector('.notice-dismiss');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function() {
                noticeContainer.innerHTML = '';
            });
        }
    }
});