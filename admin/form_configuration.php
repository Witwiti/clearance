<?php
$pageTitle = "Clearance Form Configuration";
$activePage = "form_configuration";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$conn->query(
    "CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )"
);
$conn->query("ALTER TABLE system_settings MODIFY setting_value TEXT NOT NULL");

$defaults = [
    "clearance_school_name" => "CHRIST THE KING COLLEGE DE MARANDING, INC.",
    "clearance_school_address" => "Maranding, Lala, Lanao del Norte, Philippines 9211",
    "clearance_school_contact" => "Tel. (063) 338-7039",
    "clearance_department" => "COLLEGE DEPARTMENT",
    "clearance_form_title" => "STUDENT CLEARANCE FORM",
    "clearance_term" => "2nd Semester S.Y. 2025 - 2026"
];

$message = "";
$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "save_configuration";
    if ($action === "toggle_office") {
        $officeId = (int)($_POST["office_id"] ?? 0);
        $officeStatus = ($_POST["office_status"] ?? "inactive") === "active" ? "active" : "inactive";
        if ($officeId > 0) {
            $stmt = $conn->prepare("UPDATE offices SET status = ? WHERE office_id = ?");
            $stmt->bind_param("si", $officeStatus, $officeId);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message = $officeStatus === "active" ? "Office added to the student form." : "Office removed from the student form.";
            } else {
                $error = "Unable to update the office visibility.";
            }
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );

        foreach ($defaults as $key => $default) {
            $value = trim((string)($_POST[$key] ?? ""));
            if ($value === "") {
                $value = $default;
            }
            $stmt->bind_param("ss", $key, $value);
            if (!$stmt->execute()) $error = "Unable to save the form configuration.";
        }
        $stmt->close();
        if ($error === "") {
        }
        if ($error === "") $message = "Clearance form configuration saved.";
    }
}

$settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($result) {
    while ($row = $result->fetch_assoc()) $settings[$row["setting_key"]] = $row["setting_value"];
}
foreach ($defaults as $key => $default) {
    if (!isset($settings[$key]) || $settings[$key] === "") $settings[$key] = $default;
}
$offices = $conn->query("SELECT office_id, office_name, description, status FROM offices ORDER BY status DESC, office_name ASC");

require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Clearance Form Configuration</h1>
        <p>Manage the information displayed on every student's clearance form.</p>
    </div>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<div class="configuration-layout">
    <div class="form-card configuration-card">
        <div class="configuration-card-header">
            <div class="configuration-icon"><i data-lucide="file-cog"></i></div>
            <div>
                <h2>Form details</h2>
                <p>Update the information printed on every student clearance form.</p>
            </div>
        </div>

        <form method="post" class="configuration-form">
            <section class="configuration-section">
                <div class="configuration-section-heading">
                    <span class="configuration-step">01</span>
                    <div><h3>School identity</h3><p>Shown at the top of the document.</p></div>
                </div>
                <div class="configuration-fields">
                    <label class="configuration-field configuration-field-wide">School name<input name="clearance_school_name" required value="<?= e($settings["clearance_school_name"]) ?>"></label>
                    <label class="configuration-field configuration-field-wide">Address<input name="clearance_school_address" required value="<?= e($settings["clearance_school_address"]) ?>"></label>
                    <label class="configuration-field">Contact number<input name="clearance_school_contact" required value="<?= e($settings["clearance_school_contact"]) ?>"></label>
                    <label class="configuration-field">Department<input name="clearance_department" required value="<?= e($settings["clearance_department"]) ?>"></label>
                </div>
            </section>

            <section class="configuration-section">
                <div class="configuration-section-heading">
                    <span class="configuration-step">02</span>
                    <div><h3>Document heading</h3><p>Set the title and academic term.</p></div>
                </div>
                <div class="configuration-fields">
                    <label class="configuration-field configuration-field-wide">Form title<input name="clearance_form_title" required value="<?= e($settings["clearance_form_title"]) ?>"></label>
                    <label class="configuration-field configuration-field-wide">Term<input name="clearance_term" required value="<?= e($settings["clearance_term"]) ?>"></label>
                </div>
            </section>

            <div class="configuration-actions">
                <span><i data-lucide="info"></i> Changes apply to new and existing form views.</span>
                <button class="btn btn-primary" type="submit"><i data-lucide="save"></i>Save configuration</button>
            </div>
        </form>
    </div>

    <aside class="configuration-preview">
        <div class="configuration-preview-label"><i data-lucide="eye"></i> Form preview</div>
        <div class="configuration-preview-paper">
            <div class="configuration-preview-mark">CKCM</div>
            <strong><?= e($settings["clearance_school_name"]) ?></strong>
            <small><?= e($settings["clearance_school_address"]) ?></small>
            <small><?= e($settings["clearance_school_contact"]) ?></small>
            <hr>
            <small><?= e($settings["clearance_department"]) ?></small>
            <h3><?= e($settings["clearance_form_title"]) ?></h3>
            <span><?= e($settings["clearance_term"]) ?></span>
            <div class="configuration-preview-lines"><i></i><i></i><i></i></div>
        </div>
        <p class="configuration-preview-note">The full student clearance form also includes the active offices and clearance statuses.</p>
    </aside>
</div>

<section class="form-card configuration-office-card">
    <div class="configuration-card-header">
        <div class="configuration-icon"><i data-lucide="building-2"></i></div>
        <div>
            <h2>Offices on the form</h2>
            <p>Choose which offices appear in the student's clearance table.</p>
        </div>
    </div>
    <div class="configuration-office-note"><i data-lucide="info"></i> Removing an office also sets it inactive across the portal (reviews and assignments). It does not delete office history.</div>
    <div class="table-wrapper">
        <table class="configuration-office-table">
            <thead><tr><th>Office</th><th>Description</th><th>Form visibility</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($offices && $offices->num_rows): ?>
                <?php while ($office = $offices->fetch_assoc()): ?>
                    <?php $isActiveOffice = $office["status"] === "active"; ?>
                    <tr>
                        <td><strong><?= e($office["office_name"]) ?></strong></td>
                        <td><?= e($office["description"] ?: "No description") ?></td>
                        <td><span class="status <?= $isActiveOffice ? "status-active" : "status-inactive" ?>"><?= $isActiveOffice ? "Shown on form" : "Hidden from form" ?></span></td>
                        <td>
                            <form method="post" <?= $isActiveOffice ? 'data-delete-confirm="Remove this office from the student clearance form? Existing office history will be kept."' : "" ?>>
                                <input type="hidden" name="action" value="toggle_office">
                                <input type="hidden" name="office_id" value="<?= (int)$office["office_id"] ?>">
                                <input type="hidden" name="office_status" value="<?= $isActiveOffice ? "inactive" : "active" ?>">
                                <button class="table-action <?= $isActiveOffice ? "table-action-danger" : "" ?>" type="submit">
                                    <i data-lucide="<?= $isActiveOffice ? "eye-off" : "eye" ?>"></i><?= $isActiveOffice ? "Remove from form" : "Add to form" ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="4" class="empty-state">No offices have been created yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require "_footer.php"; ?>
