document.addEventListener('DOMContentLoaded', () => {
    // Получаем глобальный nonce
    const mainNonceField = document.getElementById('sdm-main-nonce');
    const mainNonce = mainNonceField ? mainNonceField.value : '';
    console.log('Main nonce:', mainNonce);

    // Получаем текущий ID проекта
    const projectSelector = document.getElementById('sdm-project-selector');
    const currentProjectId = projectSelector ? parseInt(projectSelector.value) : 0;

    // Вспомогательная функция для управления спиннером в кнопках
    const toggleButtonSpinner = (button, enable, spinnerHTML = '<span class="spinner is-active" style="float:none;margin:0 5px;"></span>') => {
        if (!button) return;
        if (enable) {
            button.disabled = true;
            button.innerHTML += spinnerHTML;
        } else {
            button.disabled = false;
            button.innerHTML = button.innerHTML.replace(spinnerHTML, '');
        }
    };

    // Вспомогательная функция для отображения уведомлений
    const showSitesNotice = (type, message) => {
        const noticeContainer = document.getElementById('sdm-sites-notice');
        if (!noticeContainer) return;
        const cssClass = (type === 'error') ? 'notice-error' : 'notice-success';
        noticeContainer.innerHTML = `<div class="notice ${cssClass} is-dismissible"><p>${message}</p><button class="notice-dismiss" type="button">×</button></div>`;
        const dismissBtn = noticeContainer.querySelector('.notice-dismiss');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', () => {
                noticeContainer.innerHTML = '';
            });
        }
    };

    // Обработка отправки формы добавления нового сайта через AJAX
    const addSiteForm = document.getElementById('sdm-add-site-form');
    if (addSiteForm) {
        addSiteForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const formData = new FormData(addSiteForm);
            const mainDomain = formData.get('main_domain');
            const language = formData.get('language').trim();
            const submitButton = addSiteForm.querySelector('button[type="submit"]');

            if (!mainDomain) {
                showSitesNotice('error', 'Main Domain is required.');
                return;
            }
            if (!language) {
                showSitesNotice('error', 'Language is required.');
                return;
            }

            toggleButtonSpinner(submitButton, true);

            // Сначала валидация домена
            const validateData = new FormData();
            validateData.append('action', 'sdm_validate_domain');
            validateData.append('domain', mainDomain);
            validateData.append('sdm_main_nonce_field', mainNonce);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: validateData
            })
            .then(response => response.json())
            .then(data => {
                toggleButtonSpinner(submitButton, false);
                if (data.success) {
                    // Добавляем дополнительные поля и отправляем данные для создания сайта
                    formData.append('action', 'sdm_add_site');
                    formData.append('sdm_main_nonce_field', mainNonce);
                    toggleButtonSpinner(submitButton, true);
                    fetch(ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        toggleButtonSpinner(submitButton, false);
                        if (data.success) {
                            showSitesNotice('updated', 'Site added successfully (ID: ' + data.data.site_id + ')');
                            location.reload();
                        } else {
                            showSitesNotice('error', data.data);
                        }
                    })
                    .catch(error => {
                        console.error('Add site error:', error);
                        toggleButtonSpinner(submitButton, false);
                        showSitesNotice('error', 'Ajax request failed.');
                    });
                } else {
                    showSitesNotice('error', data.data);
                }
            })
            .catch(error => {
                console.error('Domain validation error:', error);
                toggleButtonSpinner(submitButton, false);
                showSitesNotice('error', 'Ajax request failed during domain validation.');
            });
        });
    }

    // Обработка редактирования существующих сайтов
    const sitesTable = document.getElementById('sdm-sites-table');
    if (sitesTable) {
        sitesTable.addEventListener('click', (e) => {
            if (e.target.classList.contains('sdm-edit-site')) {
                e.preventDefault();
                const row = e.target.closest('tr');
                toggleEditRow(row, true);
                initializeMainDomainSelect(row);
            } else if (e.target.classList.contains('sdm-save-site')) {
                e.preventDefault();
                const row = e.target.closest('tr');
                saveRow(row);
            }
        });
    }

    // Функция переключения режима редактирования строки
    const toggleEditRow = (row, editMode) => {
        const displayElems = row.querySelectorAll('.sdm-display-value');
        const editInputs = row.querySelectorAll('.sdm-edit-input');
        const editLink = row.querySelector('.sdm-edit-site');
        const saveLink = row.querySelector('.sdm-save-site');

        if (editMode) {
            displayElems.forEach(el => el.classList.add('sdm-hidden'));
            editInputs.forEach(el => el.classList.remove('sdm-hidden'));
            editLink.classList.add('sdm-hidden');
            saveLink.classList.remove('sdm-hidden');
            initializeMainDomainSelect(row);
        } else {
            displayElems.forEach(el => el.classList.remove('sdm-hidden'));
            editInputs.forEach(el => el.classList.add('sdm-hidden'));
            editLink.classList.remove('sdm-hidden');
            saveLink.classList.add('sdm-hidden');
            const mainDomainSelect = row.querySelector('select[name="main_domain"]');
            if (mainDomainSelect && window.jQuery) {
                jQuery(mainDomainSelect).select2('destroy').removeClass('select2-hidden-accessible select2-initialized');
            }
        }
    };

    // Инициализация select2 для выбора домена
    const initializeMainDomainSelect = (row) => {
        if (currentProjectId > 0) {
            const mainDomainSelect = row ? row.querySelector('select[name="main_domain"]') : document.getElementById('main_domain');
            if (mainDomainSelect && !mainDomainSelect.classList.contains('select2-initialized')) {
                fetchNonBlockedDomainsForSelect(mainDomainSelect);
                if (window.jQuery) {
                    jQuery(mainDomainSelect).select2({
                        width: '100%',
                        placeholder: 'Select a domain',
                        allowClear: true
                    }).on('select2:select', (e) => {
                        mainDomainSelect.value = e.params.data.id;
                        console.log('Selected value:', mainDomainSelect.value);
                    }).on('select2:open', () => {
                        console.log('Select2 opened for', mainDomainSelect);
                    });
                }
                mainDomainSelect.classList.add('select2-initialized');
            }
        }
    };

    // Сохранение изменений в строке таблицы
    const saveRow = (row) => {
        const siteId = row.getAttribute('data-site-id');
        const nonce = row.getAttribute('data-update-nonce');

        const siteNameInput = row.querySelector('input[name="site_name"]');
        const mainDomainSelect = row.querySelector('select[name="main_domain"]');
        const serverIpInput = row.querySelector('input[name="server_ip"]');
        const languageInput = row.querySelector('input[name="language"]');
        const monitoringInputs = row.querySelectorAll('input[name^="monitoring"]');
        const saveLink = row.querySelector('.sdm-save-site');

        if (!siteNameInput || !mainDomainSelect || !serverIpInput || !languageInput || !saveLink) {
            console.error('Missing required elements:', { siteNameInput, mainDomainSelect, serverIpInput, languageInput, saveLink });
            return;
        }

        const mainDomain = window.jQuery ? jQuery(mainDomainSelect).val() : mainDomainSelect.value;
        if (!mainDomain) {
            showSitesNotice('error', 'Main Domain is required.');
            return;
        }

        // Сбор настроек мониторинга
        const monitoring_settings = { enabled: false, types: {} };
        monitoringInputs.forEach(input => {
            const name = input.getAttribute('name');
            if (name === 'monitoring[enabled]') {
                monitoring_settings.enabled = input.checked;
            } else if (name && /monitoring\[types\]\[(.*?)\]/.test(name)) {
                const type = name.match(/monitoring\[types\]\[(.*?)\]/)[1];
                monitoring_settings.types[type] = input.checked;
            }
        });

        saveSite(
            row,
            siteId,
            nonce,
            siteNameInput.value,
            mainDomain,
            serverIpInput.value,
            languageInput.value,
            monitoring_settings,
            saveLink
        );
    };

    // Отправка данных для обновления сайта
    const saveSite = (row, siteId, nonce, siteName, mainDomain, serverIp, language, monitoring_settings, saveLink) => {
        const formData = new FormData();
        formData.append('action', 'sdm_update_site');
        formData.append('sdm_main_nonce_field', nonce);
        formData.append('site_id', siteId);
        formData.append('site_name', siteName);
        formData.append('main_domain', mainDomain);
        formData.append('server_ip', serverIp);
        formData.append('language', language);
        formData.append('monitoring_settings', JSON.stringify(monitoring_settings));

        saveLink.innerHTML = '<span class="spinner is-active" style="float:none;margin:0;"></span>';

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok ' + response.statusText);
            }
            return response.json();
        })
        .then(data => {
            saveLink.innerHTML = 'Save';
            if (data.success) {
                const siteNameSpan = row.querySelector('.column-site-name .sdm-display-value');
                const mainDomainSpan = row.querySelector('.column-main-domain .sdm-display-value');
                const serverIpSpan = row.querySelector('.column-server-ip .sdm-display-value');
                const languageSpan = row.querySelector('.column-language .sdm-display-value');
                const monitoringSpan = row.querySelector('.column-monitoring .sdm-display-value');
                if (siteNameSpan) siteNameSpan.textContent = siteName;
                if (mainDomainSpan) mainDomainSpan.textContent = mainDomain;
                if (serverIpSpan) serverIpSpan.textContent = serverIp ? serverIp : '(Not set)';
                if (languageSpan) languageSpan.textContent = language ? language : '(Not set)';
                if (monitoringSpan) {
                    monitoringSpan.textContent = (monitoring_settings.types.RusRegBL ? 'RusRegBL ' : '') +
                                                 (monitoring_settings.types.Http ? 'Http ' : '') || 'None';
                }
                toggleEditRow(row, false);
                showSitesNotice('updated', data.data.message);
            } else {
                showSitesNotice('error', data.data.message || 'Unknown error');
            }
        })
        .catch(error => {
            console.error('Update site error:', error);
            saveLink.innerHTML = 'Save';
            showSitesNotice('error', 'Ajax request failed: ' + error.message);
        });
    };


    // Функция для загрузки свободных доменов для select'а
    const fetchNonBlockedDomainsForSelect = (selectElement) => {
        const projectId = document.getElementById('sdm-project-selector').value;
        const siteId = selectElement.closest('tr') ? selectElement.closest('tr').getAttribute('data-site-id') : 0;
        if (projectId > 0) {
            const formData = new FormData();
            formData.append('action', 'sdm_get_non_blocked_domains_for_site');
            formData.append('project_id', projectId);
            formData.append('site_id', siteId);
            formData.append('sdm_main_nonce_field', mainNonce);
            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let options = '<option value="">Select a domain</option>';
                    const currentDomain = selectElement.dataset.current || '';
                    data.data.forEach(domain => {
                        const selected = domain === currentDomain ? ' selected' : '';
                        options += `<option value="${domain}"${selected}>${domain}</option>`;
                    });
                    selectElement.innerHTML = options;
                    if (window.jQuery) {
                        jQuery(selectElement).select2({
                            width: '100%',
                            placeholder: 'Select a domain',
                            allowClear: true
                        });
                    }
                }
            })
            .catch(error => {
                console.error('Fetch non-blocked domains error:', error);
            });
        }
    };

    if (currentProjectId > 0) {
        initializeMainDomainSelect();
    }

    // Обработка удаления сайта
    const deleteSiteButtons = document.querySelectorAll('.sdm-delete-site');
    deleteSiteButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            if (!confirm('Are you sure you want to delete this site?')) return;
            const row = button.closest('tr');
            const siteId = row.getAttribute('data-site-id');
            const formData = new FormData();
            formData.append('action', 'sdm_delete_site');
            formData.append('site_id', siteId);
            formData.append('sdm_main_nonce_field', mainNonce);
            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSitesNotice('updated', data.data.message);
                    row.parentNode.removeChild(row);
                } else {
                    showSitesNotice('error', data.data);
                }
            })
            .catch(error => {
                console.error('Delete site error:', error);
                showSitesNotice('error', 'Ajax request failed.');
            });
        });
    });

    // Обработка включения мониторинга через HostTracker
    const enableMonitoringButtons = document.querySelectorAll('.sdm-enable-monitoring');
    enableMonitoringButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
        const row = button.closest('tr');
        const siteId = row.getAttribute('data-site-id');
        let rusregbl = row.getAttribute('data-rusregbl') === '1';
        let http = row.getAttribute('data-http') === '1';

        // Если мониторинг ещё не был включён, будем создавать оба типа задач
        if (!rusregbl && !http) {
            rusregbl = true;
            http = true;
        }

            const formData = new FormData();
            formData.append('action', 'sdm_enable_monitoring');
            formData.append('site_id', siteId);
            if (rusregbl) formData.append('types[]', 'RusRegBL');
            if (http) formData.append('types[]', 'Http');
            formData.append('sdm_main_nonce_field', mainNonce);

            toggleButtonSpinner(button, true);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                toggleButtonSpinner(button, false);
                if (data.success) {
                    row.dataset.monitoringEnabled = '1';
                    if (rusregbl) row.dataset.rusregbl = '1';
                    if (http) row.dataset.http = '1';
                    showSitesNotice('updated', data.data.message);
                } else {
                    showSitesNotice('error', data.data.message || data.data);
                }
            })
            .catch(error => {
                console.error('Enable monitoring error:', error);
                toggleButtonSpinner(button, false);
                showSitesNotice('error', 'Ajax request failed.');
            });
        });
    });

    // Yandex verification via CloudFlare DNS
    const yandexButtons = document.querySelectorAll('.sdm-yandex-webmaster');
    yandexButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            const row = button.closest('tr');
            const siteId = row.getAttribute('data-site-id');

            const formData = new FormData();
            formData.append('action', 'sdm_add_site_to_yandex');
            formData.append('site_id', siteId);
            formData.append('sdm_main_nonce_field', mainNonce);

            toggleButtonSpinner(button, true);
            showSitesNotice('updated', 'Starting Yandex verification...');

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let msg = data.data.message;
                    if (data.data.url) {
                        msg += ' <a href="' + data.data.url + '" target="_blank">Yandex Webmaster</a>';
                    }
                    showSitesNotice('updated', msg);
                    pollYandexVerification(siteId, button, 0);
                } else {
                    toggleButtonSpinner(button, false);
                    showSitesNotice('error', data.data.message || data.data);
                }
            })
            .catch(error => {
                console.error('Yandex verification error:', error);
                toggleButtonSpinner(button, false);
                showSitesNotice('error', 'Ajax request failed.');
            });
        });
    });

    const pollYandexVerification = (siteId, button, attempt) => {
        setTimeout(() => {
            const fd = new FormData();
            fd.append('action', 'sdm_check_yandex_verification');
            fd.append('site_id', siteId);
            fd.append('sdm_main_nonce_field', mainNonce);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    toggleButtonSpinner(button, false);
                    showSitesNotice('updated', 'Yandex verification successful.');
                } else {
                    if (attempt < 4) {
                        showSitesNotice('updated', 'Waiting for DNS propagation... Checking again soon.');
                        pollYandexVerification(siteId, button, attempt + 1);
                    } else {
                        toggleButtonSpinner(button, false);
                        showSitesNotice('error', 'Verification is still pending. Please try again later.');
                    }
                }
            })
            .catch(error => {
                console.error('Yandex check error:', error);
                toggleButtonSpinner(button, false);
                showSitesNotice('error', 'Ajax request failed.');
            });
        }, 15000);
    };

        document.addEventListener('click', (e) => {
        // Клик по кнопке-триггеру (троеточие)
        const trigger = e.target.closest('.sdm-actions-trigger');
        if (trigger) {
            e.preventDefault();
            // Ищем выпадающее меню рядом
            const menuWrap = trigger.closest('.sdm-actions-menu');
            const dropdown = menuWrap.querySelector('.sdm-actions-dropdown');

            // Закрываем все другие открытые меню
            document.querySelectorAll('.sdm-actions-dropdown').forEach(dd => dd.style.display = 'none');

            // Переключаем текущее
            if (dropdown.style.display === 'block') {
                dropdown.style.display = 'none';
            } else {
                dropdown.style.display = 'block';
            }
            return;
        }

        // Если клик вне меню - закрываем все открытые
        if (!e.target.closest('.sdm-actions-dropdown')) {
            document.querySelectorAll('.sdm-actions-dropdown').forEach(dd => dd.style.display = 'none');
        }
    });

});
