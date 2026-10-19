<?php
$pageTitle = "Institutional Clubs";
$activePage = "institutional_clubs";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";
$editClub = null;

$checkUserConflict = function (string $username, string $email, ?int $ignoreUserId = null): ?string {
    global $conn;
    $query = "SELECT user_id, username, email FROM users WHERE (username = ? OR email = ?)";
    $params = [$username, $email];
    $types = "ss";
    if ($ignoreUserId !== null) {
        $query .= " AND user_id <> ?";
        $params[] = $ignoreUserId;
        $types .= "i";
    }
    $stmt = $conn->prepare($query);
    if (!$stmt) return "Unable to validate the moderator account.";
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$existing) return null;
    if (strcasecmp((string)$existing["username"], $username) === 0) return "This moderator username is already in use.";
    return "This moderator email address is already in use.";
};

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $clubId = (int)($_POST["club_id"] ?? 0);

    if ($action === "save") {
        $clubName = trim($_POST["club_name"] ?? "");
        $status = ($_POST["status"] ?? "active") === "inactive" ? "inactive" : "active";
        $existingModeratorId = (int)($_POST["moderator_user_id"] ?? 0);

        $newFullName = trim($_POST["moderator_full_name"] ?? "");
        $newUsername = trim($_POST["moderator_username"] ?? "");
        $newEmail = trim($_POST["moderator_email"] ?? "");
        $newPassword = $_POST["moderator_password"] ?? "";
        $wantsNewModerator = ($newFullName !== "" || $newUsername !== "" || $newEmail !== "" || $newPassword !== "");

        if ($clubName === "") {
            $error = "Club name is required.";
        } else {
            // Unique club name (except self).
            $dupStmt = $conn->prepare("SELECT club_id FROM institutional_clubs WHERE club_name = ? AND club_id <> ? LIMIT 1");
            $dupStmt->bind_param("si", $clubName, $clubId);
            $dupStmt->execute();
            $dup = $dupStmt->get_result()->fetch_assoc();
            $dupStmt->close();
            if ($dup) {
                $error = "A club with this name already exists.";
            }
        }

        $moderatorUserId = null;
        if ($error === "") {
            if ($wantsNewModerator) {
                // Create a new office user inline.
                if ($newFullName === "" || $newUsername === "" || $newEmail === "" || $newPassword === "") {
                    $error = "To create a moderator, fill in full name, username, email, and password. Or pick an existing moderator instead.";
                } else {
                    $conflict = $checkUserConflict($newUsername, $newEmail, null);
                    if ($conflict !== null) {
                        $error = $conflict;
                    } else {
                        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role, status) VALUES (?, ?, ?, ?, 'office', 'active')");
                        if (!$stmt) {
                            $error = "Unable to create the moderator account.";
                        } else {
                            $stmt->bind_param("ssss", $newUsername, $hash, $newFullName, $newEmail);
                            try {
                                if ($stmt->execute()) $moderatorUserId = (int)$stmt->insert_id;
                                else $error = "Unable to create the moderator account. Username or email may already exist.";
                            } catch (mysqli_sql_exception $e) {
                                $error = "This moderator username or email is already in use.";
                            }
                            $stmt->close();
                        }
                    }
                }
            } elseif ($existingModeratorId > 0) {
                $uStmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'office' AND status = 'active' LIMIT 1");
                $uStmt->bind_param("i", $existingModeratorId);
                $uStmt->execute();
                $uRow = $uStmt->get_result()->fetch_assoc();
                $uStmt->close();
                if (!$uRow) $error = "Selected moderator was not found or is not an active office user.";
                else $moderatorUserId = $existingModeratorId;
            } else {
                // Editing without changing moderator: keep current.
                if ($clubId > 0) {
                    $cStmt = $conn->prepare("SELECT moderator_user_id FROM institutional_clubs WHERE club_id = ? LIMIT 1");
                    $cStmt->bind_param("i", $clubId);
                    $cStmt->execute();
                    $cRow = $cStmt->get_result()->fetch_assoc();
                    $cStmt->close();
                    $moderatorUserId = $cRow && $cRow["moderator_user_id"] !== null ? (int)$cRow["moderator_user_id"] : null;
                    if ($moderatorUserId === null || $moderatorUserId <= 0) $error = "Pick an existing moderator or fill in a new moderator.";
                } else {
                    $error = "Pick an existing moderator or fill in a new moderator.";
                }
            }
        }

        if ($error === "") {
            if ($clubId > 0) {
                $stmt = $conn->prepare("UPDATE institutional_clubs SET club_name = ?, moderator_user_id = ?, status = ? WHERE club_id = ?");
                $stmt->bind_param("sisi", $clubName, $moderatorUserId, $status, $clubId);
                if ($stmt->execute()) {
                    $_SESSION["flash_message"] = "Club updated.";
                    header("Location: institutional_clubs.php");
                    exit;
                } else $error = "Unable to save the club.";
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO institutional_clubs (club_name, moderator_user_id, status) VALUES (?, ?, ?)");
                $stmt->bind_param("sis", $clubName, $moderatorUserId, $status);
                if ($stmt->execute()) {
                    $_SESSION["flash_message"] = "Club added.";
                    header("Location: institutional_clubs.php");
                    exit;
                } else $error = "Unable to add the club.";
                $stmt->close();
            }
        }
        // Keep form open on error.
        $editClub = [
            "club_id" => $clubId,
            "club_name" => $clubName,
            "moderator_user_id" => $moderatorUserId ?? $existingModeratorId,
            "status" => $status,
            "moderator_full_name" => $newFullName,
            "moderator_username" => $newUsername,
            "moderator_email" => $newEmail,
        ];
    } elseif ($action === "toggle" && $clubId > 0) {
        $stmt = $conn->prepare("UPDATE institutional_clubs SET status = IF(status = 'active', 'inactive', 'active') WHERE club_id = ?");
        $stmt->bind_param("i", $clubId);
        $stmt->execute();
        $stmt->close();
        $message = "Club status updated.";
    } elseif ($action === "delete" && $clubId > 0) {
        $stmt = $conn->prepare("DELETE FROM institutional_clubs WHERE club_id = ?");
        $stmt->bind_param("i", $clubId);
        if ($stmt->execute() && $stmt->affected_rows > 0) $message = "Club deleted.";
        else $error = "Unable to delete the club.";
        $stmt->close();
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && isset($_GET["edit"])) {
    $clubId = (int)$_GET["edit"];
    $stmt = $conn->prepare("SELECT * FROM institutional_clubs WHERE club_id = ?");
    $stmt->bind_param("i", $clubId);
    $stmt->execute();
    $editClub = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$moderatorOptions = $conn->query("SELECT user_id, full_name, email FROM users WHERE role = 'office' AND status = 'active' ORDER BY full_name ASC");

$clubs = $conn->query(
    "SELECT c.club_id, c.club_name, c.status, c.created_at,
            u.full_name AS moderator_name, u.email AS moderator_email
     FROM institutional_clubs c
     LEFT JOIN users u ON u.user_id = c.moderator_user_id
     ORDER BY c.club_name ASC"
);

require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Institutional Clubs</h1>
        <p>Add clubs and their moderators. Students pick one club per request — only that moderator sees the approval.</p>
    </div>
    <a class="btn btn-primary" href="#club-form" data-form-target="club-form"><i data-lucide="plus"></i> Add Club</a>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<div class="toolbar-card"><div class="search-field"><i data-lucide="search"></i><input type="search" placeholder="Search clubs..."></div></div>

<div class="form-card announcement-form floating-form <?= $editClub ? "is-open" : "" ?>" id="club-form">
    <div class="floating-form-header">
        <h2><?= $editClub ? "Edit club" : "Add club" ?></h2>
        <button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="club_id" value="<?= (int)($editClub["club_id"] ?? 0) ?>">
        <label>Club name<input name="club_name" required value="<?= e($editClub["club_name"] ?? "") ?>" placeholder="e.g. Choir"></label>
        <label>Moderator (existing office user)
            <select name="moderator_user_id">
                <option value="">Select moderator — or create one below</option>
                <?php if ($moderatorOptions): while ($u = $moderatorOptions->fetch_assoc()): ?>
                    <option value="<?= (int)$u["user_id"] ?>" <?= (int)($editClub["moderator_user_id"] ?? 0) === (int)$u["user_id"] ? "selected" : "" ?>><?= e($u["full_name"] . " - " . $u["email"]) ?></option>
                <?php endwhile; endif; ?>
            </select>
            <small class="field-help">Pick an active office user, or leave blank and fill in the new moderator fields.</small>
        </label>
        <div class="form-grid">
            <label>New moderator full name<input name="moderator_full_name" value="<?= e($editClub["moderator_full_name"] ?? "") ?>" placeholder="e.g. Juan Dela Cruz"></label>
            <label>New moderator username<input name="moderator_username" value="<?= e($editClub["moderator_username"] ?? "") ?>" placeholder="e.g. juanchoir"></label>
            <label>New moderator email<input type="email" name="moderator_email" value="<?= e($editClub["moderator_email"] ?? "") ?>" placeholder="e.g. juan@ckcm.edu.ph"></label>
            <label>New moderator password<span class="password-field"><input type="password" name="moderator_password" placeholder="<?= $editClub ? "Fill all 4 fields to replace moderator" : "Required when creating" ?>"><button type="button" class="password-toggle" aria-label="Show password"><i data-lucide="eye"></i></button></span></label>
        </div>
        <label>Status<select name="status"><option value="active" <?= ($editClub["status"] ?? "active") === "active" ? "selected" : "" ?>>Active</option><option value="inactive" <?= ($editClub["status"] ?? "active") === "inactive" ? "selected" : "" ?>>Inactive</option></select></label>
        <button class="btn btn-primary" type="submit"><i data-lucide="save"></i><?= $editClub ? "Save changes" : "Add club" ?></button>
    </form>
</div>

<div class="table-card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Club</th><th>Moderator</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($clubs && $clubs->num_rows): ?>
                <?php while ($row = $clubs->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= e($row["club_name"]) ?></strong></td>
                        <td><?= e($row["moderator_name"] ? ($row["moderator_name"] . " — " . ($row["moderator_email"] ?? "")) : "—") ?></td>
                        <td><span class="status status-<?= e($row["status"]) ?>"><?= e(ucfirst($row["status"])) ?></span></td>
                        <td class="action-group">
                            <a class="table-action" href="institutional_clubs.php?edit=<?= (int)$row["club_id"] ?>#club-form">Edit</a>
                            <form method="post">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="club_id" value="<?= (int)$row["club_id"] ?>">
                                <button class="table-action" type="submit"><?= $row["status"] === "active" ? "Deactivate" : "Activate" ?></button>
                            </form>
                            <form method="post" data-delete-confirm="Delete <?= e($row["club_name"]) ?>? This will remove its clearance transactions.">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="club_id" value="<?= (int)$row["club_id"] ?>">
                                <button class="table-action table-action-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="4" class="empty-state">No clubs found. Add your first club above.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require "_footer.php"; ?>
