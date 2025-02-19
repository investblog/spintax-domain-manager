document.addEventListener('DOMContentLoaded', function() {

    // ----------------------------
    // Existing functionality: Copy Encryption Key
    // ----------------------------
    var copyButton = document.getElementById('sdm_copy_key_button');
    var keyField = document.getElementById('sdm_encryption_key_field');
    
    if ( copyButton && keyField ) {
        copyButton.addEventListener('click', function() {
            keyField.select();
            keyField.setSelectionRange(0, 99999);
            try {
                var successful = document.execCommand('copy');
                if (successful) {
                    alert('Encryption key copied to clipboard.');
                } else {
                    alert('Failed to copy the key.');
                }
            } catch (err) {
                alert('Error copying the key.');
            }
        });
    }
    
    // ----------------------------
    // Ajax handling for "Add New Project" form
    // ----------------------------
    var addProjectForm = document.getElementById('sdm-add-project-form');
    var projectsNotice = document.getElementById('sdm-projects-notice'); // Unified notice container

    if ( addProjectForm && projectsNotice ) {
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
                if ( data.success ) {
                    showInlineNotice('updated', 'Project added successfully (ID: ' + data.data.project_id + ')');
                    addProjectForm.reset();
                    location.reload(); // Reload so new project appears in the table
                } else {
                    showInlineNotice('error', data.data);
                }
            })
            .catch(function(error) {
                submitButton.disabled = false;
                console.error('Error:', error);
                showInlineNotice('error', 'Ajax request failed.');
            });
        });
    }
    
    // ----------------------------
    // Inline Editing and Deletion for Projects
    // ----------------------------
    var projectsTable = document.getElementById('sdm-projects-table');
    
    if ( projectsTable && projectsNotice ) {
        projectsTable.addEventListener('click', function(e) {
            if ( e.target.classList.contains('sdm-edit-project') ) {
                e.preventDefault();
                var row = e.target.closest('tr');
                toggleEditRow(row, true);
            }
            else if ( e.target.classList.contains('sdm-save-project') ) {
                e.preventDefault();
                var row = e.target.closest('tr');
                saveRow(row);
            }
            else if ( e.target.classList.contains('sdm-delete-project') ) {
                e.preventDefault();
                if ( ! confirm('Are you sure you want to delete this project?') ) {
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
                    if ( data.success ) {
                        showInlineNotice('updated', data.data.message);
                        row.parentNode.removeChild(row);
                    } else {
                        showInlineNotice('error', data.data);
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    showInlineNotice('error', 'Ajax request failed.');
                });
            }
        });
    }
    
    // ----------------------------
    // Helper Functions
    // ----------------------------

    /**
     * Toggle row between display and edit mode
     */
    function toggleEditRow(row, editMode) {
        var displayElems = row.querySelectorAll('.sdm-display-value');
        var editInputs = row.querySelectorAll('.sdm-edit-input');
        var editLink = row.querySelector('.sdm-edit-project');
        var saveLink = row.querySelector('.sdm-save-project');
        
        if ( editMode ) {
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
     * Save changes for a row (inline editing)
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
            if ( data.success ) {
                showInlineNotice('updated', data.data.message);
                var nameSpan = row.querySelector('.column-name .sdm-display-value');
                var descSpan = row.querySelector('.column-description .sdm-display-value');
                var sslSpan = row.querySelector('.column-ssl_mode .sdm-display-value');
                var monitoringSpan = row.querySelector('.column-monitoring .sdm-display-value');
                
                if ( nameSpan ) nameSpan.textContent = projectName;
                if ( descSpan ) descSpan.textContent = description;
                if ( sslSpan ) sslSpan.textContent = sslMode;
                if ( monitoringSpan ) monitoringSpan.textContent = monitoringEnabled ? 'Yes' : 'No';
                
                toggleEditRow(row, false);
            } else {
                showInlineNotice('error', data.data);
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showInlineNotice('error', 'Ajax request failed.');
        });
    }

    /**
     * Show a notice in #sdm-projects-notice
     * @param {string} type 'updated' or 'error'
     * @param {string} message
     */
    function showInlineNotice(type, message) {
        if (!projectsNotice) return;
        var cssClass = (type === 'error') ? 'notice-error' : 'notice-success';
        projectsNotice.innerHTML = '<div class="notice ' + cssClass + ' is-dismissible"><p>' + message + '</p></div>';
        
        setTimeout(function() {
            if (projectsNotice.firstChild) {
                projectsNotice.removeChild(projectsNotice.firstChild);
            }
        }, 5000);
    }
});
