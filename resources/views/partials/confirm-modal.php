<?php /* Shared confirmation dialog used by every form with data-confirm (see app.js). */ ?>
<div class="modal fade" id="confirm-modal" tabindex="-1" aria-labelledby="confirm-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body d-flex gap-3 pb-4">
                <span class="confirm-icon is-primary" id="confirm-icon" aria-hidden="true"><i data-lucide="info"></i></span>
                <div class="flex-grow-1 min-w-0 pe-4">
                    <h2 class="modal-title mb-1" id="confirm-title">Please confirm</h2>
                    <p class="mb-0 text-secondary" id="confirm-message">Are you sure?</p>
                </div>
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirm-ok">Confirm</button>
            </div>
        </div>
    </div>
</div>
