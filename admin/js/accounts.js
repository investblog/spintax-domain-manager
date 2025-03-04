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
        
        // Удаляем все существующие уведомления перед добавлением нового
        while (noticeContainer.firstChild) {
            noticeContainer.removeChild(noticeContainer.firstChild);
        }
        noticeContainer.appendChild(notice);

        notice.querySelector('.notice-dismiss').addEventListener('click', function() {
            notice.remove();
        });

        setTimeout(function() {
            if (notice.parentNode) notice.remove();
        }, 5000);
    }

    // Динамическая генерация полей с отладкой и обработкой дополнительных полей
    function generateDynamicFields(service, accountData = {}) {
        var option = document.querySelector(`option[value="${service}"]`, document.getElementById('edit-service')) || document.querySelector(`#service option[value="${service}"]`);
        var paramsStr = option ? option.dataset.params : '';
        var debugInfo = option ? option.dataset.debug : ''; // Для отладки
        console.log('Service:', service, 'Params String:', paramsStr, 'Debug:', debugInfo, 'Account Data:', accountData);

        var dynamicFields = document.createElement('div');
        dynamicFields.id = 'edit-account-fields';
        dynamicFields.innerHTML = '';

        if (!paramsStr) {
            console.warn('No params found for service:', service);
            showNotice('warning', 'No configuration available for this service. Please contact support.');
            return dynamicFields;
        }

        try {
            var params = JSON.parse(paramsStr) || {};
            console.log('Parsed Params:', params);

            // Обработка required_fields
            params.required_fields.forEach(field => {
                dynamicFields.innerHTML += `
                    <div class="sdm-form-field">
                        <label for="${field}">${field.replace('_', ' ').toUpperCase()}</label>
                        <input type="text" name="${field}" id="${field}" class="sdm-input sdm-required-field" value="${accountData[field] || ''}" required>
                    </div>`;
            });

            // Обработка optional_fields
            params.optional_fields.forEach(field => {
                dynamicFields.innerHTML += `
                    <div class="sdm-form-field">
                        <label for="${field}">${field.replace('_', ' ').toUpperCase()} (optional)</label>
                        <input type="text" name="${field}" id="${field}" class="sdm-input" value="${accountData[field] || ''}">
                    </div>`;
            });

            // Обработка task_type_options для HostTracker (если есть)
            if (params.task_type_options && service === 'HostTracker') {
                dynamicFields.innerHTML += `
                    <div class="sdm-form-field">
                        <label for="task_type">Task Type (optional)</label>
                        <select name="task_type" id="task_type" class="sdm-input">
                            ${params.task_type_options.map(option => `<option value="${option}" ${option === (accountData['task_type'] || params.default_task_type || '') ? 'selected' : ''}>${option}</option>`).join('')}
                        </select>
                    </div>`;
            }

            return dynamicFields;
        } catch (error) {
            console.error('Error parsing params:', error, paramsStr);
            showNotice('error', 'Invalid service configuration. Please contact support.');
            return dynamicFields;
        }
    }

    // Инициализация полей при загрузке страницы для формы добавления
    var serviceSelect = document.getElementById('service');
    if (serviceSelect) {
        var initialService = serviceSelect.value;
        var formContainer = document.getElementById('dynamic-fields');
        if (formContainer) {
            formContainer.appendChild(generateDynamicFields(initialService));
        }
        serviceSelect.addEventListener('change', function() {
            var formContainer = document.getElementById('dynamic-fields');
            if (formContainer) {
                formContainer.innerHTML = '';
                formContainer.appendChild(generateDynamicFields(this.value));
            }
        });
    }

    // Добавление нового аккаунта (оставляем без изменений)
    document.getElementById('sdm-add-account-form').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        formData.append('action', 'sdm_create_sdm_account');

        // Собираем данные из динамических полей
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

    // Тестирование подключения (с делегированием событий)
    var accountsTable = document.getElementById('sdm-accounts-table');
    if (accountsTable) {
        accountsTable.addEventListener('click', function(e) {
            if (e.target.classList.contains('sdm-test-account')) {
                e.preventDefault();
                var button = e.target;
                var accountId = button.getAttribute('data-account-id') || '';
                var service = button.closest('tr').dataset.service;
                console.log('Testing connection for account ID:', accountId, 'Service:', service);
                var formData = new FormData();
                formData.append('action', 'sdm_test_sdm_account');
                formData.append('sdm_main_nonce_field', nonce);
                formData.append('account_id', accountId);

                fetch(ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(response => {
                    console.log('Test connection response status:', response.status, 'OK:', response.ok);
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Test connection response data:', data);
                    if (data.success) {
                        let message;
                        if (service === 'HostTracker' && data.data?.token) { // Проверяем, есть ли токен в ответе
                            message = `Token successfully obtained. Ticket: ${data.data.token}, Expiration: ${data.data.expirationTime || 'Not specified'}`;
                        } else {
                            message = data.data?.message || 'Connection tested successfully.';
                        }
                        showNotice('updated', message);
                        var row = document.getElementById('account-row-' + accountId);
                        if (row) {
                            row.querySelector('.column-last-tested').textContent = new Date().toLocaleString();
                            row.querySelector('.column-status').textContent = 'Success';
                        }
                    } else {
                        let errorMessage = data.data?.message || data.message || 'An unknown error occurred.';
                        showNotice('error', errorMessage);
                        var row = document.getElementById('account-row-' + accountId);
                        if (row) {
                            row.querySelector('.column-status').textContent = 'Failed: ' . errorMessage;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error testing connection:', error);
                    showNotice('error', 'Test connection failed.');
                });
            }
        });
    }

    // Удаление аккаунта
    if (accountsTable) {
        accountsTable.addEventListener('click', function(e) {
            if (e.target.classList.contains('sdm-delete-account')) {
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
            } else if (e.target.classList.contains('sdm-edit-account')) {
                e.preventDefault();
                var row = e.target.closest('tr');
                var accountId = row.getAttribute('data-account-id');
                var nonce = row.getAttribute('data-update-nonce');
                var service = row.getAttribute('data-service');

                // Открываем модальное окно или форму редактирования
                openEditForm(accountId, nonce, service, row);
            }
        });
    }

    function openEditForm(accountId, nonce, service, row) {
        var modal = document.getElementById('sdm-edit-modal');
        if (!modal) {
            console.error('Modal #sdm-edit-modal not found in DOM');
            showNotice('error', 'Modal window initialization failed. Please refresh the page.');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'sdm_get_account_details');
        formData.append('sdm_main_nonce_field', nonce);
        formData.append('account_id', accountId);

        console.log('Fetching account details for ID:', accountId, 'Nonce:', nonce, 'Service:', service, 'Modal found:', !!modal, 'Initial style:', modal.style.display, 'Initial classes:', modal.className, 'Computed style:', window.getComputedStyle(modal).display);

        fetch(ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status, 'OK:', response.ok);
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                var accountData = data.data.account;
                console.log('Account data received:', accountData);

                // Обновляем поля формы данными аккаунта
                var projectIdHidden = modal.querySelector('#edit-project_id_hidden');
                var accountIdHidden = modal.querySelector('#edit-account_id_hidden');
                var accountNameInput = modal.querySelector('#edit-account_name');
                var serviceDisplay = modal.querySelector('#edit-service_display'); // Используем read-only поле для отображения
                var emailInput = modal.querySelector('#edit-email');
                var dynamicFields = modal.querySelector('#edit-account-fields');

                if (projectIdHidden) projectIdHidden.value = accountData.project_id;
                if (accountIdHidden) accountIdHidden.value = accountId;
                if (accountNameInput) accountNameInput.value = accountData.account_name || ''; // Оставляем пустым, если нет имени
                if (serviceDisplay) serviceDisplay.value = service; // Отображаем сервис как read-only
                if (emailInput) emailInput.value = accountData.email || '';
                if (dynamicFields) {
                    dynamicFields.innerHTML = '';
                    dynamicFields.appendChild(generateDynamicFields(service, accountData));
                }

                // Убеждаемся, что модальное окно отображается
                if (modal.classList.contains('sdm-hidden') || modal.style.display === 'none' || window.getComputedStyle(modal).display === 'none') {
                    modal.classList.remove('sdm-hidden');
                    modal.style.display = 'block';
                    console.log('Forced modal display to block, className:', modal.className, 'Style:', modal.style.display, 'Computed style:', window.getComputedStyle(modal).display);
                }

                // Обработка закрытия модального окна
                modal.querySelectorAll('.sdm-modal-close').forEach(closeBtn => {
                    closeBtn.addEventListener('click', function() {
                        modal.classList.add('sdm-hidden');
                        modal.style.display = '';
                        console.log('Modal hidden, className:', modal.className, 'Style:', modal.style.display, 'Computed style:', window.getComputedStyle(modal).display);
                    });
                });

                // Сохранение изменений (обновляем для отправки всех данных, включая пустые, как в форме добавления)
                var editForm = document.getElementById('sdm-edit-account-form');
                if (editForm) {
                    editForm.addEventListener('submit', function(e) {
                        e.preventDefault();
                        var formData = new FormData(this);
                        formData.append('action', 'sdm_update_sdm_account');

                        // Собираем данные из всех полей формы, включая динамические и пустые, как в форме добавления
                        document.querySelectorAll('#edit-account-fields input, #edit-account-fields select').forEach(input => {
                            formData.append(input.name, input.value || ''); // Отправляем пустые значения как пустые строки
                        });

                        // Добавляем фиксированные поля, включая пустые, как в форме добавления
                        formData.append('project_id', projectIdHidden.value || '');
                        formData.append('account_id', accountIdHidden.value || '');
                        formData.append('account_name', accountNameInput.value || '');
                        formData.append('email', emailInput.value || '');
                        formData.append('service', service); // Фиксируем сервис, как в форме добавления

                        console.log('Form data submitted for update:', Object.fromEntries(formData.entries()));

                        fetch(ajax_url, {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showNotice('updated', data.data.message);
                                modal.classList.add('sdm-hidden');
                                modal.style.display = '';
                                fetchAccounts(); // Обновляем таблицу
                            } else {
                                showNotice('error', data.data);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showNotice('error', 'Ajax request failed.');
                        });
                    });
                } else {
                    console.error('Edit form not found in modal');
                }
            } else {
                showNotice('error', data.data);
            }
        })
        .catch(error => {
            console.error('Error fetching account details:', error);
            showNotice('error', 'Failed to load account details.');
        });
    }

    // Функция загрузки аккаунтов (исправляем бесконечный цикл)
    function fetchAccounts() {
        var formData = new FormData(); // Создаём пустой FormData
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
                table.innerHTML = ''; // Очищаем таблицу перед обновлением
                data.data.accounts.forEach(account => {
                    table.innerHTML += `
                        <tr id="account-row-${account.id}" data-account-id="${account.id}" data-update-nonce="${nonce}" data-service="${account.service}">
                            <td class="column-project-id">${account.project_id}</td>
                            <td class="column-project-name">${account.project_name || '(No project)'}</td>
                            <td class="column-service"><span class="sdm-display-value">${account.service}</span></td>
                            <td class="column-account-name"><span class="sdm-display-value">${account.account_name || ''}</span></td>
                            <td class="column-email"><span class="sdm-display-value">${account.email || ''}</span></td>
                            <td class="column-last-tested">${account.last_tested_at || 'Not tested'}</td>
                            <td class="column-status">${account.last_test_result || 'N/A'}</td>
                            <td class="column-created">${account.created_at}</td>
                            <td class="column-actions">
                                <a href="#" class="sdm-action-button sdm-edit sdm-edit-account">Edit</a> |
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