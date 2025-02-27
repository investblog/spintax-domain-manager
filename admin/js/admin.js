document.addEventListener('DOMContentLoaded', function() {
    // 1) Copy Encryption Key
    var copyButton = document.getElementById('sdm_copy_key_button');
    var keyField = document.getElementById('sdm_encryption_key_field');

    if (copyButton && keyField) {
        copyButton.addEventListener('click', function() {
            keyField.select();
            keyField.setSelectionRange(0, 99999); // Для совместимости с мобильными устройствами

            try {
                var successful = document.execCommand('copy');
                if (successful) {
                    showWPNotice('Encryption key copied to clipboard.', 'success');
                } else {
                    showWPNotice('Failed to copy the key.', 'error');
                }
            } catch (err) {
                showWPNotice('Error copying the key: ' + err.message, 'error');
            }
        });
    }

    function showWPNotice(message, type) {
        // Создаем элемент уведомления в стиле WordPress
        var notice = document.createElement('div');
        notice.className = 'notice notice-' + (type === 'success' ? 'updated' : 'error') + ' is-dismissible';
        notice.innerHTML = '<p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>';

        // Находим место для вставки уведомления (под заголовком страницы)
        var header = document.querySelector('.wrap h1');
        if (header) {
            var wrap = header.closest('.wrap');
            if (wrap) {
                wrap.insertBefore(notice, wrap.querySelector('.wrap > *:nth-child(2)') || wrap.firstChild.nextSibling);
            } else {
                document.body.insertBefore(notice, document.body.firstChild.nextSibling);
            }
        } else {
            var noticesContainer = document.getElementById('wpbody-content') || document.body;
            noticesContainer.insertBefore(notice, noticesContainer.firstChild);
        }

        // Удаляем уведомление при клике на "dismiss"
        notice.querySelector('.notice-dismiss').addEventListener('click', function() {
            notice.remove();
        });

        // Автоматическое удаление через 5 секунд
        setTimeout(function() {
            if (notice.parentNode) {
                notice.remove();
            }
        }, 5000);
    }

    // 2) Projects: Add New Project
    var addProjectForm = document.getElementById('sdm-add-project-form');
    var projectsNotice = document.getElementById('sdm-projects-notice'); // Container for project messages

    if (addProjectForm && projectsNotice) {
        addProjectForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(addProjectForm);
            formData.append('action', 'sdm_add_project');

            var submitButton = addProjectForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                submitButton.disabled = false;
                if (data.success) {
                    showProjectsNotice('updated', 'Project added successfully (ID: ' + data.data.project_id + ')');
                    addProjectForm.reset();
                    location.reload(); // Reload to show new project in table
                } else {
                    showProjectsNotice('error', data.data);
                }
            })
            .catch(function(error) {
                submitButton.disabled = false;
                console.error('Error:', error);
                showProjectsNotice('error', 'Ajax request failed.');
            });
        });
    }

    // 3) Projects: Inline Editing & Deletion
    var projectsTable = document.getElementById('sdm-projects-table');

    if (projectsTable && projectsNotice) {
        projectsTable.addEventListener('click', function(e) {
            // Edit
            if (e.target.classList.contains('sdm-edit-project')) {
                e.preventDefault();
                var row = e.target.closest('tr');
                toggleEditRow(row, true);
            }
            // Save
            else if (e.target.classList.contains('sdm-save-project')) {
                e.preventDefault();
                var row = e.target.closest('tr');
                saveRow(row);
            }
            // Delete
            else if (e.target.classList.contains('sdm-delete-project')) {
                e.preventDefault();
                if (!confirm('Are you sure you want to delete this project?')) {
                    return;
                }
                var row = e.target.closest('tr');
                var projectId = row.getAttribute('data-project-id');
                var nonce = row.getAttribute('data-update-nonce');

                var formData = new FormData();
                formData.append('action', 'sdm_delete_project');
                formData.append('sdm_main_nonce_field', nonce);
                formData.append('project_id', projectId);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        showProjectsNotice('updated', data.data.message);
                        row.parentNode.removeChild(row);
                    } else {
                        showProjectsNotice('error', data.data);
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    showProjectsNotice('error', 'Ajax request failed.');
                });
            }
        });
    }

    /**
     * Toggle row between display/edit mode (Projects)
     */
    function toggleEditRow(row, editMode) {
        var displayElems = row.querySelectorAll('.sdm-display-value');
        var editInputs = row.querySelectorAll('.sdm-edit-input');
        var editLink = row.querySelector('.sdm-edit-project');
        var saveLink = row.querySelector('.sdm-save-project');

        if (editMode) {
            displayElems.forEach(function(el) { el.classList.add('sdm-hidden'); });
            editInputs.forEach(function(el) { el.classList.remove('sdm-hidden'); });
            editLink.classList.add('sdm-hidden');
            saveLink.classList.remove('sdm-hidden');
        } else {
            displayElems.forEach(function(el) { el.classList.remove('sdm-hidden'); });
            editInputs.forEach(function(el) { el.classList.add('sdm-hidden'); });
            editLink.classList.remove('sdm-hidden');
            saveLink.classList.add('sdm-hidden');
        }
    }

    /**
     * Save row changes (Projects)
     */
    function saveRow(row) {
        var projectId = row.getAttribute('data-project-id');
        var nonce = row.getAttribute('data-update-nonce');
        var projectName = row.querySelector('input[name="project_name"]').value;
        var description = row.querySelector('textarea[name="description"]').value;
        var sslMode = row.querySelector('select[name="ssl_mode"]').value;
        var monitoringEnabled = row.querySelector('input[name="monitoring_enabled"]').checked ? '1' : '';

        var formData = new FormData();
        formData.append('action', 'sdm_update_project');
        formData.append('sdm_main_nonce_field', nonce);
        formData.append('project_id', projectId);
        formData.append('project_name', projectName);
        formData.append('description', description);
        formData.append('ssl_mode', sslMode);
        formData.append('monitoring_enabled', monitoringEnabled);

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showProjectsNotice('updated', data.data.message);

                var nameSpan = row.querySelector('.column-name .sdm-display-value');
                var descSpan = row.querySelector('.column-description .sdm-display-value');
                var sslSpan = row.querySelector('.column-ssl_mode .sdm-display-value');
                var monitoringSpan = row.querySelector('.column-monitoring .sdm-display-value');

                if (nameSpan) nameSpan.textContent = projectName;
                if (descSpan) descSpan.textContent = description;
                if (sslSpan) sslSpan.textContent = sslMode;
                if (monitoringSpan) monitoringSpan.textContent = monitoringEnabled ? 'Yes' : 'No';

                toggleEditRow(row, false);
            } else {
                showProjectsNotice('error', data.data);
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showProjectsNotice('error', 'Ajax request failed.');
        });
    }

    /**
     * Show a notice for projects in #sdm-projects-notice
     */
    function showProjectsNotice(type, message) {
        if (!projectsNotice) return;
        var cssClass = (type === 'error') ? 'notice-error' : 'notice-success';
        projectsNotice.innerHTML = '<div class="notice ' + cssClass + ' is-dismissible"><p>' + message + '</p></div>';

        setTimeout(function() {
            if (projectsNotice.firstChild) {
                projectsNotice.removeChild(projectsNotice.firstChild);
            }
        }, 5000);
    }

    // 4) Accounts: Add New Account
    var addAccountForm = document.getElementById('sdm-add-account-form');
    var accountsNotice = document.getElementById('sdm-accounts-notice'); // Container for account messages

    if (addAccountForm && accountsNotice) {
        addAccountForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(addAccountForm);
            formData.append('action', 'sdm_add_account');

            var submitButton = addAccountForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                submitButton.disabled = false;
                if (data.success) {
                    showAccountsNotice('updated', 'Account added successfully (ID: ' + data.data.account_id + ')');
                    addAccountForm.reset();
                    location.reload(); // or dynamically add row
                } else {
                    showAccountsNotice('error', data.data);
                }
            })
            .catch(function(error) {
                submitButton.disabled = false;
                console.error('Error:', error);
                showAccountsNotice('error', 'Ajax request failed.');
            });
        });
    }

    // 5) Accounts: Inline Editing & Deletion
    var accountsTable = document.getElementById('sdm-accounts-table');

    if (accountsTable && accountsNotice) {
        accountsTable.addEventListener('click', function(e) {
            // Edit
            if (e.target.classList.contains('sdm-edit-account')) {
                e.preventDefault();
                var row = e.target.closest('tr');
                toggleEditAccountRow(row, true);
            }
            // Save
            else if (e.target.classList.contains('sdm-save-account')) {
                e.preventDefault();
                var row = e.target.closest('tr');
                saveAccountRow(row);
            }
            // Delete
            else if (e.target.classList.contains('sdm-delete-account')) {
                e.preventDefault();
                if (!confirm('Are you sure you want to delete this account?')) {
                    return;
                }
                var row = e.target.closest('tr');
                var accountId = row.getAttribute('data-account-id');
                var nonce = row.getAttribute('data-update-nonce');

                var formData = new FormData();
                formData.append('action', 'sdm_delete_account');
                formData.append('sdm_main_nonce_field', nonce);
                formData.append('account_id', accountId);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        showAccountsNotice('updated', data.data.message);
                        row.parentNode.removeChild(row);
                    } else {
                        showAccountsNotice('error', data.data);
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    showAccountsNotice('error', 'Ajax request failed.');
                });
            }
        });
    }

    /**
     * Toggle row for accounts between display/edit mode
     */
    function toggleEditAccountRow(row, editMode) {
        var displayElems = row.querySelectorAll('.sdm-display-value');
        var editInputs = row.querySelectorAll('.sdm-edit-input');
        var editLink = row.querySelector('.sdm-edit-account');
        var saveLink = row.querySelector('.sdm-save-account');

        if (editMode) {
            displayElems.forEach(function(el) { el.classList.add('sdm-hidden'); });
            editInputs.forEach(function(el) { el.classList.remove('sdm-hidden'); });
            editLink.classList.add('sdm-hidden');
            saveLink.classList.remove('sdm-hidden');
        } else {
            displayElems.forEach(function(el) { el.classList.remove('sdm-hidden'); });
            editInputs.forEach(function(el) { el.classList.add('sdm-hidden'); });
            editLink.classList.remove('sdm-hidden');
            saveLink.classList.add('sdm-hidden');
        }
    }

    /**
     * Save changes for an account row
     */
    function saveAccountRow(row) {
        var accountId = row.getAttribute('data-account-id');
        var nonce = row.getAttribute('data-update-nonce');
        var service = row.querySelector('select[name="service"]').value;
        var accountName = row.querySelector('input[name="account_name"]').value;
        var email = row.querySelector('input[name="email"]').value;
        var apiKey = row.querySelector('input[name="api_key_enc"]').value;
        var clientId = row.querySelector('input[name="client_id_enc"]').value;
        var clientSecret = row.querySelector('input[name="client_secret_enc"]').value;
        var refreshToken = row.querySelector('input[name="refresh_token_enc"]').value;
        var additionalData = row.querySelector('textarea[name="additional_data_enc"]').value;

        var formData = new FormData();
        formData.append('action', 'sdm_update_account');
        formData.append('sdm_main_nonce_field', nonce);
        formData.append('account_id', accountId);
        formData.append('service', service);
        formData.append('account_name', accountName);
        formData.append('email', email);
        formData.append('api_key_enc', apiKey);
        formData.append('client_id_enc', clientId);
        formData.append('client_secret_enc', clientSecret);
        formData.append('refresh_token_enc', refreshToken);
        formData.append('additional_data_enc', additionalData);

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showAccountsNotice('updated', data.data.message);

                var serviceSpan = row.querySelector('.column-service .sdm-display-value');
                var nameSpan = row.querySelector('.column-account-name .sdm-display-value');
                var emailSpan = row.querySelector('.column-email .sdm-display-value');
                var apiKeySpan = row.querySelector('.column-api-key .sdm-display-value');
                var clientIdSpan = row.querySelector('.column-client-id .sdm-display-value');
                var clientSecretSpan = row.querySelector('.column-client-secret .sdm-display-value');
                var refreshTokenSpan = row.querySelector('.column-refresh-token .sdm-display-value');
                var additionalSpan = row.querySelector('.column-additional-data .sdm-display-value');

                if (serviceSpan) serviceSpan.textContent = service;
                if (nameSpan) nameSpan.textContent = accountName;
                if (emailSpan) emailSpan.textContent = email;
                if (apiKeySpan) apiKeySpan.textContent = apiKey ? 'Encrypted' : '';
                if (clientIdSpan) clientIdSpan.textContent = clientId ? 'Encrypted' : '';
                if (clientSecretSpan) clientSecretSpan.textContent = clientSecret ? 'Encrypted' : '';
                if (refreshTokenSpan) refreshTokenSpan.textContent = refreshToken ? 'Encrypted' : '';
                if (additionalSpan) additionalSpan.textContent = additionalData ? 'Encrypted' : '';

                toggleEditAccountRow(row, false);
            } else {
                showAccountsNotice('error', data.data);
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showAccountsNotice('error', 'Ajax request failed.');
        });
    }

    /**
     * Show a notice for accounts in #sdm-accounts-notice
     */
    function showAccountsNotice(type, message) {
        if (!accountsNotice) return;
        var cssClass = (type === 'error') ? 'notice-error' : 'notice-success';
        accountsNotice.innerHTML = '<div class="notice ' + cssClass + ' is-dismissible"><p>' + message + '</p></div>';

        setTimeout(function() {
            if (accountsNotice.firstChild) {
                accountsNotice.removeChild(accountsNotice.firstChild);
            }
        }, 5000);
    }

    // 6) Services: Add, Edit, Delete
    var addServiceForm = document.getElementById('sdm-add-service-form');
    var servicesNotice = document.getElementById('sdm-services-notice');
    var servicesTable = document.getElementById('sdm-services-table');

    // Add service
    if (addServiceForm && servicesNotice) {
        addServiceForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(addServiceForm);
            formData.append('action', 'sdm_add_service');

            var submitButton = addServiceForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                submitButton.disabled = false;
                if (data.success) {
                    showServicesNotice('updated', 'Service added (ID: ' + data.data.service_id + ')');
                    addServiceForm.reset();
                    location.reload(); // or dynamically add row
                } else {
                    showServicesNotice('error', data.data);
                }
            })
            .catch(function(error) {
                console.error(error);
                showServicesNotice('error', 'Request failed.');
                submitButton.disabled = false;
            });
        });
    }

    // Inline edit & delete
    if (servicesTable && servicesNotice) {
        servicesTable.addEventListener('click', function(e) {
            // Edit
            if (e.target.classList.contains('sdm-edit-service')) {
                e.preventDefault();
                var row = e.target.closest('tr');
                toggleEditServiceRow(row, true);
            }
            // Save
            else if (e.target.classList.contains('sdm-save-service')) {
                e.preventDefault();
                var row = e.target.closest('tr');
                saveServiceRow(row);
            }
            // Delete
            else if (e.target.classList.contains('sdm-delete-service')) {
                e.preventDefault();
                if (!confirm('Are you sure?')) {
                    return;
                }
                var row = e.target.closest('tr');
                var serviceId = row.getAttribute('data-service-id');
                var nonce = row.getAttribute('data-update-nonce');

                var formData = new FormData();
                formData.append('action', 'sdm_delete_service');
                formData.append('sdm_main_nonce_field', nonce);
                formData.append('service_id', serviceId);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        showServicesNotice('updated', data.data.message);
                        row.parentNode.removeChild(row);
                    } else {
                        showServicesNotice('error', data.data);
                    }
                })
                .catch(function(error) {
                    console.error(error);
                    showServicesNotice('error', 'Request failed.');
                });
            }
        });
    }

    function toggleEditServiceRow(row, editMode) {
        var displayElems = row.querySelectorAll('.sdm-display-value');
        var editInputs = row.querySelectorAll('.sdm-edit-input');
        var editLink = row.querySelector('.sdm-edit-service');
        var saveLink = row.querySelector('.sdm-save-service');

        if (editMode) {
            displayElems.forEach(function(el) { el.classList.add('sdm-hidden'); });
            editInputs.forEach(function(el) { el.classList.remove('sdm-hidden'); });
            editLink.classList.add('sdm-hidden');
            saveLink.classList.remove('sdm-hidden');
        } else {
            displayElems.forEach(function(el) { el.classList.remove('sdm-hidden'); });
            editInputs.forEach(function(el) { el.classList.add('sdm-hidden'); });
            editLink.classList.remove('sdm-hidden');
            saveLink.classList.add('sdm-hidden');
        }
    }

    function saveServiceRow(row) {
        var serviceId = row.getAttribute('data-service-id');
        var nonce = row.getAttribute('data-update-nonce');

        var serviceNameInput = row.querySelector('input[name="service_name"]');
        var authMethodInput = row.querySelector('input[name="auth_method"]');
        var additionalParamsInput = row.querySelector('textarea[name="additional_params"]');

        var serviceName = serviceNameInput.value;
        var authMethod = authMethodInput.value;
        var additionalParams = additionalParamsInput.value;

        var formData = new FormData();
        formData.append('action', 'sdm_update_service');
        formData.append('sdm_main_nonce_field', nonce);
        formData.append('service_id', serviceId);
        formData.append('service_name', serviceName);
        formData.append('auth_method', authMethod);
        formData.append('additional_params', additionalParams);

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                showServicesNotice('updated', data.data.message);

                // Update displayed values
                row.querySelector('.column-service-name .sdm-display-value').textContent = serviceName;
                row.querySelector('.column-auth-method .sdm-display-value').textContent = authMethod;
                row.querySelector('.column-additional-params .sdm-display-value').textContent = additionalParams;

                toggleEditServiceRow(row, false);
            } else {
                showServicesNotice('error', data.data);
            }
        })
        .catch(function(error) {
            console.error(error);
            showServicesNotice('error', 'Request failed.');
        });
    }

    function showServicesNotice(type, message) {
        if (!servicesNotice) return;
        var cssClass = (type === 'error') ? 'notice-error' : 'notice-success';
        servicesNotice.innerHTML = '<div class="notice ' + cssClass + ' is-dismissible"><p>' + message + '</p></div>';
        setTimeout(function() {
            if (servicesNotice.firstChild) {
                servicesNotice.removeChild(servicesNotice.firstChild);
            }
        }, 5000);
    }
});