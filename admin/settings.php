<?php
$pageTitle = "Settings";
$activePage = "settings";
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

$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $allowRequests = isset($_POST["allow_clearance_requests"]) ? "1" : "0";
    $allowRemarks = isset($_POST["allow_office_remarks"]) ? "1" : "0";
    $maintenanceMode = isset($_POST["maintenance_mode"]) ? "1" : "0";
    $stmt = $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    foreach ([
        "allow_clearance_requests" => $allowRequests,
        "allow_office_remarks" => $allowRemarks,
        "maintenance_mode" => $maintenanceMode
    ] as $key => $value) {
        $stmt->bind_param("ss", $key, $value);
        $stmt->execute();
    }
    $stmt->close();
    $message = "Settings saved.";
}

$settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($result) {
    while ($row = $result->fetch_assoc()) $settings[$row["setting_key"]] = $row["setting_value"];
}
$allowRequests = ($settings["allow_clearance_requests"] ?? "1") === "1";
$allowRemarks = ($settings["allow_office_remarks"] ?? "1") === "1";
$maintenanceMode = ($settings["maintenance_mode"] ?? "0") === "1";
require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Settings</h1>
        <p>Configure the student clearance system.</p>
    </div>
</div>

<div class="settings-grid">
    <div class="form-card">
        <h2>Clearance Settings</h2>
        <p class="muted">Changes are saved for the whole system.</p>

        <?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
        <form method="post">

        <label class="setting-row">
            <span>
                <strong>Allow student clearance requests</strong>
                <small>Students can start a new clearance request.</small>
            </span>
            <input type="checkbox" name="allow_clearance_requests" <?= $allowRequests ? "checked" : "" ?> >
        </label>

        <label class="setting-row">
            <span>
                <strong>Allow office remarks</strong>
                <small>Office assignatories can add remarks when reviewing.</small>
            </span>
            <input type="checkbox" name="allow_office_remarks" <?= $allowRemarks ? "checked" : "" ?> >
        </label>

        <label class="setting-row setting-row-warning">
            <span>
                <strong>Enable maintenance mode</strong>
                <small>Visitors and non-admin users will see a maintenance message.</small>
            </span>
            <input type="checkbox" name="maintenance_mode" <?= $maintenanceMode ? "checked" : "" ?> >
        </label>
        <button class="btn btn-primary" type="submit"><i data-lucide="save"></i>Save settings</button>
        </form>
    </div>
</div>
<?php require "_footer.php"; ?>
