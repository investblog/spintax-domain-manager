document.addEventListener('DOMContentLoaded', () => {
    const sdmPluginUrl = (typeof SDM_Data !== 'undefined' && SDM_Data.pluginUrl) ? SDM_Data.pluginUrl : '';

    // Если страница не соответствует нужному шаблону, прекращаем выполнение.
    if (!document.body.className.match(/spintax-manager_page_sdm-redirects/)) {
        return;
    }

    const mainNonceField = document.getElementById('sdm-main-nonce');
    const localizedNonce = (typeof SDM_Data !== 'undefined' && SDM_Data.nonce) ? SDM_Data.nonce : '';
    const mainNonce = localizedNonce || (mainNonceField ? mainNonceField.value : '');
    const projectSelector = document.getElementById('sdm-project-selector');
    let currentProjectId = projectSelector ? parseInt(projectSelector.value) : 0;
    let isRedirectsLoaded = false;
    let sortDirection = {};
    let lastSortedColumn = null;

    // Функция для инициализации состояния сортировки
    const initializeSortState = () => {
        sortDirection['domain'] = 'asc';
        sortDirection['redirect_status'] = 'asc';
    };

    function getSpinnerElement() {
        const spinner = document.createElement('div');
        spinner.className = 'sdm-mini-icon';
        spinner.innerHTML = `<svg width="17" height="17" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 9 13"><path fill="#F7B500" d="M8.6277 5.78308H5.19913v-5.25l-5.14286 6.75h3.42858v5.25002L8.6277 5.78308Z"/></svg>`;
        return spinner;
    }

    function createInlineSpinner() {
        const spinner = document.createElement('span');
        spinner.className = 'spinner is-active sdm-inline-spinner';
        spinner.style.float = 'none';
        return spinner;
    }



    // Универсальная функция для AJAX-запросов
    const ajaxRequest = (formData) => {
        return fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(response => response.json());
    };

    const normalizeMessage = (message) => {
        if (!message) {
            return '';
        }
        if (typeof message === 'string') {
            return message;
        }
        if (message.message) {
            return message.message;
        }
        if (message.data && message.data.message) {
            return message.data.message;
        }
        try {
            return JSON.stringify(message);
        } catch (err) {
            return String(message);
        }
    };

    const getDomainLabel = (domainId) => {
        const selector = `tr[data-domain-id="${domainId}"]`;
        const row = document.querySelector(selector);
        if (row) {
            const datasetDomain = row.getAttribute('data-domain');
            if (datasetDomain) {
                return datasetDomain;
            }
            const domainCell = row.querySelector('.sdm-domain');
            if (domainCell && domainCell.textContent) {
                return domainCell.textContent.trim();
            }
        }
        return `Domain #${domainId}`;
    };

    function batchSyncRedirects(domainIds, mainNonce, options = {}) {
        const sanitizedIds = Array.isArray(domainIds)
            ? domainIds.map(id => parseInt(id, 10)).filter(id => !Number.isNaN(id))
            : [];

        if (!sanitizedIds.length) {
            if (typeof options.onComplete === 'function') {
                options.onComplete();
            }
            return;
        }

        const progressBox = document.getElementById('sdm-batch-progress');
        const progressBar = progressBox ? progressBox.querySelector('.sdm-progress-bar') : null;
        const queueBox = document.getElementById('sdm-redirects-queue');
        const queueList = queueBox ? queueBox.querySelector('.sdm-sync-queue-list') : null;
        const queueSummary = queueBox ? queueBox.querySelector('.sdm-sync-queue-summary') : null;
        const hasProgress = !!progressBar;
        const statusText = {
            pending: 'Pending',
            processing: 'Syncing…',
            success: 'Completed',
            error: 'Failed'
        };

        const tasks = sanitizedIds.map(id => ({
            id,
            label: getDomainLabel(id)
        }));
        const total = tasks.length;
        const queue = tasks.slice();

        if (queueList) {
            queueList.innerHTML = '';
            tasks.forEach(task => {
                const item = document.createElement('li');
                item.className = 'sdm-sync-queue-item is-pending';
                item.dataset.domainId = String(task.id);

                const labelEl = document.createElement('span');
                labelEl.className = 'sdm-sync-queue-label';
                labelEl.textContent = task.label;

                const statusEl = document.createElement('span');
                statusEl.className = 'sdm-sync-queue-status';
                statusEl.textContent = statusText.pending;

                item.appendChild(labelEl);
                item.appendChild(statusEl);
                queueList.appendChild(item);

                task.element = item;
            });
        }

        if (queueBox && (queueList || queueSummary)) {
            queueBox.style.display = 'block';
        }
        if (queueSummary) {
            queueSummary.textContent = `Processed 0/${total}`;
        }
        if (hasProgress) {
            progressBar.style.width = '0%';
            progressBox.style.display = 'block';
        }

        let processed = 0;
        let successCount = 0;
        const failed = [];

        const truncateMessage = (message) => {
            if (!message) {
                return '';
            }
            const str = String(message);
            return str.length > 160 ? str.slice(0, 157) + '…' : str;
        };

        const updateProgress = () => {
            if (!hasProgress) {
                return;
            }
            const percent = total ? Math.round((processed / total) * 100) : 100;
            progressBar.style.width = percent + '%';
        };

        const updateSummary = (isFinal = false) => {
            if (!queueSummary) {
                return;
            }
            const failText = failed.length ? `, ${failed.length} failed` : '';
            const prefix = isFinal ? 'Completed' : 'Processed';
            queueSummary.textContent = `${prefix} ${processed}/${total}${failText}`;
        };

        const updateQueueItemStatus = (task, state, message) => {
            if (!task || !task.element) {
                return;
            }
            task.element.classList.remove('is-pending', 'is-processing', 'is-success', 'is-error');
            task.element.classList.add(`is-${state}`);
            const statusEl = task.element.querySelector('.sdm-sync-queue-status');
            if (statusEl) {
                const fallback = statusText[state] || '';
                statusEl.textContent = truncateMessage(message) || fallback;
            }
        };

        const syncSingle = (domainId) => {
            const fd = new FormData();
            fd.append('action', 'sdm_mass_sync_redirects_to_cloudflare');
            fd.append('domain_ids', JSON.stringify([domainId]));
            fd.append('project_id', currentProjectId);
            fd.append('sdm_main_nonce_field', mainNonce);

            return fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            })
                .then(r => r.json())
                .then(resp => {
                    const msg = normalizeMessage(resp.data) || resp.message || 'Unknown server response';
                    if (resp.success) {
                        return { ok: true, message: msg };
                    }
                    return { ok: false, message: msg };
                })
                .catch(err => {
                    console.error('Request failed for domain', domainId, err);
                    return { ok: false, message: err && err.message ? err.message : 'Request failed.' };
                });
        };

        const finalize = () => {
            updateSummary(true);
            if (hasProgress) {
                setTimeout(() => { progressBox.style.display = 'none'; }, 600);
            }
            if (queueBox) {
                setTimeout(() => { queueBox.style.display = 'none'; }, 6000);
            }

            fetchRedirects(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc');

            let msg = `${successCount} of ${total} redirects synced.`;
            if (failed.length) {
                msg += ' ' + failed.length + ' failed:\n' + failed.join('\n');
                showRedirectsNotice('error', msg);
            } else {
                showRedirectsNotice('updated', msg);
            }

            if (typeof options.onComplete === 'function') {
                options.onComplete();
            }
        };

        const processNext = () => {
            if (!queue.length) {
                finalize();
                return;
            }
            const currentTask = queue.shift();
            updateQueueItemStatus(currentTask, 'processing', statusText.processing);

            syncSingle(currentTask.id).then(result => {
                processed += 1;
                if (result.ok) {
                    successCount += 1;
                    updateQueueItemStatus(currentTask, 'success', result.message || statusText.success);
                } else {
                    const failureMessage = result.message || statusText.error;
                    failed.push(`${currentTask.label}: ${failureMessage}`);
                    updateQueueItemStatus(currentTask, 'error', failureMessage);
                }
                updateProgress();
                updateSummary();
                processNext();
            });
        };

        processNext();
    }

    const fetchRedirects = (projectId, sortColumn = 'domain', sortDirectionParam = 'asc') => {
        if (isRedirectsLoaded) {
            console.log('Redirects already loading, skipping...');
            return;
        }
        isRedirectsLoaded = true;

        const formData = new FormData();
        formData.append('action', 'sdm_fetch_redirects_list');
        formData.append('project_id', projectId);
        formData.append('sort_column', sortColumn);
        formData.append('sort_direction', sortDirectionParam);
        formData.append('sdm_main_nonce_field', mainNonce);

        const container = document.getElementById('sdm-redirects-container');
        container.innerHTML = `<p>${getSpinnerElement().outerHTML} Loading...</p>`;

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                container.innerHTML = data.data.html;
                initializeDynamicListeners();
                initializeSorting();
                addTooltipsToArrows();
            } else {
                showRedirectsNotice('error', data.data);
            }
            isRedirectsLoaded = false;
        })
        .catch(error => {
            console.error('Fetch redirects list error:', error);
            showRedirectsNotice('error', 'Ajax request failed.');
            container.innerHTML = '<p class="error">Error loading redirects.</p>';
            isRedirectsLoaded = false;
        });
    };

    if (currentProjectId > 0) {
        initializeSortState();
        fetchRedirects(currentProjectId);
    }

    if (projectSelector) {
        projectSelector.addEventListener('change', function() {
            isRedirectsLoaded = false;
            currentProjectId = parseInt(this.value);
            if (currentProjectId > 0) {
                initializeSortState();
                fetchRedirects(currentProjectId);
            } else {
                document.getElementById('sdm-redirects-container').innerHTML =
                    '<p style="margin: 20px 0; color: #666;">Please select a project to view its redirects.</p>';
            }
        });
    }

    const syncButton = document.getElementById('sdm-sync-cloudflare');
    if (syncButton) {
        syncButton.addEventListener('click', (e) => {
            e.preventDefault();
            if (!confirm('Are you sure you want to sync all redirects with CloudFlare for this project?')) return;

            const allIds = Array.from(document.querySelectorAll('.sdm-redirect-checkbox'))
                .map(cb => parseInt(cb.value));
            if (allIds.length === 0) {
                alert('No redirects to sync.');
                return;
            }
            const originalHtml = syncButton.innerHTML;
            const spinner = createInlineSpinner();
            const label = document.createElement('span');
            label.textContent = 'Syncing...';

            syncButton.disabled = true;
            syncButton.innerHTML = '';
            syncButton.appendChild(spinner);
            syncButton.appendChild(label);

            batchSyncRedirects(allIds, mainNonce, {
                onComplete: () => {
                    syncButton.disabled = false;
                    syncButton.innerHTML = originalHtml;
                }
            });
        });
    }

    const initializeDynamicListeners = () => {
        document.querySelectorAll('.sdm-delete').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                if (!confirm('Are you sure you want to delete this redirect?')) return;

                const redirectId = button.getAttribute('data-redirect-id');
                const spinner = getSpinnerElement();
                spinner.style.float = 'none';
                spinner.style.margin = '0 5px';
                button.innerHTML = '';
                button.appendChild(spinner);

                const formData = new FormData();
                formData.append('action', 'sdm_delete_redirect');
                formData.append('redirect_id', redirectId);
                formData.append('sdm_main_nonce_field', mainNonce);

                ajaxRequest(formData)
                    .then(data => {
                        spinner.remove();
                        if (data.success) {
                            showRedirectsNotice('updated', data.data.message);
                            fetchRedirects(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc');
                        } else {
                            showRedirectsNotice('error', data.data);
                        }
                    })
                    .catch(error => {
                        console.error('Delete redirect error:', error);
                        showRedirectsNotice('error', 'Ajax request failed.');
                        spinner.remove();
                    });
            });
        });

        document.querySelectorAll('.sdm-create-redirect').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                if (!confirm('Are you sure you want to create a default redirect for this domain?')) return;

                const domainId = button.getAttribute('data-domain-id');
                const spinner = getSpinnerElement();
                spinner.style.float = 'none';
                spinner.style.margin = '0 5px';
                button.innerHTML = '';
                button.appendChild(spinner);

                const formData = new FormData();
                formData.append('action', 'sdm_create_default_redirect');
                formData.append('domain_id', domainId);
                formData.append('project_id', currentProjectId);
                formData.append('sdm_main_nonce_field', mainNonce);

                ajaxRequest(formData)
                    .then(data => {
                        spinner.remove();
                        if (data.success) {
                            showRedirectsNotice('updated', data.data.message);
                            fetchRedirects(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc');
                        } else {
                            showRedirectsNotice('error', data.data);
                        }
                    })
                    .catch(error => {
                        console.error('Create default redirect error:', error);
                        showRedirectsNotice('error', 'Ajax request failed.');
                        spinner.remove();
                    });
            });
        });

        document.querySelectorAll('.sdm-mass-action-select-site').forEach(select => {
            const siteId = select.getAttribute('data-site-id');
            const applyBtn = document.querySelector(`.sdm-mass-action-apply-site[data-site-id="${siteId}"]`);
            if (!applyBtn) return;

            applyBtn.addEventListener('click', (e) => {
                e.preventDefault();
                const action = select.value;
                if (!action) {
                    alert('Please select a mass action.');
                    return;
                }
                const selected = Array.from(document.querySelectorAll(`.sdm-redirect-checkbox[data-site-id="${siteId}"]:checked`))
                                      .map(cb => cb.value);
                if (selected.length === 0) {
                    alert('No redirects selected for this site.');
                    return;
                }

                if (action === 'create_default') {
                    if (!confirm('Are you sure you want to create default redirects for the selected domains in this site?')) return;
                    massActionAPI('sdm_mass_create_default_redirects', selected, applyBtn);
                } else if (action === 'mass_delete') {
                    if (!confirm('Are you sure you want to delete the selected redirects?')) return;
                    massActionAPI('sdm_mass_delete_redirects', selected, applyBtn);
                } else if (action === 'sync_cloudflare') {
                    if (!confirm('Are you sure you want to sync the selected redirects with CloudFlare for this site?')) return;
                    const ids = selected
                        .map(id => parseInt(id, 10))
                        .filter(id => !Number.isNaN(id));
                    if (!ids.length) {
                        showRedirectsNotice('error', 'No valid domains selected for sync.');
                        return;
                    }

                    const originalHtml = applyBtn.innerHTML;
                    const spinner = createInlineSpinner();
                    const label = document.createElement('span');
                    label.textContent = 'Syncing...';

                    applyBtn.disabled = true;
                    applyBtn.innerHTML = '';
                    applyBtn.appendChild(spinner);
                    applyBtn.appendChild(label);

                    batchSyncRedirects(ids, mainNonce, {
                        onComplete: () => {
                            applyBtn.disabled = false;
                            applyBtn.innerHTML = originalHtml;
                        }
                    });
                }
            });
        });

        const massActionAPI = (actionName, domainIds, buttonEl) => {
            const spinner = getSpinnerElement();
            spinner.style.float = 'none';
            spinner.style.margin = '0 5px';
            buttonEl.disabled = true;
            const originalText = buttonEl.innerHTML;
            buttonEl.innerHTML = '';
            buttonEl.appendChild(spinner);

            const formData = new FormData();
            formData.append('action', actionName);
            formData.append('domain_ids', JSON.stringify(domainIds));
            formData.append('project_id', currentProjectId);
            formData.append('sdm_main_nonce_field', mainNonce);

            ajaxRequest(formData)
                .then(data => {
                    buttonEl.disabled = false;
                    buttonEl.innerHTML = originalText;
                    if (data.success) {
                        showRedirectsNotice('updated', data.data.message);
                        fetchRedirects(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc');
                    } else {
                        showRedirectsNotice('error', data.data);
                    }
                    spinner.remove();
                })
                .catch(error => {
                    console.error('Mass action error:', error);
                    showRedirectsNotice('error', 'Ajax request failed.');
                    buttonEl.disabled = false;
                    buttonEl.innerHTML = originalText;
                    spinner.remove();
                });
        };


        document.querySelectorAll('.sdm-select-all-site-redirects').forEach(checkbox => {
            const siteId = checkbox.getAttribute('data-site-id');
            checkbox.addEventListener('change', function() {
                const checked = this.checked;
                document.querySelectorAll(`.sdm-redirect-checkbox[data-site-id="${siteId}"]`)
                    .forEach(cb => cb.checked = checked);
            });
        });

        document.body.addEventListener('click', (e) => {
            const typeDisplay = e.target.closest('.sdm-redirect-type-display');
            if (typeDisplay) {
                const cell = typeDisplay.closest('.sdm-redirect-type-cell');
                if (!cell) return;
                const selector = cell.querySelector('.sdm-redirect-type-selector');
                if (!selector) return;
                selector.style.display = (selector.style.display === 'none' || !selector.style.display)
                    ? 'inline-block'
                    : 'none';
            }

            const typeOption = e.target.closest('.sdm-type-option');
            if (typeOption) {
                const newType = typeOption.getAttribute('data-value');
                const cell = typeOption.closest('.sdm-redirect-type-cell');
                const redirectId = cell.getAttribute('data-redirect-id');
                const oldType = cell.getAttribute('data-current-type');

                if (!redirectId) return;
                if (newType === oldType) {
                    cell.querySelector('.sdm-redirect-type-selector').style.display = 'none';
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'sdm_update_redirect_type');
                formData.append('redirect_id', redirectId);
                formData.append('new_type', newType);
                formData.append('sdm_main_nonce_field', mainNonce);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        cell.setAttribute('data-current-type', newType);
                        const displaySpan = cell.querySelector('.sdm-redirect-type-display');
                        displaySpan.classList.remove('sdm-redirect-type-' + oldType);
                        displaySpan.classList.add('sdm-redirect-type-' + newType);
                        displaySpan.innerHTML = typeOption.innerHTML;

                        const targetSpan = cell.querySelector('.sdm-target-domain');
                        if (targetSpan && data.data.target_url) {
                            targetSpan.textContent = data.data.target_url;
                        }

                        cell.querySelector('.sdm-redirect-type-selector').style.display = 'none';
                        showRedirectsNotice('updated', data.data.message);
                    } else {
                        console.error(data.data || 'Error updating redirect type');
                        showRedirectsNotice('error', data.data);
                    }
                })
                .catch(error => {
                    console.error('AJAX error:', error);
                    showRedirectsNotice('error', 'Ajax request failed.');
                });
            }
        });

        document.querySelectorAll('.sdm-attach-domain-to-site').forEach(select => {
            select.addEventListener('change', function(e) {
                e.preventDefault();
                const domainId = this.getAttribute('data-domain-id');
                const siteId = this.value;
                if (siteId === '0') return;

                const formData = new FormData();
                formData.append('action', 'sdm_attach_domain_to_site');
                formData.append('domain_id', domainId);
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
                        showRedirectsNotice('updated', data.data.message);
                        fetchRedirects(currentProjectId, lastSortedColumn, sortDirection[lastSortedColumn] || 'asc');
                    } else {
                        showRedirectsNotice('error', data.data);
                    }
                })
                .catch(error => {
                    console.error('Error attaching domain to site:', error);
                    showRedirectsNotice('error', 'Ajax request failed.');
                });
            });
        });
    };

    const initializeSorting = () => {
        document.querySelectorAll('.sdm-sortable').forEach(header => {
            header.addEventListener('click', (e) => {
                e.preventDefault();
                const columnName = header.dataset.column;
                const direction = sortDirection[columnName] === 'asc' ? 'desc' : 'asc';

                document.querySelectorAll('.sdm-sortable').forEach(col => {
                    if (col !== header) {
                        col.classList.remove('sdm-sorted-asc', 'sdm-sorted-desc');
                        sortDirection[col.dataset.column] = 'asc';
                    }
                });

                header.classList.remove('sdm-sorted-asc', 'sdm-sorted-desc');
                header.classList.add('sdm-sorted-' + direction);
                sortDirection[columnName] = direction;

                if (currentProjectId > 0) {
                    fetchRedirects(currentProjectId, columnName, direction);
                }
                lastSortedColumn = columnName;
            });
        });

        const urlParams = new URLSearchParams(window.location.search);
        const sortColumn = urlParams.get('sort_column') || '';
        const sortDirectionParam = urlParams.get('sort_direction') || 'asc';
        if (sortColumn && ['domain', 'redirect_status'].includes(sortColumn)) {
            const header = document.querySelector(`.sdm-sortable[data-column="${sortColumn}"]`);
            if (header) {
                header.classList.remove('sdm-sorted-asc', 'sdm-sorted-desc');
                header.classList.add('sdm-sorted-' + sortDirectionParam);
                sortDirection[sortColumn] = sortDirectionParam;
                fetchRedirects(currentProjectId, sortColumn, sortDirectionParam);
            }
        }
    };

    const addTooltipsToArrows = () => {
        document.querySelectorAll('.sdm-redirect-arrow').forEach(arrow => {
            const type = arrow.getAttribute('data-redirect-type');
            let tooltipText = '';
            switch (type) {
                case 'main':
                    tooltipText = 'Main redirect';
                    break;
                case 'glue':
                    tooltipText = 'Glue redirect (underlink)';
                    break;
                case 'hidden':
                    tooltipText = 'Hidden redirect';
                    break;
                default:
                    tooltipText = 'No redirect';
            }
            arrow.setAttribute('title', tooltipText);
        });
    };

    const showRedirectsNotice = (type, message) => {
        const noticeContainer = document.getElementById('sdm-redirects-notice');
        if (!noticeContainer) return;
        const cssClass = (type === 'error') ? 'notice-error' : 'notice-success';
        const resolvedMessage = normalizeMessage(message) || (type === 'error' ? 'An unknown error occurred.' : '');
        noticeContainer.innerHTML =
            `<div class="notice ${cssClass} is-dismissible"><p>${resolvedMessage}</p><button class="notice-dismiss" type="button">×</button></div>`;
        const dismissBtn = noticeContainer.querySelector('.notice-dismiss');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', () => {
                noticeContainer.innerHTML = '';
            });
        }
    };

});
