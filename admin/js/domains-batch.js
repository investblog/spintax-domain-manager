// Handles batch operations for domains page

document.addEventListener('DOMContentLoaded', function () {
    var massActionSelect = document.getElementById('sdm-mass-action-select');
    var massActionApply  = document.getElementById('sdm-mass-action-apply');
    var mainNonceField   = document.getElementById('sdm-main-nonce');
    var mainNonce        = mainNonceField ? mainNonceField.value : '';
    var progressBox      = document.getElementById('sdm-batch-progress');
    var progressBar      = progressBox ? progressBox.querySelector('.sdm-progress-bar') : null;

    if (!massActionSelect || !massActionApply) return;

    massActionApply.addEventListener('click', function(e){
        if (massActionSelect.value !== 'sync_ns') return;
        e.preventDefault();

        var selected = [];
        document.querySelectorAll('.sdm-domain-checkbox:checked').forEach(function(cb){
            var row = cb.closest('tr');
            var isMain = row.querySelector('.sdm-main-domain-icon') !== null;
            if (!isMain) selected.push(cb.value);
        });

        if (selected.length === 0) {
            alert('No domains selected (main domains are excluded).');
            return;
        }

        if (!confirm('Sync Cloudflare nameservers to Namecheap for selected domains?')) {
            return;
        }

        batchSyncNS(selected.map(function(id){ return parseInt(id); }));
    });

    function batchSyncNS(domainIds){
        if (!progressBox || !progressBar) return;
        var total = domainIds.length;
        var processed = 0;
        var batchSize = 10;
        var successCount = 0;
        var failed = [];

        progressBar.style.width = '0%';
        progressBox.style.display = 'block';

        function doBatch(){
            if (domainIds.length === 0) {
                setTimeout(function(){ progressBox.style.display = 'none'; }, 500);
                if (window.SDM_Domains_API) {
                    SDM_Domains_API.fetchDomains(
                        SDM_Domains_API.getCurrentProjectId(),
                        SDM_Domains_API.getSortColumn(),
                        SDM_Domains_API.getSortDirection()
                    );
                }
                var msg = successCount + ' domains synced.';
                if (failed.length) {
                    msg += ' ' + failed.length + ' failed:\n' + failed.join('\n');
                    if (window.SDM_Domains_API) {
                        SDM_Domains_API.showNotice('error', msg);
                    }
                } else if (window.SDM_Domains_API) {
                    SDM_Domains_API.showNotice('updated', msg);
                }
                return;
            }
            var batch = domainIds.splice(0, batchSize);
            Promise.all(batch.map(syncSingle)).then(function(){
                processed += batch.length;
                var percent = Math.round(processed / total * 100);
                progressBar.style.width = percent + '%';
                doBatch();
            });
        }

        function syncSingle(domainId){
            var fd = new FormData();
            fd.append('action', 'sdm_sync_cf_ns_namecheap');
            fd.append('domain_id', domainId);
            fd.append('sdm_main_nonce_field', mainNonce);
            return fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            }).then(function(r){ return r.json(); })
              .then(function(resp){
                  var msg = resp.data || resp.message || 'Unknown server response';
                  if (resp.success) {
                      successCount++;
                  } else {
                      failed.push(domainId + ': ' + msg);
                  }
              })
              .catch(function(err){
                  failed.push(domainId + ': request failed');
                  console.error('Request failed for domain', domainId, err);
              });
        }

        doBatch();
    }
});
