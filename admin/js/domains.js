document.addEventListener('DOMContentLoaded', function() {

    // Get the global nonce from the hidden field
    var mainNonceField = document.getElementById('sdm-main-nonce');
    var mainNonce = mainNonceField ? mainNonceField.value : '';

    // ----------------------------
    // 1. Fetch Project Domains
    // ----------------------------
    var fetchBtn = document.getElementById('sdm-fetch-domains');
    var projectSelector = document.getElementById('sdm-project-selector');
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
    // 2. Mass Actions
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

            if (action === 'assign_site') {
                var selected = [];
                document.querySelectorAll('.sdm-domain-checkbox:checked').forEach(function(cb) {
                    selected.push(cb.value);
                });
                if (selected.length === 0) {
                    alert('No domains selected.');
                    return;
                }
                openAssignToSiteModal(selected);
                return;
            }

            // For other actions (sync_ns, sync_status), placeholder logic
            var selected = [];
            document.querySelectorAll('.sdm-domain-checkbox:checked').forEach(function(cb) {
                selected.push(cb.value);
            });
            if (selected.length === 0) {
                alert('No domains selected.');
                return;
            }

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
                    location.reload();
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
    // 3. Modal for Mass Adding Domains to CloudFlare
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
                    location.reload();
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
    // 4. Modal for Assigning Domains to Site
    // ----------------------------
    var assignModal = document.getElementById('sdm-assign-to-site-modal');
    var assignConfirm = document.getElementById('sdm-assign-confirm');
    var assignCancel = document.getElementById('sdm-assign-cancel');
    var assignClose = document.getElementById('sdm-close-assign-modal');
    var siteSelect = document.getElementById('sdm-assign-site-select');
    var selectedDomainsList = document.getElementById('sdm-selected-domains-list');

    function openAssignToSiteModal(domainIds) {
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
        assignModal.style.display = 'block';
    }

    function closeAssignToSiteModal() {
        assignModal.style.display = 'none';
    }

    if (assignConfirm) {
        assignConfirm.addEventListener('click', function(e) {
            e.preventDefault();
            var siteId = siteSelect.value;
            if (!siteId) {
                alert('Please select a site.');
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

            var formData = new FormData();
            formData.append('action', 'sdm_assign_domains_to_site');
            formData.append('domain_ids', JSON.stringify(selected));
            formData.append('site_id', siteId);
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
                    closeAssignToSiteModal();
                    location.reload();
                } else {
                    showDomainsNotice('error', data.data);
                }
            })
            .catch(function(error) {
                console.error('Assign domains error:', error);
                showDomainsNotice('error', 'Ajax request failed.');
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
    // 5. Helper: Show Domains Notice
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