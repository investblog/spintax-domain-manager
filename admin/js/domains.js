document.addEventListener('DOMContentLoaded', function() {

    // Get the global nonce from the hidden field
    var mainNonceField = document.getElementById('sdm-main-nonce');
    var mainNonce = mainNonceField ? mainNonceField.value : '';

    // Get current project ID
    var projectSelector = document.getElementById('sdm-project-selector');
    var currentProjectId = projectSelector ? parseInt(projectSelector.value) : 0;

    // ----------------------------
    // 1. Fetch Project Domains
    // ----------------------------
    var fetchBtn = document.getElementById('sdm-fetch-domains');
    var fetchStatus = document.getElementById('sdm-fetch-domains-status');

    if (fetchBtn && projectSelector && fetchStatus) {
        fetchBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var projectId = projectSelector.value;
            if (!projectId) {
                fetchStatus.textContent = 'Please select a project.';
                return;
            }
            fetchStatus.textContent = 'Fetching domains...';

            var formData = new FormData();
            formData.append('action', 'sdm_fetch_domains');
            formData.append('project_id', projectId);
            formData.append('sdm_main_nonce_field', mainNonce);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    fetchStatus.textContent = 'Fetched ' + data.data.count + ' domains from CloudFlare.';
                    fetchDomains(projectId); // Refresh table after fetch
                } else {
                    fetchStatus.textContent = 'Error: ' + data.data;
                }
            })
            .catch(function(error) {
                console.error('Fetch domains error:', error);
                fetchStatus.textContent = 'Ajax request failed.';
            });
        });
    }

    // ----------------------------
    // 2. Sorting and Search
    // ----------------------------
    var table = document.getElementById('sdm-domains-table');
    var sortColumns = document.querySelectorAll('.sdm-sortable');
    var searchInput = document.getElementById('sdm-domain-search');

    let sortDirection = {};
    let lastSortedColumn = null;

    // Initialize sorting state
    sortColumns.forEach(column => {
        sortDirection[column.dataset.column] = 'asc';
    });

    // Handle sorting
    sortColumns.forEach(column => {
        column.addEventListener('click', function(e) {
            e.preventDefault();
            let columnName = this.dataset.column;
            let direction = sortDirection[columnName] === 'asc' ? 'desc' : 'asc';

            // Reset other columns' sort indicators
            sortColumns.forEach(col => {
                if (col !== this) {
                    col.classList.remove('sdm-sorted-asc', 'sdm-sorted-desc');
                    sortDirection[col.dataset.column] = 'asc';
                }
            });

            // Toggle sort indicator on the clicked column
            this.classList.remove('sdm-sorted-asc', 'sdm-sorted-desc');
            this.classList.add('sdm-sorted-' + direction);
            sortDirection[columnName] = direction;

            // Fetch and sort domains
            if (currentProjectId > 0) {
                fetchDomains(currentProjectId, columnName, direction);
            }

            lastSortedColumn = columnName;
        });
    });

    // Handle search
    if (searchInput) {
        searchInput.addEventListener('input', debounce(function() {
            if (currentProjectId > 0) {
                const searchTerm = searchInput.value.trim().toLowerCase();
                console.log('Searching for:', searchTerm); // Debug
                fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc', searchTerm);
            }
        }, 300));

        // Show spinner while searching
        searchInput.addEventListener('input', function() {
            var spinner = document.createElement('span');
            spinner.className = 'spinner is-active';
            spinner.style.float = 'none';
            spinner.style.margin = '0 5px';
            searchInput.parentNode.appendChild(spinner);

            setTimeout(() => spinner.remove(), 300); // Remove spinner after debounce delay
        });
    }

    // ----------------------------
    // 3. Mass Actions
    // ----------------------------
    var massActionSelect = document.getElementById('sdm-mass-action-select');
    var massActionApply  = document.getElementById('sdm-mass-action-apply');
    var domainCheckboxes = document.querySelectorAll('.sdm-domain-checkbox');

    // "Select all" checkbox
    var selectAllCheckbox = document.getElementById('sdm-select-all-domains');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            var checked = this.checked;
            domainCheckboxes.forEach(function(cb) {
                cb.checked = checked;
            });
        });
    }

    // Handle mass actions
    if (massActionApply && massActionSelect) {
        massActionApply.addEventListener('click', function(e) {
            e.preventDefault();
            var action = massActionSelect.value;
            if (!action) {
                alert('Please select a mass action.');
                return;
            }

            if (action === 'mass_add') {
                openMassAddModal();
                return;
            }

            var selected = [];
            document.querySelectorAll('.sdm-domain-checkbox:checked').forEach(function(cb) {
                selected.push(cb.value);
            });
            if (selected.length === 0) {
                alert('No domains selected.');
                return;
            }

            if (action === 'assign_site') {
                openAssignToSiteModal(selected, action);
                return;
            }

            // For other actions (sync_ns, sync_status), placeholder logic
            var formData = new FormData();
            formData.append('action', 'sdm_mass_action');
            formData.append('mass_action', action);
            formData.append('domain_ids', JSON.stringify(selected));
            formData.append('sdm_main_nonce_field', mainNonce);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    showDomainsNotice('updated', data.data.message);
                    fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc'); // Refresh table
                } else {
                    showDomainsNotice('error', data.data);
                }
            })
            .catch(function(error) {
                console.error('Mass action error:', error);
                showDomainsNotice('error', 'Ajax request failed.');
            });
        });
    }

    // ----------------------------
    // 4. Delete Inactive Domains
    // ----------------------------
    var deleteDomainButtons = document.querySelectorAll('.sdm-delete-domain');
    deleteDomainButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to delete this domain?')) return;

            var row = this.closest('tr');
            var domainId = row.getAttribute('data-domain-id');

            var formData = new FormData();
            formData.append('action', 'sdm_delete_domain');
            formData.append('domain_id', domainId);
            formData.append('sdm_main_nonce_field', mainNonce);

            var spinner = document.createElement('span');
            spinner.className = 'spinner is-active';
            spinner.style.float = 'none';
            spinner.style.margin = '0 5px';
            this.innerHTML = '';
            this.appendChild(spinner);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    showDomainsNotice('updated', data.data.message);
                    row.parentNode.removeChild(row);
                    fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc'); // Refresh table
                } else {
                    showDomainsNotice('error', data.data);
                }
                spinner.remove();
            })
            .catch(function(error) {
                console.error('Delete domain error:', error);
                showDomainsNotice('error', 'Ajax request failed.');
                spinner.remove();
            });
        });
    });

    // ----------------------------
    // 5. Unassign Single Domain
    // ----------------------------
    var unassignButtons = document.querySelectorAll('.sdm-unassign');
    unassignButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to unassign this domain?')) return;

            var row = this.closest('tr');
            var domainId = row.getAttribute('data-domain-id');

            var formData = new FormData();
            formData.append('action', 'sdm_unassign_domain');
            formData.append('domain_id', domainId);
            formData.append('sdm_main_nonce_field', mainNonce);

            var spinner = document.createElement('span');
            spinner.className = 'spinner is-active';
            spinner.style.float = 'none';
            spinner.style.margin = '0 5px';
            this.innerHTML = '';
            this.appendChild(spinner);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    showDomainsNotice('updated', data.data.message);
                    row.querySelector('.sdm-site-link').parentNode.innerHTML = '(Unassigned)';
                    row.querySelector('.sdm-unassign').remove();
                    if (row.querySelector('.sdm-main-domain-note')) {
                        row.querySelector('.sdm-main-domain-note').remove();
                    }
                    row.querySelector('.sdm-domain-checkbox').style.display = 'inline-block';
                    fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc'); // Refresh table
                } else {
                    showDomainsNotice('error', data.data);
                }
                spinner.remove();
            })
            .catch(function(error) {
                console.error('Unassign domain error:', error);
                showDomainsNotice('error', 'Ajax request failed.');
                spinner.remove();
            });
        });
    });

    // ----------------------------
    // 6. Modal for Mass Adding Domains to CloudFlare
    // ----------------------------
    var massAddModal = document.getElementById('sdm-mass-add-modal');
    var modalConfirm = document.getElementById('sdm-modal-confirm');
    var modalClose   = document.getElementById('sdm-modal-close');

    function openMassAddModal() {
        var textarea = document.getElementById('sdm-mass-add-textarea');
        if (textarea) {
            textarea.value = '';
        }
        massAddModal.style.display = 'block';
    }

    function closeMassAddModal() {
        massAddModal.style.display = 'none';
    }

    if (modalConfirm) {
        modalConfirm.addEventListener('click', function(e) {
            e.preventDefault();
            var textarea = document.getElementById('sdm-mass-add-textarea');
            if (!textarea || !textarea.value.trim()) {
                alert('Please enter at least one domain.');
                return;
            }
            var domainList = textarea.value.split(/\r?\n/).map(function(item) {
                return item.trim();
            }).filter(function(item) {
                return item !== '';
            });

            var formData = new FormData();
            formData.append('action', 'sdm_mass_add_domains');
            formData.append('domain_list', JSON.stringify(domainList));
            formData.append('sdm_main_nonce_field', mainNonce);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    showDomainsNotice('updated', data.data.message);
                    closeMassAddModal();
                    fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc'); // Refresh table
                } else {
                    showDomainsNotice('error', data.data);
                }
            })
            .catch(function(error) {
                console.error('Mass add error:', error);
                showDomainsNotice('error', 'Ajax request failed.');
            });
        });
    }

    if (modalClose) {
        modalClose.addEventListener('click', function(e) {
            e.preventDefault();
            closeMassAddModal();
        });
    }

    // ----------------------------
    // 7. Modal for Assigning Domains to Site
    // ----------------------------
    var assignModal = document.getElementById('sdm-assign-to-site-modal');
    var assignConfirm = document.getElementById('sdm-assign-confirm');
    var assignCancel = document.getElementById('sdm-assign-cancel');
    var assignClose = document.getElementById('sdm-close-assign-modal');
    var siteSelect = document.getElementById('sdm-assign-site-select');
    var selectedDomainsList = document.getElementById('sdm-selected-domains-list');
    var modalActionTitle = document.getElementById('sdm-modal-action-title');
    var modalInstruction = document.getElementById('sdm-modal-instruction');

    function openAssignToSiteModal(domainIds, action) {
        if (selectedDomainsList) {
            selectedDomainsList.innerHTML = '';
            domainIds.forEach(function(id) {
                var domainRow = document.getElementById('domain-row-' + id);
                if (domainRow) {
                    var domainName = domainRow.querySelector('td:first-child').textContent;
                    var li = document.createElement('li');
                    li.textContent = domainName;
                    selectedDomainsList.appendChild(li);
                }
            });
        }

        if (action === 'assign_site') {
            modalActionTitle.textContent = 'Assign Domains to Site';
            modalInstruction.textContent = 'Select a site to assign the domains:';
            siteSelect.disabled = false;
            siteSelect.value = '';
        }

        assignModal.style.display = 'block';
    }

    function closeAssignToSiteModal() {
        assignModal.style.display = 'none';
    }

    if (assignConfirm) {
        assignConfirm.addEventListener('click', function(e) {
            e.preventDefault();
            var action = massActionSelect.value;
            var selected = [];
            document.querySelectorAll('.sdm-domain-checkbox:checked').forEach(function(cb) {
                selected.push(cb.value);
            });
            if (selected.length === 0) {
                alert('No domains selected.');
                return;
            }

            var formData = new FormData();
            formData.append('action', 'sdm_assign_domains_to_site');
            formData.append('domain_ids', JSON.stringify(selected));
            formData.append('site_id', siteSelect.value);
            formData.append('sdm_main_nonce_field', mainNonce);

            var spinner = document.createElement('span');
            spinner.className = 'spinner is-active';
            spinner.style.float = 'none';
            spinner.style.margin = '0 5px';
            this.innerHTML = '';
            this.appendChild(spinner);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                spinner.remove();
                if (data.success) {
                    showDomainsNotice('updated', data.data.message);
                    closeAssignToSiteModal();
                    fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc'); // Refresh table
                } else {
                    showDomainsNotice('error', data.data);
                }
            })
            .catch(function(error) {
                console.error('Assign domains error:', error);
                showDomainsNotice('error', 'Ajax request failed.');
                spinner.remove();
            });
        });
    }

    if (assignCancel || assignClose) {
        [assignCancel, assignClose].forEach(function(element) {
            if (element) {
                element.addEventListener('click', function(e) {
                    e.preventDefault();
                    closeAssignToSiteModal();
                });
            }
        });
    }

    // ----------------------------
    // 8. Helper Functions
    // ----------------------------

    // Debounce function for search
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Fetch domains with sorting and search
    function fetchDomains(projectId, sortColumn = 'created_at', sortDirection = 'desc', searchTerm = '') {
        var formData = new FormData();
        formData.append('action', 'sdm_fetch_domains_list');
        formData.append('project_id', projectId);
        formData.append('sort_column', sortColumn);
        formData.append('sort_direction', sortDirection);
        formData.append('search_term', searchTerm);
        formData.append('sdm_main_nonce_field', mainNonce);

        // Show spinner while fetching
        var spinner = document.createElement('span');
        spinner.className = 'spinner is-active';
        spinner.style.float = 'none';
        spinner.style.margin = '0 5px';
        var table = document.getElementById('sdm-domains-table');
        table.parentNode.insertBefore(spinner, table);

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            spinner.remove();
            if (data.success) {
                var tbody = document.querySelector('#sdm-domains-table tbody');
                tbody.innerHTML = data.data.html;
                // Reinitialize event listeners for new elements
                initializeDynamicListeners();
                // Update sort indicators
                if (sortColumn) {
                    var sortedColumn = document.querySelector(`.sdm-sortable[data-column="${sortColumn}"]`);
                    if (sortedColumn) {
                        sortedColumn.classList.remove('sdm-sorted-asc', 'sdm-sorted-desc');
                        sortedColumn.classList.add('sdm-sorted-' + (sortDirection === 'desc' ? 'desc' : 'asc'));
                    }
                }
            } else {
                showDomainsNotice('error', data.data);
            }
        })
        .catch(function(error) {
            console.error('Fetch domains list error:', error);
            showDomainsNotice('error', 'Ajax request failed.');
            spinner.remove();
        });
    }

    // Initialize dynamic event listeners (e.g., delete, unassign buttons)
    function initializeDynamicListeners() {
        var deleteDomainButtons = document.querySelectorAll('.sdm-delete-domain');
        deleteDomainButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                if (!confirm('Are you sure you want to delete this domain?')) return;

                var row = this.closest('tr');
                var domainId = row.getAttribute('data-domain-id');

                var formData = new FormData();
                formData.append('action', 'sdm_delete_domain');
                formData.append('domain_id', domainId);
                formData.append('sdm_main_nonce_field', mainNonce);

                var spinner = document.createElement('span');
                spinner.className = 'spinner is-active';
                spinner.style.float = 'none';
                spinner.style.margin = '0 5px';
                this.innerHTML = '';
                this.appendChild(spinner);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        showDomainsNotice('updated', data.data.message);
                        row.parentNode.removeChild(row);
                        fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc');
                    } else {
                        showDomainsNotice('error', data.data);
                    }
                    spinner.remove();
                })
                .catch(function(error) {
                    console.error('Delete domain error:', error);
                    showDomainsNotice('error', 'Ajax request failed.');
                    spinner.remove();
                });
            });
        });

        var unassignButtons = document.querySelectorAll('.sdm-unassign');
        unassignButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                if (!confirm('Are you sure you want to unassign this domain?')) return;

                var row = this.closest('tr');
                var domainId = row.getAttribute('data-domain-id');

                var formData = new FormData();
                formData.append('action', 'sdm_unassign_domain');
                formData.append('domain_id', domainId);
                formData.append('sdm_main_nonce_field', mainNonce);

                var spinner = document.createElement('span');
                spinner.className = 'spinner is-active';
                spinner.style.float = 'none';
                spinner.style.margin = '0 5px';
                this.innerHTML = '';
                this.appendChild(spinner);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        showDomainsNotice('updated', data.data.message);
                        row.querySelector('.sdm-site-link').parentNode.innerHTML = '(Unassigned)';
                        row.querySelector('.sdm-unassign').remove();
                        if (row.querySelector('.sdm-main-domain-note')) {
                            row.querySelector('.sdm-main-domain-note').remove();
                        }
                        row.querySelector('.sdm-domain-checkbox').style.display = 'inline-block';
                        fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc');
                    } else {
                        showDomainsNotice('error', data.data);
                    }
                    spinner.remove();
                })
                .catch(function(error) {
                    console.error('Unassign domain error:', error);
                    showDomainsNotice('error', 'Ajax request failed.');
                    spinner.remove();
                });
            });
        });
    }

    // Initialize dynamic listeners on page load
    initializeDynamicListeners();

    // ----------------------------
    // 9. Helper: Show Domains Notice
    // ----------------------------
    function showDomainsNotice(type, message) {
        var noticeContainer = document.getElementById('sdm-domains-notice');
        if (!noticeContainer) return;
        var cssClass = (type === 'error') ? 'notice-error' : 'notice-success';
        noticeContainer.innerHTML = '<div class="notice ' + cssClass + ' is-dismissible"><p>' + message + '</p><button class="notice-dismiss" type="button">×</button></div>';
        
        var dismissBtn = noticeContainer.querySelector('.notice-dismiss');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function() {
                noticeContainer.innerHTML = '';
            });
        }
    }
    
    // Initialize Select2 for site selection
    var siteSelectElement = document.getElementById('sdm-assign-site-select');
    if (siteSelectElement) {
        siteSelectElement.addEventListener('focus', function() {
            if (!window.jQuery) return;
            jQuery(this).select2({
                width: '100%',
                placeholder: 'Select a site',
                allowClear: true
            });
        });
    }
});