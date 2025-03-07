document.addEventListener('DOMContentLoaded', function() {
    // Get the global nonce from the hidden field
    var mainNonceField = document.getElementById('sdm-main-nonce');
    var mainNonce = mainNonceField ? mainNonceField.value : '';
    console.log('Main nonce:', mainNonce);

    // Get current project ID from the selector
    var projectSelector = document.getElementById('sdm-project-selector');
    var currentProjectId = projectSelector ? parseInt(projectSelector.value) : 0;

    // Handle "Add New Site" Form Submission via AJAX
    var addSiteForm = document.getElementById('sdm-add-site-form');
    if (addSiteForm) {
        addSiteForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(addSiteForm);
            var mainDomain = formData.get('main_domain');
            var language = formData.get('language').trim();
            var submitButton = addSiteForm.querySelector('button[type="submit"]');

            if (!mainDomain) {
                showSitesNotice('error', 'Main Domain is required.');
                return;
            }
            if (!language) {
                showSitesNotice('error', 'Language is required.');
                return;
            }

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML += '<span class="spinner is-active" style="float:none;margin:0 5px;"></span>';
            }

            var validateFormData = new FormData();
            validateFormData.append('action', 'sdm_validate_domain');
            validateFormData.append('domain', mainDomain);
            validateFormData.append('sdm_main_nonce_field', mainNonce);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: validateFormData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = submitButton.innerHTML.replace('<span class="spinner is-active" style="float:none;margin:0 5px;"></span>', '');
                }

                if (data.success) {
                    formData.append('action', 'sdm_add_site');
                    formData.append('sdm_main_nonce_field', mainNonce);

                    if (submitButton) {
                        submitButton.disabled = true;
                        submitButton.innerHTML += '<span class="spinner is-active" style="float:none;margin:0 5px;"></span>';
                    }

                    fetch(ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: formData
                    })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.innerHTML = submitButton.innerHTML.replace('<span class="spinner is-active" style="float:none;margin:0 5px;"></span>', '');
                        }
                        if (data.success) {
                            showSitesNotice('updated', 'Site added successfully (ID: ' + data.data.site_id + ')');
                            location.reload();
                        } else {
                            showSitesNotice('error', data.data);
                        }
                    })
                    .catch(function(error) {
                        console.error('Add site error:', error);
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.innerHTML = submitButton.innerHTML.replace('<span class="spinner is-active" style="float:none;margin:0 5px;"></span>', '');
                        }
                        showSitesNotice('error', 'Ajax request failed.');
                    });
                } else {
                    showSitesNotice('error', data.data);
                }
            })
            .catch(function(error) {
                console.error('Domain validation error:', error);
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = submitButton.innerHTML.replace('<span class="spinner is-active" style="float:none;margin:0 5px;"></span>', '');
                }
                showSitesNotice('error', 'Ajax request failed during domain validation.');
            });
        });
    }

    // Inline Editing for Sites
    var sitesTable = document.getElementById('sdm-sites-table');
    if (sitesTable) {
        sitesTable.addEventListener('click', function(e) {
            if (e.target.classList.contains('sdm-edit-site')) {
                e.preventDefault();
                var row = e.target.closest('tr');
                toggleEditRow(row, true);
                initializeMainDomainSelect(row);
            } else if (e.target.classList.contains('sdm-save-site')) {
                e.preventDefault();
                var row = e.target.closest('tr');
                saveRow(row);
            } else if (e.target.closest('.sdm-site-icon')) {
                e.preventDefault();
                var iconSpan = e.target.closest('.sdm-site-icon');
                var siteId = iconSpan.getAttribute('data-site-id');
                if (currentProjectId > 0) {
                    openIconModal(siteId);
                }
            }
        });
    }

    function toggleEditRow(row, editMode) {
        var displayElems = row.querySelectorAll('.sdm-display-value');
        var editInputs = row.querySelectorAll('.sdm-edit-input');
        var editLink = row.querySelector('.sdm-edit-site');
        var saveLink = row.querySelector('.sdm-save-site');

        if (editMode) {
            displayElems.forEach(function(el) { el.classList.add('sdm-hidden'); });
            editInputs.forEach(function(el) { el.classList.remove('sdm-hidden'); });
            editLink.classList.add('sdm-hidden');
            saveLink.classList.remove('sdm-hidden');
            initializeMainDomainSelect(row);
        } else {
            displayElems.forEach(function(el) { el.classList.remove('sdm-hidden'); });
            editInputs.forEach(function(el) { el.classList.add('sdm-hidden'); });
            editLink.classList.remove('sdm-hidden');
            saveLink.classList.add('sdm-hidden');

            var mainDomainSelect = row.querySelector('select[name="main_domain"]');
            if (mainDomainSelect && window.jQuery) {
                jQuery(mainDomainSelect).select2('destroy').removeClass('select2-hidden-accessible select2-initialized');
            }
        }
    }

    function initializeMainDomainSelect(row) {
        if (currentProjectId > 0) {
            var mainDomainSelect = row ? row.querySelector('select[name="main_domain"]') : document.getElementById('main_domain');
            if (mainDomainSelect && !mainDomainSelect.classList.contains('select2-initialized')) {
                fetchNonBlockedDomainsForSelect(mainDomainSelect);
                if (window.jQuery) {
                    jQuery(mainDomainSelect).select2({
                        width: '100%',
                        placeholder: 'Select a domain',
                        allowClear: true
                    }).on('select2:select', function(e) {
                        mainDomainSelect.value = e.params.data.id;
                        console.log('Selected value:', mainDomainSelect.value);
                    }).on('select2:open', function() {
                        console.log('Select2 opened for', mainDomainSelect);
                    });
                }
                mainDomainSelect.classList.add('select2-initialized');
            }
        }
    }

    function saveRow(row) {
        var siteId = row.getAttribute('data-site-id');
        var nonce = row.getAttribute('data-update-nonce');

        var siteNameInput = row.querySelector('input[name="site_name"]');
        var mainDomainSelect = row.querySelector('select[name="main_domain"]');
        var serverIpInput = row.querySelector('input[name="server_ip"]');
        var languageInput = row.querySelector('input[name="language"]');
        var monitoringInputs = row.querySelectorAll('input[name^="monitoring"]');
        var saveLink = row.querySelector('.sdm-save-site');

        if (!siteNameInput || !mainDomainSelect || !serverIpInput || !languageInput || !saveLink) {
            console.error('One or more required elements not found:', { siteNameInput, mainDomainSelect, serverIpInput, languageInput, saveLink });
            return;
        }

        var mainDomain = window.jQuery ? jQuery(mainDomainSelect).val() : mainDomainSelect.value;
        if (!mainDomain) {
            showSitesNotice('error', 'Main Domain is required.');
            return;
        }

        var monitoring_settings = { enabled: false, types: {} };
        monitoringInputs.forEach(function(input) {
            var name = input.getAttribute('name');
            if (name === 'monitoring[enabled]') {
                monitoring_settings.enabled = input.checked;
            } else if (name && name.match(/monitoring\[types\]\[(.*?)\]/)) {
                var type = name.match(/monitoring\[types\]\[(.*?)\]/)[1];
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
    }

    function saveSite(row, siteId, nonce, siteName, mainDomain, serverIp, language, monitoring_settings, saveLink) {
        var formData = new FormData();
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
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Network response was not ok ' + response.statusText);
            }
            return response.json();
        })
        .then(function(data) {
            saveLink.innerHTML = 'Save';
            if (data.success) {
                var siteNameSpan = row.querySelector('.column-site-name .sdm-display-value');
                var mainDomainSpan = row.querySelector('.column-main-domain .sdm-display-value');
                var serverIpSpan = row.querySelector('.column-server-ip .sdm-display-value');
                var languageSpan = row.querySelector('.column-language .sdm-display-value');
                var monitoringSpan = row.querySelector('.column-monitoring .sdm-display-value');
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
        .catch(function(error) {
            console.error('Update site error:', error);
            saveLink.innerHTML = 'Save';
            showSitesNotice('error', 'Ajax request failed: ' + error.message);
        });
    }

    // Handle SVG Icon Editing
    function openIconModal(siteId) {
        var modal = document.getElementById('sdm-edit-icon-modal');
        var siteIdField = document.getElementById('sdm-icon-site-id');
        var svgInput = document.getElementById('svg_icon');
        var currentIcon = document.querySelector('#site-row-' + siteId + ' .sdm-site-icon').innerHTML.trim();

        if (modal && siteIdField && svgInput && currentProjectId > 0) {
            siteIdField.value = siteId;
            svgInput.value = currentIcon;
            modal.style.display = 'block';
        }
    }

    var editIconForm = document.getElementById('sdm-edit-icon-form');
    if (editIconForm && currentProjectId > 0) {
        editIconForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            var svgIcon = document.getElementById('svg_icon').value;

            svgIcon = svgIcon.replace(/width="[^"]*"/i, '').replace(/height="[^"]*"/i, '');
            formData.set('svg_icon', svgIcon);

            formData.append('action', 'sdm_update_site_icon');
            formData.append('sdm_main_nonce_field', mainNonce);

            var submitButton = this.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML += '<span class="spinner is-active" style="float:none;margin:0 5px;"></span>';
            }

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Save Icon';
                }
                if (data.success) {
                    var siteId = document.getElementById('sdm-icon-site-id').value;
                    var iconSpan = document.querySelector('#site-row-' + siteId + ' .sdm-site-icon');
                    iconSpan.innerHTML = data.data.svg_icon;
                    document.getElementById('sdm-edit-icon-modal').style.display = 'none';
                    showSitesNotice('updated', 'Icon updated successfully.');
                } else {
                    showSitesNotice('error', data.data);
                }
            })
            .catch(function(error) {
                console.error('Update icon error:', error);
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Save Icon';
                }
                showSitesNotice('error', 'Ajax request failed.');
            });
        });
    }

    var closeIconModal = document.getElementById('sdm-close-icon-modal');
    var iconModalOverlay = document.querySelector('#sdm-edit-icon-modal .sdm-modal-overlay');
    if (closeIconModal && currentProjectId > 0) {
        closeIconModal.addEventListener('click', function() {
            document.getElementById('sdm-edit-icon-modal').style.display = 'none';
        });
    }
    if (iconModalOverlay && currentProjectId > 0) {
        iconModalOverlay.addEventListener('click', function() {
            document.getElementById('sdm-edit-icon-modal').style.display = 'none';
        });
    }

    function fetchNonBlockedDomainsForSelect(selectElement) {
        var projectId = document.getElementById('sdm-project-selector').value;
        var siteId = selectElement.closest('tr') ? selectElement.closest('tr').getAttribute('data-site-id') : 0;
        if (projectId > 0) {
            var formData = new FormData();
            formData.append('action', 'sdm_get_non_blocked_domains_for_site');
            formData.append('project_id', projectId);
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
                    var options = '<option value="">Select a domain</option>';
                    var currentDomain = selectElement.dataset.current || '';
                    data.data.forEach(function(domain) {
                        var selected = domain === currentDomain ? ' selected' : '';
                        options += '<option value="' + domain + '"' + selected + '>' + domain + '</option>';
                    });
                    selectElement.innerHTML = options;

                    if (!window.jQuery) return;
                    jQuery(selectElement).select2({
                        width: '100%',
                        placeholder: 'Select a domain',
                        allowClear: true
                    });
                }
            })
            .catch(function(error) {
                console.error('Fetch non-blocked domains error:', error);
            });
        }
    }

    if (currentProjectId > 0) {
        initializeMainDomainSelect();
    }

    // Handle Delete Site Action
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
            .then(function(response) { return response.json(); })
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

    // Helper: Show Sites Notice
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
