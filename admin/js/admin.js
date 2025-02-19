document.addEventListener('DOMContentLoaded', function() {
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
});
