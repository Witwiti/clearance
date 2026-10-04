<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_role(string $role): void
{
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== $role) {
        header("Location: ../index.php");
        exit();
    }
}

function is_dean_user(): bool
{
    return ($_SESSION['role'] ?? '') === 'office' && (int)($_SESSION['college_dean'] ?? 0) === 1;
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
