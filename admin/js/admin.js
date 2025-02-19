document.addEventListener('DOMContentLoaded', function() {

    // Existing functionality (e.g., copy encryption key) can remain here.

    var copyButton = document.getElementById('sdm_copy_key_button');
    var keyField = document.getElementById('sdm_encryption_key_field');
    
    if ( copyButton && keyField ) {
        copyButton.addEventListener('click', function() {
            keyField.select();
            keyField.setSelectionRange(0, 99999); // For mobile devices

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

    // --- Ajax handling for "Add New Project" form ---

    var addProjectForm = document.getElementById('sdm-add-project-form');
    if ( addProjectForm ) {
        addProjectForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(addProjectForm);
            formData.append('action', 'sdm_add_project'); // Specify Ajax action
            
            // Disable the submit button
            var submitButton = addProjectForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            
            // Send Ajax request to admin-ajax.php (ajaxurl is globally defined in WP admin)
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
                var messageDiv = document.getElementById('sdm-add-project-message');
                if ( data.success ) {
                    messageDiv.innerHTML = '<div class="updated"><p>' + 'Project added successfully (ID: ' + data.data.project_id + ')</p></div>';
                    // Optionally, clear the form fields
                    addProjectForm.reset();
                    // Optionally, update the projects table via Ajax or reload the page
                    // For now, we'll reload the page:
                    location.reload();
                } else {
                    messageDiv.innerHTML = '<div class="error"><p>' + data.data + '</p></div>';
                }
            })
            .catch(function(error) {
                submitButton.disabled = false;
                console.error('Error:', error);
            });
        });
    }
});