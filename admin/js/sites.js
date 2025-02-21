document.addEventListener('DOMContentLoaded', function() {

    // Получаем глобальный nonce из скрытого поля
    var mainNonceField = document.getElementById('sdm-main-nonce');
    var mainNonce = mainNonceField ? mainNonceField.value : '';

    // ----------------------------
    // 1. Handle "Add New Site" Form Submission via AJAX
    // ----------------------------
    var addSiteForm = document.getElementById('sdm-add-site-form');
    if (addSiteForm) {
        addSiteForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(addSiteForm);
            formData.append('action', 'sdm_add_site');
            formData.append('sdm_main_nonce_field', mainNonce);

            var submitButton = addSiteForm.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
            }

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (submitButton) {
                    submitButton.disabled = false;
                }
                if (data.success) {
                    showSitesNotice('updated', 'Site added successfully (ID: ' + data.data.site_id + ')');
                    // Обновляем страницу, чтобы увидеть добавленный сайт в таблице
                    location.reload();
                } else {
                    showSitesNotice('error', data.data);
                }
            })
            .catch(function(error) {
                console.error('Add site error:', error);
                if (submitButton) {
                    submitButton.disabled = false;
                }
                showSitesNotice('error', 'Ajax request failed.');
            });
        });
    }

    // ----------------------------
    // Inline Editing for Sites
    // ----------------------------
    var sitesTable = document.getElementById('sdm-sites-table');
    if (sitesTable) {
        sitesTable.addEventListener('click', function(e) {
            // Если клик по кнопке "Edit"
            if ( e.target.classList.contains('sdm-edit-site') ) {
                e.preventDefault();
                var row = e.target.closest('tr');
                toggleEditRow(row, true);
            }
            // Если клик по кнопке "Save"
            else if ( e.target.classList.contains('sdm-save-site') ) {
                e.preventDefault();
                var row = e.target.closest('tr');
                saveRow(row);
            }
        });
    }

    function toggleEditRow(row, editMode) {
        var displayElems = row.querySelectorAll('.sdm-display-value');
        var editInputs   = row.querySelectorAll('.sdm-edit-input');
        var editLink     = row.querySelector('.sdm-edit-site');
        var saveLink     = row.querySelector('.sdm-save-site');

        if (editMode) {
            displayElems.forEach(function(el){ el.classList.add('sdm-hidden'); });
            editInputs.forEach(function(el){ el.classList.remove('sdm-hidden'); });
            editLink.classList.add('sdm-hidden');
            saveLink.classList.remove('sdm-hidden');
        } else {
            displayElems.forEach(function(el){ el.classList.remove('sdm-hidden'); });
            editInputs.forEach(function(el){ el.classList.add('sdm-hidden'); });
            editLink.classList.remove('sdm-hidden');
            saveLink.classList.add('sdm-hidden');
        }
    }

    function saveRow(row) {
        var siteId = row.getAttribute('data-site-id');
        var nonce  = row.getAttribute('data-update-nonce');

        // Считываем новые значения из input
        var siteNameInput   = row.querySelector('input[name="site_name"]');
        var mainDomainInput = row.querySelector('input[name="main_domain"]');

        var siteName   = siteNameInput ? siteNameInput.value : '';
        var mainDomain = mainDomainInput ? mainDomainInput.value : '';

        // Формируем FormData для AJAX
        var formData = new FormData();
        formData.append('action', 'sdm_update_site');
        formData.append('sdm_main_nonce_field', nonce);
        formData.append('site_id', siteId);
        formData.append('site_name', siteName);
        formData.append('main_domain', mainDomain);

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                // Обновляем отображение
                var siteNameSpan   = row.querySelector('.column-site-name .sdm-display-value');
                var mainDomainSpan = row.querySelector('.column-main-domain .sdm-display-value');
                if (siteNameSpan) siteNameSpan.textContent = siteName;
                if (mainDomainSpan) mainDomainSpan.textContent = mainDomain;

                // Выключаем режим редактирования
                toggleEditRow(row, false);
                showSitesNotice('updated', data.data.message);
            } else {
                showSitesNotice('error', data.data);
            }
        })
        .catch(function(error) {
            console.error('Update site error:', error);
            showSitesNotice('error', 'Ajax request failed.');
        });
    }

    function showSitesNotice(type, message) {
        var noticeContainer = document.getElementById('sdm-sites-notice');
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

    // ----------------------------
    // 2. Handle Delete Site Action
    // ----------------------------
    var deleteSiteButtons = document.querySelectorAll('.sdm-delete-site');
    deleteSiteButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to delete this site?')) return;

            var row = this.closest('tr');
            var siteId = row.getAttribute('data-site-id');

            var formData = new FormData();
            formData.append('action', 'sdm_delete_site');
            formData.append('site_id', siteId);
            formData.append('sdm_main_nonce_field', mainNonce);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data.success) {
                    showSitesNotice('updated', data.data.message);
                    row.parentNode.removeChild(row);
                } else {
                    showSitesNotice('error', data.data);
                }
            })
            .catch(function(error) {
                console.error('Delete site error:', error);
                showSitesNotice('error', 'Ajax request failed.');
            });
        });
    });

    // ----------------------------
    // 3. Helper: Show Sites Notice
    // ----------------------------
    function showSitesNotice(type, message) {
        var noticeContainer = document.getElementById('sdm-sites-notice');
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
