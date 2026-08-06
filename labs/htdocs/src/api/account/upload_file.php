<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
$username = $user->getUsername();
if (!$username) {
    echo json_encode(['status' => 'error', 'error' => 'User not found']); exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'error' => 'No file uploaded or upload error.']); exit;
}

$file = $_FILES['file'];

// S12: File type allowlist validation
$allowedExtensions = [
    'jpg', 'jpeg', 'png', 'gif', 'webp',  // Images
    'pdf',                                   // Documents
    'txt', 'md', 'csv', 'json', 'xml',     // Text
    'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',  // Office
];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExtensions)) {
    echo json_encode(['status' => 'error', 'error' => 'File type not allowed. Allowed: ' . implode(', ', $allowedExtensions)]); exit;
}

// Block double-extension attacks (e.g., shell.php.jpg)
$parts = explode('.', $file['name']);
if (count($parts) > 2) {
    echo json_encode(['status' => 'error', 'error' => 'Files with multiple extensions are not allowed.']); exit;
}

$maxSize = 5 * 1024 * 1024; // 5 MB
if ($file['size'] > $maxSize) {
    echo json_encode(['status' => 'error', 'error' => 'File size exceeds 5MB.']); exit;
}

$userId = $user->getUserId();
if (!$userId) {
    echo json_encode(['status' => 'error', 'error' => 'User ID not found']); exit;
}

// Generate safe filename to prevent overwrites
$originalName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $file['name']);
$filename = time() . '_' . $originalName;
$s3Path = "labassets/uploads/{$userId}/{$filename}";

if (Storage::upload($file['tmp_name'], $s3Path)) {
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'error' => 'Failed to upload file to MinIO.']);
}
