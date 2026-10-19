        </main>
    </div>
</div>

<div class="confirm-modal" id="deleteConfirmModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="deleteConfirmTitle">
    <div class="confirm-modal-card">
        <div class="confirm-modal-icon" id="deleteConfirmIcon"><i data-lucide="trash-2"></i></div>
        <h2 id="deleteConfirmTitle">Delete record?</h2>
        <p id="deleteConfirmMessage">This action cannot be undone.</p>
        <div class="confirm-modal-actions">
            <button class="btn btn-secondary" type="button" id="deleteConfirmCancel">Cancel</button>
            <button class="btn btn-danger" type="button" id="deleteConfirmSubmit"><i data-lucide="trash-2"></i>Delete</button>
        </div>
    </div>
</div>

<div class="confirm-modal" id="helpModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="helpModalTitle">
    <div class="confirm-modal-card">
        <div class="confirm-modal-icon is-help"><i data-lucide="circle-help"></i></div>
        <h2 id="helpModalTitle">Need help?</h2>
        <p id="helpModalMessage">Review requests assigned to your office and update each requirement with a decision and remarks.</p>
        <div class="confirm-modal-actions">
            <button class="btn btn-primary" type="button" id="helpModalOk">OK</button>
        </div>
    </div>
</div>

<script src="../assets/js/app.js?v=20261028"></script>
<?php require_once __DIR__ . "/../config/mobile_nav.php"; render_mobile_nav("office", $activePage ?? "", $conn ?? null); ?>
<script>lucide.createIcons();</script>
</body>
</html>
