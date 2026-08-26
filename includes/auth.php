<?php

require_once __DIR__ . "/session.php";

function requireLogin()
{
    if (!isset($_SESSION["user_id"])) {
        header("Location: ../auth/login.php");
        exit;
    }
}

function requireRole($allowedRoles)
{
    requireLogin();

    if (!is_array($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }

    $currentRole = $_SESSION["role"] ?? "";

    if (!in_array($currentRole, $allowedRoles, true)) {
        header("Location: ../index.php");
        exit;
    }
}

function currentUserId()
{
    return $_SESSION["user_id"] ?? null;
}

function currentUserName()
{
    return $_SESSION["full_name"] ?? "";
}

function currentUserRole()
{
    return $_SESSION["role"] ?? "";
}