document.addEventListener('DOMContentLoaded', function() {

    // Получаем глобальный nonce из скрытого поля
    var mainNonceField = document.getElementById('sdm-main-nonce');
    var mainNonce = mainNonceField ? mainNonceField.value : '';

    // ----------------------------
    // 1. Fetch Project Domains (оставляем без изменений)
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

    // Обработчик для массовых действий – один обработчик
    if (massActionApply && massActionSelect) {
        massActionApply.addEventListener('click', function(e) {
            e.preventDefault();
            var action = massActionSelect.value;
            if (!action) {
                alert('Please select a mass action.');
                return;
            }
            if (action === 'mass_add') {
                console.log("Mass action 'mass_add' selected. Opening modal.");
                openMassAddModal();
                return;
            }
            // Для других действий собираем ID выбранных доменов
            var selected = [];
            document.querySelectorAll('.sdm-domain-checkbox').forEach(function(cb) {
                if (cb.checked) {
                    selected.push(cb.value);
                }
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
    var modalContainer = document.getElementById('sdm-mass-add-modal');
    var modalConfirm = document.getElementById('sdm-modal-confirm');
    var modalClose   = document.getElementById('sdm-modal-close');

    function openMassAddModal() {
        var textarea = document.getElementById('sdm-mass-add-textarea');
        if (textarea) {
            textarea.value = '';
        }
        // Показываем родительский контейнер, что сделает видимыми и оверлей, и содержимое
        if (modalContainer) {
            modalContainer.style.display = 'block';
        }
    }

    function closeMassAddModal() {
        if (modalContainer) {
            modalContainer.style.display = 'none';
        }
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
    // 4. Helper: Show Domain Notice
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
    
});
