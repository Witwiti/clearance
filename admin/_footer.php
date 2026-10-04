        </main>
    </div>
</div>

<div class="confirm-modal" id="deleteConfirmModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="deleteConfirmTitle">
    <div class="confirm-modal-card">
        <div class="confirm-modal-icon"><i data-lucide="trash-2"></i></div>
        <h2 id="deleteConfirmTitle">Delete record?</h2>
        <p id="deleteConfirmMessage">This action cannot be undone.</p>
        <div class="confirm-modal-actions">
            <button class="btn btn-secondary" type="button" id="deleteConfirmCancel">Cancel</button>
            <button class="btn btn-danger" type="button" id="deleteConfirmSubmit"><i data-lucide="trash-2"></i>Delete</button>
        </div>
    </div>
</div>

<script src="../assets/js/app.js"></script>
<script>
    lucide.createIcons();
</script>
</body>
</html>
