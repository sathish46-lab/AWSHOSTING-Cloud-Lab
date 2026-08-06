<?php
require_once __DIR__ . '/../../load.php';
require_once __DIR__ . '/../../lib/core/HealthCheck.class.php';

// Health check endpoint — accessible to authenticated users
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

HealthCheck::jsonResponse();
