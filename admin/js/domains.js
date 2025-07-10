document.addEventListener('DOMContentLoaded', function () {

    /* ────────── Глобальные переменные ────────── */
    var mainNonceField  = document.getElementById('sdm-main-nonce');
    var mainNonce       = mainNonceField ? mainNonceField.value : '';
    var projectSelector = document.getElementById('sdm-project-selector');
    var currentProjectId = projectSelector ? parseInt(projectSelector.value) : 0;
    let sortDirection   = {};
    let lastSortedColumn = null;


    /* ────────── Делегированный клик: Sync NS ────────── */
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.sdm-sync-ns');
        if (!btn) return;                                 // клик не по нашей кнопке

        e.preventDefault();
        const domainId = btn.getAttribute('data-domain-id');
        if (!domainId) return;

        // при желании можно спросить подтверждение
        if (!confirm('Sync Cloudflare nameservers to Namecheap for this domain?')) return;

        btn.disabled = true;                              // визуальная блокировка

        const fd = new FormData();
        fd.append('action',               'sdm_sync_cf_ns_namecheap');
        fd.append('domain_id',            domainId);
        fd.append('sdm_main_nonce_field', mainNonce);

        fetch(ajaxurl, {
            method:       'POST',
            credentials:  'same-origin',
            body:         fd
        })
        .then(r => r.json())
        .then(resp => {
            const msg = resp.data || resp.message || 'Unknown server response';
            showDomainsNotice(resp.success ? 'updated' : 'error', msg);
        })
        .catch(err => {
            console.error('Sync NS error:', err);
            showDomainsNotice('error', 'Ajax request failed.');
        })
        .finally(() => { btn.disabled = false; });
    });



    /* ────────── Сортировка: начальное состояние ────────── */
    function initializeSortState() {
        sortDirection['domain']        = 'asc';
        sortDirection['site_name']     = 'asc';
        sortDirection['abuse_status']  = 'asc';
        sortDirection['blocked']       = 'asc';
        sortDirection['status']        = 'asc';
        sortDirection['last_checked']  = 'asc';
        sortDirection['created_at']    = 'asc';
    }


    /* ────────── Ajax-загрузка списка доменов ────────── */
    function fetchDomains(projectId,
                          sortColumn        = 'created_at',
                          sortDirectionParam = 'desc',
                          searchTerm        = '',
                          isBlockedSort     = false) {

        var formData = new FormData();
        formData.append('action',          'sdm_fetch_domains_list');
        formData.append('project_id',      projectId);
        formData.append('sort_column',     sortColumn);
        formData.append('sort_direction',  sortDirectionParam);
        formData.append('search_term',     searchTerm);
        if (isBlockedSort) formData.append('is_blocked_sort', '1');
        formData.append('sdm_main_nonce_field', mainNonce);

        var container = document.getElementById('sdm-domains-container');
        container.innerHTML = '<p><span class="spinner"></span> Loading...</p>';

        fetch(ajaxurl, {
            method:       'POST',
            credentials:  'same-origin',
            body:         formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                container.innerHTML = data.data.html;

                appendSyncButtons();        // ← добавляем кнопки после отрисовки таблицы

                initializeDynamicListeners();
                initializeSorting();
            } else {
                showDomainsNotice('error', data.data);
                container.innerHTML = '<p class="error">' + data.data + '</p>';
            }
        })
        .catch(err => {
            console.error('Fetch domains list error:', err);
            showDomainsNotice('error', 'Ajax request failed.');
            container.innerHTML = '<p class="error">Error loading domains.</p>';
        });
    }



    /* ────────── Вставка кнопок “Sync NS” ────────── */
    function appendSyncButtons() {
        // Для всех <tr> с data-domain-id добавляем кнопку, если её ещё нет
        const rows = document.querySelectorAll('#sdm-domains-container tr[data-domain-id]');
        rows.forEach(function (row) {
            if (row.querySelector('.sdm-sync-ns')) return;      // кнопка уже есть

            const domainId = row.getAttribute('data-domain-id');
            if (!domainId) return;

            // Ищем ячейку действий – класс .sdm-actions или последняя TD
            let actionsCell = row.querySelector('.sdm-actions');
            if (!actionsCell) actionsCell = row.lastElementChild;

            const btn = document.createElement('button');
            btn.className = 'button button-small sdm-sync-ns';
            btn.setAttribute('data-domain-id', domainId);
            btn.title = 'Sync NS to Namecheap';
            btn.innerHTML = '<span class="dashicons dashicons-update"></span>';

            actionsCell.appendChild(btn);
        });
    }



    /* ────────── Первичная загрузка (если проект выбран) ────────── */
    if (currentProjectId > 0) {
        initializeSortState();
        fetchDomains(currentProjectId);
    }


    /* ────────── Селектор проекта ────────── */
    if (projectSelector) {
        projectSelector.addEventListener('change', function () {
            currentProjectId = parseInt(this.value);
            if (currentProjectId > 0) {
                initializeSortState();
                fetchDomains(currentProjectId);
            } else {
                document.getElementById('sdm-domains-container').innerHTML =
                    '<p style="margin: 20px 0; color: #666;">Please select a project to view its domains.</p>';
            }
        });
    }



    /* ────────── Кнопка “Fetch Domains from CF” ────────── */
    var fetchBtn    = document.getElementById('sdm-fetch-domains');
    var fetchStatus = document.getElementById('sdm-fetch-domains-status');

    if (fetchBtn && fetchStatus) {

        fetchBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (!currentProjectId) {
                fetchStatus.textContent = 'Please select a project.';
                return;
            }
            fetchStatus.textContent = 'Fetching domains…';

            var fd = new FormData();
            fd.append('action',               'sdm_fetch_domains');
            fd.append('project_id',           currentProjectId);
            fd.append('sdm_main_nonce_field', mainNonce);

            fetch(ajaxurl, {
                method:      'POST',
                credentials: 'same-origin',
                body:        fd
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    fetchStatus.textContent = 'Fetched ' + data.data.count + ' domains from CloudFlare.';
                    fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'desc');
                } else {
                    fetchStatus.textContent = 'Error: ' + data.data;
                }
            })
            .catch(err => {
                console.error('Fetch domains error:', err);
                fetchStatus.textContent = 'Ajax request failed.';
            });
        });
    }



    /* ────────── Уведомления ────────── */
    function showDomainsNotice(type, message) {
        var noticeContainer = document.getElementById('sdm-domains-notice');
        if (!noticeContainer) return;
        var cssClass = (type === 'error') ? 'notice-error' : 'notice-success';
        noticeContainer.innerHTML =
            '<div class="notice ' + cssClass + ' is-dismissible">' +
            '<p>' + message + '</p><button class="notice-dismiss" type="button">×</button></div>';

        var dismissBtn = noticeContainer.querySelector('.notice-dismiss');
        if (dismissBtn) dismissBtn.addEventListener('click', function () {
            noticeContainer.innerHTML = '';
        });
    }

    // Обработчик кнопки Fetch Domains
    var fetchBtn = document.getElementById('sdm-fetch-domains');
    var fetchStatus = document.getElementById('sdm-fetch-domains-status');
    if (fetchBtn && fetchStatus) {
        fetchBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!currentProjectId) {
                fetchStatus.textContent = 'Please select a project.';
                return;
            }
            fetchStatus.textContent = 'Fetching domains...';

            var formData = new FormData();
            formData.append('action', 'sdm_fetch_domains');
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
                    fetchStatus.textContent = 'Fetched ' + data.data.count + ' domains from CloudFlare.';
                    fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'desc');
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

    // Функция для отображения уведомлений
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

    // Функция для установки динамических обработчиков (удаление, отмена назначения, массовые действия и т.д.)
    function initializeDynamicListeners() {
        // Удаление неактивных доменов
        var deleteDomainButtons = document.querySelectorAll('.sdm-delete-domain');
        deleteDomainButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                if (!confirm('Are you sure you want to delete this domain?')) return;
                var row = this.closest('tr');
                var domainId = row.getAttribute('data-domain-id');
                var spinner = document.createElement('span');
                spinner.className = 'spinner is-active';
                spinner.style.float = 'none';
                spinner.style.margin = '0 5px';
                this.innerHTML = '';
                this.appendChild(spinner);
                var formData = new FormData();
                formData.append('action', 'sdm_delete_domain');
                formData.append('domain_id', domainId);
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
                        row.parentNode.removeChild(row);
                        fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'desc');
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

        // Отмена назначения домена
        var unassignButtons = document.querySelectorAll('.sdm-unassign');
        unassignButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                if (!confirm('Are you sure you want to unassign this domain?')) return;
                var row = this.closest('tr');
                var domainId = row.getAttribute('data-domain-id');
                var spinner = document.createElement('span');
                spinner.className = 'spinner is-active';
                spinner.style.float = 'none';
                spinner.style.margin = '0 5px';
                this.innerHTML = '';
                this.appendChild(spinner);
                var formData = new FormData();
                formData.append('action', 'sdm_unassign_domain');
                formData.append('domain_id', domainId);
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
                        row.querySelector('.sdm-site-link').parentNode.innerHTML = '(Unassigned)';
                        row.querySelector('.sdm-unassign').remove();
                        if (row.querySelector('.sdm-main-domain-icon')) {
                            row.querySelector('.sdm-main-domain-icon').remove();
                        }
                        row.querySelector('.sdm-domain-checkbox').style.display = 'inline-block';
                        fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'desc');
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

        // Кнопка Email Forwarding
        var emailForwardingButtons = document.querySelectorAll('.sdm-email-forwarding');
        emailForwardingButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                var domainId = this.getAttribute('data-domain-id');
                openEmailModal(domainId);
            });
        });

        // Массовые действия (код не изменялся)
        var massActionSelect = document.getElementById('sdm-mass-action-select');
        var massActionApply = document.getElementById('sdm-mass-action-apply');
        var domainCheckboxes = document.querySelectorAll('.sdm-domain-checkbox');
        var selectAllCheckbox = document.getElementById('sdm-select-all-domains');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                var checked = this.checked;
                domainCheckboxes.forEach(function(cb) {
                    var row = cb.closest('tr');
                    var isMainDomain = row.querySelector('.sdm-main-domain-icon') !== null;
                    if (!isMainDomain) {
                        cb.checked = checked;
                    }
                });
            });
        }
        if (massActionApply && massActionSelect) {
            massActionApply.addEventListener('click', function(e) {
                e.preventDefault();
                var action = massActionSelect.value;
                if (!action) {
                    alert('Please select a mass action.');
                    return;
                }
                var selected = [];
                document.querySelectorAll('.sdm-domain-checkbox:checked').forEach(function(cb) {
                    var row = cb.closest('tr');
                    var isMainDomain = row.querySelector('.sdm-main-domain-icon') !== null;
                    if (!isMainDomain) {
                        selected.push(cb.value);
                    }
                });
                if (selected.length === 0 && action !== 'mass_add') {
                    alert('No domains selected (main domains are excluded).');
                    return;
                }
                if (action === 'mass_add') {
                    openMassAddModal();
                    return;
                }
                if ([
                    'assign_site',
                    'set_abuse_status',
                    'set_blocked_provider',
                    'set_blocked_government',
                    'clear_blocked'
                ].includes(action)) {
                    openAssignToSiteModal(selected, action);
                    return;
                }
                var formData = new FormData();
                formData.append('action', 'sdm_mass_action');
                formData.append('mass_action', action);
                formData.append('domain_ids', JSON.stringify(selected));
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
                        showDomainsNotice('updated', data.data.message);
                        fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'desc');
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
    }

    // Функция сортировки таблицы
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
                    if (columnName === 'blocked') {
                        fetchDomains(currentProjectId, columnName, direction, '', true);
                    } else {
                        fetchDomains(currentProjectId, columnName, direction);
                    }
                }
                lastSortedColumn = columnName;
            });
        });
    }

    // Функция-дебаунс для поиска
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

    // Модальное окно "Mass Add Domains"
    var massAddModal = document.getElementById('sdm-mass-add-modal');
    var modalConfirm = document.getElementById('sdm-modal-confirm');
    var modalClose = document.getElementById('sdm-modal-close');

    function openMassAddModal() {
        if (!massAddModal) return;
        var modalClone = massAddModal.cloneNode(true);
        massAddModal.remove();
        document.body.appendChild(modalClone);
        massAddModal = modalClone;
        var textarea = massAddModal.querySelector('#sdm-mass-add-textarea');
        if (textarea) {
            textarea.value = '';
        }
        massAddModal.style.display = 'block';
        var confirmBtn = massAddModal.querySelector('#sdm-modal-confirm');
        var closeBtn = massAddModal.querySelector('#sdm-modal-close');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var textarea = massAddModal.querySelector('#sdm-mass-add-textarea');
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
                        showDomainsNotice('updated', data.data.message);
                        closeMassAddModal();
                        fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'desc');
                    } else {
                        showDomainsNotice('error', data.data);
                    }
                })
                .catch(function(error) {
                    console.error('Mass add error:', error);
                    showDomainsNotice('error', 'Ajax request failed.');
                });
            }, { once: true });
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                closeMassAddModal();
            }, { once: true });
        }
    }

    function closeMassAddModal() {
        if (massAddModal) {
            massAddModal.style.display = 'none';
        }
    }

    // Modal: Assigning / Setting Abuse/Block Status
    var assignModal = document.getElementById('sdm-assign-to-site-modal');
    var assignConfirm = document.getElementById('sdm-assign-confirm');
    var assignCancel = document.getElementById('sdm-assign-cancel');
    var assignClose = document.getElementById('sdm-close-assign-modal');
    var siteSelect = document.getElementById('sdm-assign-site-select');
    var selectedDomainsList = document.getElementById('sdm-selected-domains-list');
    var modalActionTitle = document.getElementById('sdm-modal-action-title');
    var modalInstruction = document.getElementById('sdm-modal-instruction');
    var massActionOptions = document.getElementById('sdm-mass-action-options');

    function openAssignToSiteModal(domainIds, action) {
        if (!assignModal) return;
        var modalClone = assignModal.cloneNode(true);
        assignModal.remove();
        document.body.appendChild(modalClone);
        assignModal = modalClone;
        var selectedDomainsList = assignModal.querySelector('#sdm-selected-domains-list');
        var modalActionTitle = assignModal.querySelector('#sdm-modal-action-title');
        var modalInstruction = assignModal.querySelector('#sdm-modal-instruction');
        var massActionOptions = assignModal.querySelector('#sdm-mass-action-options');
        var siteSelect = assignModal.querySelector('#sdm-assign-site-select');
        var confirmBtn = assignModal.querySelector('#sdm-assign-confirm');
        var cancelBtn = assignModal.querySelector('#sdm-assign-cancel');
        var closeBtn = assignModal.querySelector('#sdm-close-assign-modal');
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
        var confirmButtonText = '';
        if (siteSelect) siteSelect.disabled = false;
        massActionOptions.style.display = 'none';
        switch (action) {
            case 'assign_site':
                modalActionTitle.textContent = 'Assign Domains to Site';
                modalInstruction.textContent = 'Select a site to assign the domains:';
                if (siteSelect) {
                    siteSelect.style.display = 'block';
                    siteSelect.value = '';
                }
                confirmButtonText = 'Assign';
                break;
            case 'set_abuse_status':
                modalActionTitle.textContent = 'Set Abuse Status';
                modalInstruction.textContent = 'Select an abuse status for the selected domains:';
                if (siteSelect) siteSelect.style.display = 'none';
                massActionOptions.style.display = 'block';
                massActionOptions.innerHTML = `
                    <label for="sdm-abuse-status-select">Abuse Status:</label>
                    <select id="sdm-abuse-status-select" class="sdm-select">
                        <option value="clean">Clean</option>
                        <option value="phishing">Phishing</option>
                        <option value="malware">Malware</option>
                        <option value="spam">Spam</option>
                        <option value="other">Other</option>
                    </select>
                `;
                confirmButtonText = 'Set Status';
                break;
            case 'set_blocked_provider':
                modalActionTitle.textContent = 'Block by Provider';
                modalInstruction.textContent = 'Confirm blocking the selected domains by provider.';
                if (siteSelect) siteSelect.style.display = 'none';
                massActionOptions.style.display = 'block';
                massActionOptions.innerHTML = '<p>No additional options required.</p>';
                confirmButtonText = 'Block';
                break;
            case 'set_blocked_government':
                modalActionTitle.textContent = 'Block by Government';
                modalInstruction.textContent = 'Confirm blocking the selected domains by government.';
                if (siteSelect) siteSelect.style.display = 'none';
                massActionOptions.style.display = 'block';
                massActionOptions.innerHTML = '<p>No additional options required.</p>';
                confirmButtonText = 'Block';
                break;
            case 'clear_blocked':
                modalActionTitle.textContent = 'Clear Blocked Status';
                modalInstruction.textContent = 'Confirm clearing the blocked status for the selected domains.';
                if (siteSelect) siteSelect.style.display = 'none';
                massActionOptions.style.display = 'block';
                massActionOptions.innerHTML = '<p>No additional options required.</p>';
                confirmButtonText = 'Clear';
                break;
        }
        if (confirmBtn && confirmButtonText) {
            confirmBtn.textContent = confirmButtonText;
        }
        assignModal.style.display = 'block';
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var action = document.getElementById('sdm-mass-action-select').value;
                var selected = [];
                document.querySelectorAll('.sdm-domain-checkbox:checked').forEach(function(cb) {
                    var row = cb.closest('tr');
                    var isMainDomain = row.querySelector('.sdm-main-domain-icon') !== null;
                    if (!isMainDomain) {
                        selected.push(cb.value);
                    }
                });
                if (selected.length === 0) {
                    alert('No domains selected (main domains are excluded).');
                    return;
                }
                var formData = new FormData();
                formData.append('action', 'sdm_mass_action');
                formData.append('mass_action', action);
                formData.append('domain_ids', JSON.stringify(selected));
                formData.append('project_id', currentProjectId);
                formData.append('sdm_main_nonce_field', mainNonce);
                if (action === 'assign_site') {
                    if (siteSelect && siteSelect.value) {
                        formData.append('site_id', siteSelect.value);
                    } else {
                        alert('Please select a site.');
                        return;
                    }
                } else if (action === 'set_abuse_status') {
                    var abuseSelect = assignModal.querySelector('#sdm-abuse-status-select');
                    if (abuseSelect) {
                        formData.append('abuse_status', abuseSelect.value);
                    } else {
                        alert('Abuse status selection is missing.');
                        return;
                    }
                }
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
                        fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'desc');
                    } else {
                        showDomainsNotice('error', data.data);
                    }
                })
                .catch(function(error) {
                    console.error('Mass action error:', error);
                    showDomainsNotice('error', 'Ajax request failed.');
                    spinner.remove();
                });
            }, { once: true });
        }
        if (cancelBtn || closeBtn) {
            [cancelBtn, closeBtn].forEach(function(btn) {
                if (btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        closeAssignToSiteModal();
                    }, { once: true });
                }
            });
        }
    }

    function closeAssignToSiteModal() {
        if (assignModal) {
            assignModal.style.display = 'none';
            var confirmBtn = assignModal.querySelector('#sdm-assign-confirm');
            if (confirmBtn && confirmBtn.dataset.defaultText) {
                confirmBtn.textContent = confirmBtn.dataset.defaultText;
            }
            var massActionOptions = assignModal.querySelector('#sdm-mass-action-options');
            var siteSelect = assignModal.querySelector('#sdm-assign-site-select');
            if (massActionOptions) massActionOptions.style.display = 'none';
            if (siteSelect) siteSelect.style.display = 'none';
        }
    }

    // =================== Email Forwarding Modal (Steps 3 & 4 commented out) ===================
    function openEmailModal(domainId) {
        var emailModal = document.getElementById('sdm-email-forwarding-modal');
        if (!emailModal) return;
        // Клонируем модал, чтобы не копить старые слушатели
        var modalClone = emailModal.cloneNode(true);
        emailModal.remove();
        document.body.appendChild(modalClone);
        emailModal = modalClone;
        emailModal.style.display = 'block';

        var closeBtn       = emailModal.querySelector('#sdm-close-email-modal');
        var emailStatus    = emailModal.querySelector('#sdm-email-status');
        var createEmailBtn = emailModal.querySelector('#sdm-email-confirm');    // Step 1
        var createCfBtn    = emailModal.querySelector('#sdm-create-cf-address'); // Step 2

        // Оставляем только две кнопки. Steps 3 & 4 не используются
        var emailSettings  = emailModal.querySelector('#sdm-email-settings');
        var emailField     = emailModal.querySelector('#sdm-forwarding-email');
        var emailServer    = emailModal.querySelector('#sdm-email-server');
        var emailUsername  = emailModal.querySelector('#sdm-email-username');
        var emailPassword  = emailModal.querySelector('#sdm-email-password');
        var webmailLink    = emailModal.querySelector('#sdm-webmail-link');

        // Инструкция "Almost done!" (финальный блок)
        var finalInstructions = emailModal.querySelector('#sdm-email-instructions');

        // Скрываем статус
        if (emailStatus) {
            emailStatus.style.display = 'none';
            emailStatus.innerHTML = '';
        }

        // Закрытие окна
        if (closeBtn) {
            closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                emailModal.style.display = 'none';
            }, { once: true });
        }

        // ------------------ Step 1: Create Email (Mail-in-a-Box) ------------------
        if (createEmailBtn) {
            createEmailBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (emailStatus) {
                    emailStatus.style.display = 'block';
                    emailStatus.innerHTML = '<span class="spinner is-active"></span> Creating mailbox in Mail-in-a-Box...';
                }
                var formData = new FormData();
                formData.append('action', 'sdm_create_email_forwarding');
                formData.append('domain_id', domainId);
                formData.append('project_id', currentProjectId);
                formData.append('sdm_main_nonce_field', mainNonce);
                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(resp){ return resp.json(); })
                .then(function(data){
                    if (emailStatus) {
                        emailStatus.innerHTML = '';
                    }
                    if (!data.success) {
                        if (emailStatus) {
                            emailStatus.innerHTML = '<div class="notice notice-error"><p>' + data.data + '</p></div>';
                        }
                        return;
                    }
                    // Заполняем поля
                    if (emailField) { emailField.value = data.data.email_address; }
                    if (emailServer) { emailServer.textContent = data.data.server_url || '[Dynamic]'; }
                    if (emailUsername) { emailUsername.textContent = data.data.email_address; }
                    if (emailPassword) { emailPassword.textContent = data.data.password; }
                    if (webmailLink) {
                        var mailUrl = 'https://' + data.data.server_url + '/mail';
                        webmailLink.innerHTML = 'Please verify your new email: <a href="' + mailUrl + '" target="_blank">' + mailUrl + '</a>';
                    }
                    if (emailSettings) { emailSettings.style.display = 'block'; }
                    if (emailStatus) {
                        emailStatus.innerHTML = '<div class="notice notice-success"><p>' + data.data.message + '</p></div>';
                    }
                    // Скрываем Step 1, показываем Step 2
                    createEmailBtn.style.display = 'none';
                    if (createCfBtn) { createCfBtn.style.display = 'inline-block'; }
                })
                .catch(function(error){
                    console.error('Create mailbox error:', error);
                    if (emailStatus) {
                        emailStatus.innerHTML = '<div class="notice notice-error"><p>Ajax request failed.</p></div>';
                    }
                });
            }, { once: true });
        }

        // ------------------ Step 2: Create CF Address ------------------
        if (createCfBtn) {
            createCfBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (emailStatus) {
                    emailStatus.style.display = 'block';
                    emailStatus.innerHTML = '<span class="spinner is-active"></span> Creating CF custom address...';
                }
                var formData = new FormData();
                formData.append('action', 'sdm_create_cf_custom_address');
                formData.append('domain_id', domainId);
                formData.append('sdm_main_nonce_field', mainNonce);
                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (emailStatus) {
                        emailStatus.innerHTML = '';
                    }
                    if (!data.success) {
                        if (emailStatus) {
                            emailStatus.innerHTML = '<div class="notice notice-error"><p>' + data.data + '</p></div>';
                        }
                        return;
                    }
                    if (emailStatus) {
                        emailStatus.innerHTML = '<div class="notice notice-success"><p>' + data.data.message + '</p></div>';
                    }
                    // Скрываем Step 2
                    createCfBtn.style.display = 'none';
                    // Показываем финальный блок "Almost done!"
                    if (finalInstructions) {
                        finalInstructions.style.display = 'block';
                        // После отображения инструкции, обновляем ссылку
                        fetchAccountIdAndOpenLink(domainId);
                    }
                })
                .catch(function(error){
                    console.error('Create CF address error:', error);
                    if (emailStatus) {
                        emailStatus.innerHTML = '<div class="notice notice-error"><p>Ajax request failed.</p></div>';
                    }
                });
            }, { once: true });
        }

    // Функция для получения account_id по домену и установки ссылки
    function fetchAccountIdAndOpenLink(domainId) {
        // Получаем строку таблицы по domainId
        var row = document.getElementById('domain-row-' + domainId);
        if (!row) {
            console.error('Domain row not found for domainId:', domainId);
            return;
        }
        // Извлекаем доменное имя из первой колонки
        var trueDomain = row.querySelector('.sdm-domain').textContent.trim();
        var formData = new FormData();
        formData.append('action', 'sdm_get_zone_account_details_by_domain');
        formData.append('domain', trueDomain);
        formData.append('sdm_main_nonce_field', mainNonce);
        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                var accountId = data.data.account_id;
                // Формируем URL: https://dash.cloudflare.com/{accountId}/{trueDomain}/email/routing
                var routingURL = 'https://dash.cloudflare.com/' + accountId + '/' + trueDomain + '/email/routing';
                // Устанавливаем ссылку в кнопке
                var routingLinkBtn = document.querySelector('#sdm-cf-routing-link');
                if (routingLinkBtn) {
                    routingLinkBtn.href = routingURL;
                }
            } else {
                console.error('Failed to get zone details:', data.data);
            }
        })
        .catch(function(error) {
            console.error('Error fetching zone details:', error);
        });
    }

    }

    // Функция для получения account_id по zone_id и установки ссылки
    function fetchAccountIdAndOpenLink(domainId) {
        // Получаем строку таблицы по domainId (в ней должен быть data-zone-id)
        var row = document.getElementById('domain-row-' + domainId);
        if (!row) {
            console.error('Domain row not found for domainId:', domainId);
            return;
        }
        var zoneId = row.getAttribute('data-zone-id');
        // Также получаем настоящее доменное имя
        var trueDomain = row.querySelector('.sdm-domain').textContent.trim();
        var formData = new FormData();
        formData.append('action', 'sdm_get_zone_details');
        formData.append('zone_id', zoneId);
        formData.append('sdm_main_nonce_field', mainNonce);
        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                var accountId = data.data.account_id;
                // Формируем URL: https://dash.cloudflare.com/{accountId}/{trueDomain}/email/routing
                var routingURL = 'https://dash.cloudflare.com/' + accountId + '/' + trueDomain + '/email/routing';
                // Устанавливаем ссылку в кнопке
                var routingLinkBtn = document.querySelector('#sdm-cf-routing-link');
                if (routingLinkBtn) {
                    routingLinkBtn.href = routingURL;
                }
            } else {
                console.error('Failed to get zone details:', data.data);
            }
        })
        .catch(function(error) {
            console.error('Error fetching zone details:', error);
        });
    }

    // Функция-дебаунс для поиска
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

    // Экспортируем несколько функций и переменных для batch-скриптов
    window.SDM_Domains_API = {
        fetchDomains: fetchDomains,
        showNotice: showDomainsNotice,
        getCurrentProjectId: () => currentProjectId,
        getSortColumn: () => lastSortedColumn,
        getSortDirection: () => sortDirection[lastSortedColumn] || 'desc'
    };
});
