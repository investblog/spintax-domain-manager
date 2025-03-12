document.addEventListener('DOMContentLoaded', function() {
    var mainNonceField = document.getElementById('sdm-main-nonce');
    var mainNonce = mainNonceField ? mainNonceField.value : '';

    var projectSelector = document.getElementById('sdm-project-selector');
    var currentProjectId = projectSelector ? parseInt(projectSelector.value) : 0;

    let sortDirection = {};
    let lastSortedColumn = null;

    function initializeSortState() {
        sortDirection['domain'] = 'asc';
        sortDirection['site_name'] = 'asc';
        sortDirection['abuse_status'] = 'asc';
        sortDirection['blocked'] = 'asc';
        sortDirection['status'] = 'asc';
        sortDirection['last_checked'] = 'asc';
        sortDirection['created_at'] = 'asc';
    }

    function fetchDomains(projectId, sortColumn = 'created_at', sortDirectionParam = 'desc', searchTerm = '', isBlockedSort = false) {
        var formData = new FormData();
        formData.append('action', 'sdm_fetch_domains_list');
        formData.append('project_id', projectId);
        formData.append('sort_column', sortColumn);
        formData.append('sort_direction', sortDirectionParam);
        formData.append('search_term', searchTerm);
        if (isBlockedSort) {
            formData.append('is_blocked_sort', '1');
        }
        formData.append('sdm_main_nonce_field', mainNonce);

        var container = document.getElementById('sdm-domains-container');
        container.innerHTML = '<p><span class="spinner"></span> Loading...</p>';

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function(response) {
            console.log('Response:', response);
            return response.json();
        })
        .then(function(data) {
            console.log('Data:', data);
            if (data.success) {
                container.innerHTML = data.data.html;
                initializeDynamicListeners();
                initializeSorting();
            } else {
                showDomainsNotice('error', data.data);
                container.innerHTML = '<p class="error">' + data.data + '</p>';
            }
        })
        .catch(function(error) {
            console.error('Fetch domains list error:', error);
            showDomainsNotice('error', 'Ajax request failed.');
            container.innerHTML = '<p class="error">Error loading domains.</p>';
        });
    }

    if (currentProjectId > 0) {
        initializeSortState();
        fetchDomains(currentProjectId);
    }

    if (projectSelector) {
        projectSelector.addEventListener('change', function() {
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

    function initializeDynamicListeners() {
        // Delete Inactive Domains
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

        // Unassign Single Domain
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

        // Email Forwarding Button
        var emailForwardingButtons = document.querySelectorAll('.sdm-email-forwarding');
        emailForwardingButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                var domainId = this.getAttribute('data-domain-id');
                var domain = this.getAttribute('data-domain');
                var serverUrl = this.getAttribute('data-server-url') || 'box.mailrouting.site'; // Fallback
                var forwardingEmail = `${domain}@${serverUrl}`;
                openEmailModal(domainId, domain, forwardingEmail);
            });
        });

        // Mass Actions
        var massActionSelect = document.getElementById('sdm-mass-action-select');
        var massActionApply = document.getElementById('sdm-mass-action-apply');
        var domainCheckboxes = document.querySelectorAll('.sdm-domain-checkbox');

        // "Select all" checkbox (excluding main domains)
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

                if (['assign_site', 'set_abuse_status', 'set_blocked_provider', 'set_blocked_government', 'clear_blocked'].includes(action)) {
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

    // Modals and Mass Actions
    var massAddModal = document.getElementById('sdm-mass-add-modal');
    var modalConfirm = document.getElementById('sdm-modal-confirm');
    var modalClose = document.getElementById('sdm-modal-close');

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
        });
    }

    if (modalClose) {
        modalClose.addEventListener('click', function(e) {
            e.preventDefault();
            closeMassAddModal();
        });
    }

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

        // Динамически меняем текст кнопки "Confirm" и контент в зависимости от действия
        var confirmButtonText = '';
        if (siteSelect) siteSelect.disabled = false; // Разблокируем селект по умолчанию
        massActionOptions.style.display = 'none'; // Скрываем дополнительные опции по умолчанию

        switch (action) {
            case 'assign_site':
                modalActionTitle.textContent = 'Assign Domains to Site';
                modalInstruction.textContent = 'Select a site to assign the domains:';
                if (siteSelect) {
                    siteSelect.style.display = 'block'; // Показываем селект
                    siteSelect.value = ''; // Сбрасываем выбор
                }
                confirmButtonText = 'Assign';
                break;
            case 'set_abuse_status':
                modalActionTitle.textContent = 'Set Abuse Status';
                modalInstruction.textContent = 'Select an abuse status for the selected domains:';
                if (siteSelect) siteSelect.style.display = 'none'; // Скрываем селект
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

        // Устанавливаем текст кнопки
        if (assignConfirm && confirmButtonText) {
            assignConfirm.textContent = confirmButtonText;
        }

        assignModal.style.display = 'block';
    }

    function closeAssignToSiteModal() {
        assignModal.style.display = 'none';
        // Возвращаем кнопке "Confirm" её исходный текст
        if (assignConfirm && assignConfirm.dataset.defaultText) {
            assignConfirm.textContent = assignConfirm.dataset.defaultText;
        }
        // Скрываем дополнительные опции
        if (massActionOptions) massActionOptions.style.display = 'none';
        if (siteSelect) siteSelect.style.display = 'none'; // Скрываем селект при закрытии
    }

    if (assignConfirm) {
        assignConfirm.addEventListener('click', function(e) {
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
                var abuseSelect = document.getElementById('sdm-abuse-status-select');
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

    // Search functionality
    var searchInput = document.getElementById('sdm-domain-search');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(function() {
            if (currentProjectId > 0) {
                const searchTerm = searchInput.value.trim().toLowerCase();
                fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'desc', searchTerm);
            }
        }, 300));

        searchInput.addEventListener('input', function() {
            var spinner = document.createElement('span');
            spinner.className = 'spinner is-active';
            spinner.style.float = 'none';
            spinner.style.margin = '0 5px';
            searchInput.parentNode.appendChild(spinner);

            setTimeout(() => spinner.remove(), 300);
        });
    }

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

    // Email Forwarding Modal
    var emailModal = document.getElementById('sdm-email-forwarding-modal');
    var emailConfirm = document.getElementById('sdm-email-confirm');
    var emailCancel = document.getElementById('sdm-email-cancel');
    var closeEmailButton = document.getElementById('sdm-close-email-modal');
    var setCatchallButton = document.getElementById('sdm-set-catchall');
    var emailSubmit = document.getElementById('sdm-email-submit');

    function openEmailModal(domainId, domain, forwardingEmail) {
        var emailField = document.getElementById('sdm-forwarding-email');
        var emailSettings = document.getElementById('sdm-email-settings');
        var emailUsername = document.getElementById('sdm-email-username');
        var emailPassword = document.getElementById('sdm-email-password');

        if (emailField) {
            emailField.value = forwardingEmail;
        }

        // Показываем модальное окно
        emailModal.style.display = 'block';
        emailSubmit.style.display = 'block';

        function closeEmailModal() {
            emailModal.style.display = 'none';
            emailSettings.style.display = 'none';
            setCatchallButton.style.display = 'none';
            emailSubmit.style.display = 'none';
        }

        if (emailCancel && closeEmailButton) {
            emailCancel.addEventListener('click', function(e) {
                e.preventDefault();
                closeEmailModal();
            });
            closeEmailButton.addEventListener('click', function(e) {
                e.preventDefault();
                closeEmailModal();
            });
        }

        if (emailConfirm) {
            emailConfirm.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('Creating email for domain:', domainId);
                // Здесь будет добавлена логика AJAX для создания email (позже)
                if (emailUsername && emailPassword) {
                    emailUsername.textContent = forwardingEmail;
                    emailPassword.textContent = 'generated_password'; // Пример, заменится на реальный пароль
                    emailSettings.style.display = 'block';
                    setCatchallButton.style.display = 'inline-block';
                    emailSubmit.style.display = 'none';
                }
            });
        }

        if (setCatchallButton) {
            setCatchallButton.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('Setting Catch-All for domain:', domainId);
                // Здесь будет добавлена логика AJAX для настройки Catch-All (позже)
            });
        }
    }

    function initializeDynamicListeners() {
        // Delete Inactive Domains
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

        // Unassign Single Domain
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

        // Email Forwarding Button
        var emailForwardingButtons = document.querySelectorAll('.sdm-email-forwarding');
        emailForwardingButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                var domainId = this.getAttribute('data-domain-id');
                var domain = this.getAttribute('data-domain');
                var serverUrl = this.getAttribute('data-server-url') || 'box.mailrouting.site'; // Fallback
                var forwardingEmail = `${domain}@${serverUrl}`;
                openEmailModal(domainId, domain, forwardingEmail);
            });
        });

        // Mass Actions
        var massActionSelect = document.getElementById('sdm-mass-action-select');
        var massActionApply = document.getElementById('sdm-mass-action-apply');
        var domainCheckboxes = document.querySelectorAll('.sdm-domain-checkbox');

        // "Select all" checkbox (excluding main domains)
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

                if (['assign_site', 'set_abuse_status', 'set_blocked_provider', 'set_blocked_government', 'clear_blocked'].includes(action)) {
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

    // Search functionality
    var searchInput = document.getElementById('sdm-domain-search');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(function() {
            if (currentProjectId > 0) {
                const searchTerm = searchInput.value.trim().toLowerCase();
                fetchDomains(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'desc', searchTerm);
            }
        }, 300));

        searchInput.addEventListener('input', function() {
            var spinner = document.createElement('span');
            spinner.className = 'spinner is-active';
            spinner.style.float = 'none';
            spinner.style.margin = '0 5px';
            searchInput.parentNode.appendChild(spinner);

            setTimeout(() => spinner.remove(), 300);
        });
    }

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