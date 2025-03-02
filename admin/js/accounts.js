document.addEventListener('DOMContentLoaded', function() {
    var ajax_url = SDM_Accounts_Data.ajax_url;
    var nonce = SDM_Accounts_Data.nonce;
    var servicesOptions = SDM_Accounts_Data.servicesOptions;

    // Функция уведомлений
    function showNotice(type, message) {
        var notice = document.createElement('div');
        notice.className = 'notice notice-' + (type === 'error' ? 'error' : 'updated') + ' is-dismissible';
        notice.innerHTML = '<p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>';
        var noticeContainer = document.getElementById('sdm-accounts-notice');
        noticeContainer.insertBefore(notice, noticeContainer.firstChild);

        notice.querySelector('.notice-dismiss').addEventListener('click', function() {
            notice.remove();
        });

        setTimeout(function() {
            if (notice.parentNode) notice.remove();
        }, 5000);
    }

    // Динамическая генерация полей с отладкой и обработкой дополнительных полей
    function generateDynamicFields(service) {
        var option = document.getElementById('service').querySelector(`option[value="${service}"]`);
        var paramsStr = option.dataset.params;
        var debugInfo = option.dataset.debug; // Для отладки
        console.log('Service:', service, 'Params String:', paramsStr, 'Debug:', debugInfo);

        var dynamicFields = document.getElementById('dynamic-fields');
        if (!dynamicFields) {
            console.error('Dynamic fields container not found');
            showNotice('error', 'Error loading form fields.');
            return;
        }
        dynamicFields.innerHTML = '';

        if (!paramsStr) {
            console.warn('No params found for service:', service);
            showNotice('warning', 'No configuration available for this service. Please contact support.');
            return;
        }

        try {
            var params = JSON.parse(paramsStr) || {};
            console.log('Parsed Params:', params);

            // Обработка required_fields
            params.required_fields.forEach(field => {
                dynamicFields.innerHTML += `
                    <div class="sdm-form-field">
                        <label for="${field}">${field.replace('_', ' ').toUpperCase()}</label>
                        <input type="text" name="${field}" id="${field}" class="sdm-input sdm-required-field" required>
                    </div>`;
            });

            // Обработка optional_fields
            params.optional_fields.forEach(field => {
                dynamicFields.innerHTML += `
                    <div class="sdm-form-field">
                        <label for="${field}">${field.replace('_', ' ').toUpperCase()} (optional)</label>
                        <input type="text" name="${field}" id="${field}" class="sdm-input">
                    </div>`;
            });

            // Обработка task_type_options для HostTracker (если есть)
            if (params.task_type_options && service === 'HostTracker') {
                dynamicFields.innerHTML += `
                    <div class="sdm-form-field">
                        <label for="task_type">Task Type (optional)</label>
                        <select name="task_type" id="task_type" class="sdm-input">
                            ${params.task_type_options.map(option => `<option value="${option}" ${option === (params.default_task_type || '') ? 'selected' : ''}>${option}</option>`).join('')}
                        </select>
                    </div>`;
            }

        } catch (error) {
            console.error('Error parsing params:', error, paramsStr);
            showNotice('error', 'Invalid service configuration. Please contact support.');
        }
    }

    // Инициализация полей при загрузке страницы
    var serviceSelect = document.getElementById('service');
    if (serviceSelect) {
        var initialService = serviceSelect.value;
        generateDynamicFields(initialService); // Вызываем функцию для отображения полей по умолчанию
        serviceSelect.addEventListener('change', function() {
            generateDynamicFields(this.value);
        });
    }

    // Добавление нового аккаунта
    document.getElementById('sdm-add-account-form').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        formData.append('action', 'sdm_create_sdm_account');

        // Собираем данные из динамических полей, включая select для task_type
        document.querySelectorAll('#dynamic-fields input, #dynamic-fields select').forEach(input => {
            formData.append(input.name, input.value);
        });

        fetch(ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotice('updated', data.data.message);
                this.reset();
                fetchAccounts();
            } else {
                showNotice('error', data.data);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotice('error', 'Ajax request failed.');
        });
    });

    // Тестирование подключения
    document.querySelectorAll('.sdm-test-account').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            var accountId = this.getAttribute('data-account-id') || '';
            var formData = new FormData();
            formData.append('action', 'sdm_test_sdm_account');
            formData.append('sdm_main_nonce_field', nonce);
            formData.append('account_id', accountId);

            fetch(ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotice('updated', data.data.message);
                    var row = document.getElementById('account-row-' + accountId);
                    if (row) {
                        row.querySelector('.column-last-tested').textContent = new Date().toLocaleString();
                        row.querySelector('.column-status').textContent = 'Success';
                    }
                } else {
                    showNotice('error', data.data);
                    var row = document.getElementById('account-row-' + accountId);
                    if (row) {
                        row.querySelector('.column-status').textContent = 'Failed: ' + data.data;
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotice('error', 'Test connection failed.');
            });
        });
    });

    // Inline-редактирование
    var accountsTable = document.getElementById('sdm-accounts-table');
    if (accountsTable) {
        accountsTable.addEventListener('click', function(e) {
            if (e.target.classList.contains('sdm-edit-account')) {
                e.preventDefault();
                var row = e.target.closest('tr');
                toggleEditAccountRow(row, true);
            } else if (e.target.classList.contains('sdm-save-account')) {
                e.preventDefault();
                var row = e.target.closest('tr');
                saveAccountRow(row);
            } else if (e.target.classList.contains('sdm-delete-account')) {
                e.preventDefault();
                if (!confirm('Are you sure you want to delete this account?')) return;
                var row = e.target.closest('tr');
                var accountId = row.getAttribute('data-account-id');
                var nonce = row.getAttribute('data-update-nonce');

                var formData = new FormData();
                formData.append('action', 'sdm_delete_sdm_account');
                formData.append('sdm_main_nonce_field', nonce);
                formData.append('account_id', accountId);

                fetch(ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotice('updated', data.data.message);
                        row.parentNode.removeChild(row);
                    } else {
                        showNotice('error', data.data);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotice('error', 'Ajax request failed.');
                });
            }
        });
    }

    function toggleEditAccountRow(row, editMode) {
        var displayElems = row.querySelectorAll('.sdm-display-value');
        var editInputs = row.querySelectorAll('.sdm-edit-input');
        var editLink = row.querySelector('.sdm-edit-account');
        var saveLink = row.querySelector('.sdm-save-account');

        if (editMode) {
            // Загрузить дополнительные поля через AJAX
            var service = row.dataset.service;
            fetch(ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: new FormData([['action', 'sdm_get_service_params'], ['service', service], ['sdm_main_nonce_field', nonce]])
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    var params = data.data.params;
                    var dynamicFields = row.querySelector('.dynamic-fields') || row.insertAdjacentHTML('beforeend', '<div class="dynamic-fields"></div>');
                    dynamicFields.innerHTML = '';
                    params.required_fields.forEach(field => {
                        dynamicFields.innerHTML += `
                            <div class="sdm-form-field">
                                <label>${field}</label>
                                <input class="sdm-edit-input sdm-hidden sdm-input sdm-required-field" type="text" name="${field}" value="${row.dataset[field] || ''}" required>
                            </div>`;
                    });
                    params.optional_fields.forEach(field => {
                        dynamicFields.innerHTML += `
                            <div class="sdm-form-field">
                                <label>${field} (optional)</label>
                                <input class="sdm-edit-input sdm-hidden sdm-input" type="text" name="${field}" value="${row.dataset[field] || ''}">
                            </div>`;
                    });

                    // Обработка task_type_options для HostTracker (если есть)
                    if (params.task_type_options && service === 'HostTracker') {
                        dynamicFields.innerHTML += `
                            <div class="sdm-form-field">
                                <label for="task_type">Task Type (optional)</label>
                                <select class="sdm-edit-input sdm-hidden sdm-input" name="task_type" id="task_type">
                                    ${params.task_type_options.map(option => `<option value="${option}" ${option === (params.default_task_type || '') ? 'selected' : ''}>${option}</option>`).join('')}
                                </select>
                            </div>`;
                    }
                    displayElems.forEach(el => el.classList.add('sdm-hidden'));
                    editInputs.forEach(el => el.classList.remove('sdm-hidden'));
                    editLink.classList.add('sdm-hidden');
                    saveLink.classList.remove('sdm-hidden');
                } else {
                    showNotice('error', data.data);
                }
            })
            .catch(error => {
                console.error('Error fetching service params:', error);
                showNotice('error', 'Failed to load account fields.');
            });
        } else {
            displayElems.forEach(el => el.classList.remove('sdm-hidden'));
            editInputs.forEach(el => el.classList.add('sdm-hidden'));
            editLink.classList.remove('sdm-hidden');
            saveLink.classList.add('sdm-hidden');
        }
    }

    function saveAccountRow(row) {
        var accountId = row.getAttribute('data-account-id');
        var nonce = row.getAttribute('data-update-nonce');
        var formData = new FormData();
        formData.append('action', 'sdm_update_sdm_account');
        formData.append('sdm_main_nonce_field', nonce);
        formData.append('account_id', accountId);

        // Собираем данные из всех видимых input'ов и select'ов, включая динамические поля
        row.querySelectorAll('.sdm-edit-input:not(.sdm-hidden)').forEach(input => {
            formData.append(input.name, input.value);
        });

        fetch(ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotice('updated', data.data.message);
                // Обновляем отображаемые значения
                var displayElems = row.querySelectorAll('.sdm-display-value');
                displayElems.forEach(el => {
                    var name = el.className.match(/column-(\w+)/)[1];
                    el.textContent = formData.get(name) || '';
                });
                toggleEditAccountRow(row, false);
            } else {
                showNotice('error', data.data);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotice('error', 'Ajax request failed.');
        });
    }

    // Функция загрузки аккаунтов
    function fetchAccounts() {
        var formData = new FormData();
        formData.append('action', 'sdm_fetch_accounts');
        formData.append('sdm_main_nonce_field', nonce);

        fetch(ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                var table = document.getElementById('sdm-accounts-table').querySelector('tbody');
                table.innerHTML = '';
                data.data.accounts.forEach(account => {
                    table.innerHTML += `
                        <tr id="account-row-${account.id}" data-account-id="${account.id}" data-update-nonce="${nonce}" data-service="${account.service}">
                            <td class="column-project-id">${account.project_id}</td>
                            <td class="column-project-name">${account.project_name || '(No project)'}</td>
                            <td class="column-service"><span class="sdm-display-value">${account.service}</span><select class="sdm-edit-input sdm-hidden sdm-select" name="service">${servicesOptions}</select></td>
                            <td class="column-account-name"><span class="sdm-display-value">${account.account_name || ''}</span><input class="sdm-edit-input sdm-hidden sdm-input" type="text" name="account_name" value="${account.account_name || ''}"></td>
                            <td class="column-email"><span class="sdm-display-value">${account.email || ''}</span><input class="sdm-edit-input sdm-hidden sdm-input" type="email" name="email" value="${account.email || ''}"></td>
                            <td class="column-last-tested">${account.last_tested_at || 'Not tested'}</td>
                            <td class="column-status">${account.last_test_result || 'N/A'}</td>
                            <td class="column-created">${account.created_at}</td>
                            <td class="column-actions">
                                <a href="#" class="sdm-action-button sdm-edit sdm-edit-account">Edit</a>
                                <a href="#" class="sdm-action-button sdm-save sdm-save-account sdm-hidden">Save</a> |
                                <a href="#" class="sdm-action-button sdm-delete sdm-delete-account">Delete</a>
                                <a href="#" class="sdm-action-button sdm-test sdm-test-account" data-account-id="${account.id}">Test</a>
                            </td>
                        </tr>`;
                });
            } else {
                showNotice('error', data.data);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotice('error', 'Failed to fetch accounts.');
        });
    }

    // Вызываем при загрузке страницы
    fetchAccounts();
});