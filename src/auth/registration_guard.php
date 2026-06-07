<?php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/auth_helpers.php';

function require_ip_registration_complete(): void
{
    $currentRole = normalize_role((string)($_SESSION['role'] ?? ''));

    if ($currentRole !== 'ip_member') {
        return;
    }

    // Prevent redirect loops if the user is already on any registration page.
    $currentPage = basename($_SERVER['PHP_SELF']);
    $registrationPages = [
        'personal.php',
        'personal_submit.php',
        'personal_submit_redirect.php',
        'genealogy.php',
        'genealogy_submit.php',
        'documents.php',
        'documents_submit.php',
        'review.php',
        'review_step_fix.php',
        'verify.php'
    ];
    if (in_array($currentPage, $registrationPages, true)) {
        return;
    }

    $isComplete = (bool)($_SESSION['ip_registration_complete'] ?? false);
    if (!$isComplete) {
        header('Location: personal.php');
        exit;
    }
}
