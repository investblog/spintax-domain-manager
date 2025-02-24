document.addEventListener('DOMContentLoaded', function() {
    // Проверяем, является ли текущая страница страницей редиректов
    var currentScreen = document.querySelector('body').className.match(/spintax-manager_page_sdm-redirects/);
    if (!currentScreen) {
        return; // Выходим, если это не страница редиректов
    }

    // Получаем глобальный nonce
    var mainNonceField = document.getElementById('sdm-main-nonce');
    var mainNonce = mainNonceField ? mainNonceField.value : '';

    // Получаем текущий ID проекта
    var projectSelector = document.getElementById('sdm-project-selector');
    var currentProjectId = projectSelector ? parseInt(projectSelector.value) : 0;

    // Флаг для отслеживания, была ли уже выполнена загрузка
    var isRedirectsLoaded = false;

    // Функция для загрузки редиректов
    function fetchRedirects(projectId) {
        
        if (isRedirectsLoaded) return; // Предотвращаем дублирование
        isRedirectsLoaded = true;

        var formData = new FormData();
        formData.append('action', 'sdm_fetch_redirects_list');
        formData.append('project_id', projectId);
        formData.append('sdm_main_nonce_field', mainNonce);

        var container = document.getElementById('sdm-redirects-container');
        container.innerHTML = '<p><span class="spinner"></span> Loading...</p>';

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                container.innerHTML = data.data.html;
                initializeDynamicListeners();
            } else {
                showRedirectsNotice('error', data.data);
            }
            isRedirectsLoaded = false; // Сбрасываем флаг после загрузки
        })
        .catch(function(error) {
            console.error('Fetch redirects list error:', error);
            showRedirectsNotice('error', 'Ajax request failed.');
            container.innerHTML = '<p class="error">Error loading redirects.</p>';
            isRedirectsLoaded = false; // Сбрасываем флаг при ошибке
        });
    }

    // Инициализация при загрузке
    if (currentProjectId > 0) {
        fetchRedirects(currentProjectId);
    }

    // Обновление при смене проекта
    projectSelector.addEventListener('change', function() {
        isRedirectsLoaded = false; // Сбрасываем флаг при смене проекта
        if (this.value > 0) {
            fetchRedirects(this.value);
        } else {
            document.getElementById('sdm-redirects-container').innerHTML = '<p style="margin: 20px 0; color: #666;">Please select a project to view its redirects.</p>';
        }
    });

    // Синхронизация с CloudFlare
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
                    fetchRedirects(currentProjectId); // Обновляем таблицу
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

    // Инициализация динамических слушателей (для массовых действий и других кнопок)
    function initializeDynamicListeners() {
        // Удаление редиректа
        var deleteRedirectButtons = document.querySelectorAll('.sdm-delete-redirect');
        deleteRedirectButtons.forEach(function(button) {
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
                        fetchRedirects(currentProjectId);
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

        // Создание дефолтного редиректа
        var createRedirectButtons = document.querySelectorAll('.sdm-create-redirect');
        createRedirectButtons.forEach(function(button) {
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
                        fetchRedirects(currentProjectId);
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

        // Массовые действия
        var massActionSelectSite = document.querySelectorAll('.sdm-mass-action-select-site');
        var massActionApplySite = document.querySelectorAll('.sdm-mass-action-apply-site');

        massActionSelectSite.forEach(function(select) {
            var siteId = select.getAttribute('data-site-id');
            var applyBtn = document.querySelector('.sdm-mass-action-apply-site[data-site-id="' + siteId + '"]');

            if (applyBtn) {
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

                        var spinner = document.createElement('span');
                        spinner.className = 'spinner is-active';
                        spinner.style.float = 'none';
                        spinner.style.margin = '0 5px';
                        this.disabled = true;
                        this.innerHTML = '';
                        this.appendChild(spinner);

                        var formData = new FormData();
                        formData.append('action', 'sdm_mass_create_default_redirects');
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
                            applyBtn.disabled = false;
                            applyBtn.innerHTML = 'Apply';
                            if (data.success) {
                                showRedirectsNotice('updated', data.data.message);
                                fetchRedirects(currentProjectId);
                            } else {
                                showRedirectsNotice('error', data.data);
                            }
                            spinner.remove();
                        })
                        .catch(function(error) {
                            console.error('Mass create default redirects error:', error);
                            showRedirectsNotice('error', 'Ajax request failed.');
                            applyBtn.disabled = false;
                            applyBtn.innerHTML = 'Apply';
                            spinner.remove();
                        });
                    } else if (action === 'sync_cloudflare') {
                        if (!confirm('Are you sure you want to sync the selected redirects with CloudFlare for this site?')) return;

                        var spinner = document.createElement('span');
                        spinner.className = 'spinner is-active';
                        spinner.style.float = 'none';
                        spinner.style.margin = '0 5px';
                        this.disabled = true;
                        this.innerHTML = '';
                        this.appendChild(spinner);

                        var formData = new FormData();
                        formData.append('action', 'sdm_mass_sync_redirects_to_cloudflare');
                        formData.append('redirect_ids', JSON.stringify(selected));
                        formData.append('sdm_main_nonce_field', mainNonce);

                        fetch(ajaxurl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: formData
                        })
                        .then(function(response) { return response.json(); })
                        .then(function(data) {
                            applyBtn.disabled = false;
                            applyBtn.innerHTML = 'Apply';
                            if (data.success) {
                                showRedirectsNotice('updated', data.data.message);
                                fetchRedirects(currentProjectId);
                            } else {
                                showRedirectsNotice('error', data.data);
                            }
                            spinner.remove();
                        })
                        .catch(function(error) {
                            console.error('Mass sync redirects error:', error);
                            showRedirectsNotice('error', 'Ajax request failed.');
                            applyBtn.disabled = false;
                            applyBtn.innerHTML = 'Apply';
                            spinner.remove();
                        });
                    }
                });
            }
        });

        // "Select all" для каждого сайта
        var selectAllSiteRedirects = document.querySelectorAll('.sdm-select-all-site-redirects');
        selectAllSiteRedirects.forEach(function(checkbox) {
            var siteId = checkbox.getAttribute('data-site-id');
            checkbox.addEventListener('change', function() {
                var checked = this.checked;
                document.querySelectorAll('.sdm-redirect-checkbox[data-site-id="' + siteId + '"]').forEach(function(cb) {
                    cb.checked = checked;
                });
            });
        });
    }

    // Показ уведомлений
    function showRedirectsNotice(type, message) {
        var noticeContainer = document.getElementById('sdm-redirects-notice');
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