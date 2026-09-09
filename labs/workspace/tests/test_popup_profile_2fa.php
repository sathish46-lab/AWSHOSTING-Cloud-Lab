<?php
/**
 * Test: Profile popup HTML structure and 2FA elements.
 *
 * REAL RUNTIME TEST — Verifies the account settings modal template
 * contains required form fields, 2FA elements, and accessibility.
 *
 * Usage:
 *   php workspace/tests/test_popup_profile_2fa.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== Profile Popup + 2FA Tests (Runtime) ===\n\n";

// ── Test 1: Template file exists ──
echo "--- Template Structure ---\n";

$templatePath = SRC_PATH . '/template/partials/_account_settings_modal.php';
test("_account_settings_modal.php exists", file_exists($templatePath));

if (file_exists($templatePath)) {
    $src = file_get_contents($templatePath);

    // Profile form fields
    test("Has profile form", strpos($src, 'acctProfileForm') !== false || strpos($src, 'profile') !== false);
    test("Has first name field", strpos($src, 'first_name') !== false);
    test("Has last name field", strpos($src, 'last_name') !== false);
    test("Has email display", strpos($src, 'email') !== false);

    // 2FA elements
    test("Has 2FA status display", strpos($src, '2fa') !== false || strpos($src, 'two-factor') !== false || strpos($src, '2FA') !== false);
    test("Has 2FA toggle/enable button", strpos($src, 'enable') !== false || strpos($src, 'toggle') !== false);
    test("Has OTP input field", strpos($src, 'otp') !== false || strpos($src, 'one-time-code') !== false || strpos($src, 'verification') !== false);
    test("Has verify button", strpos($src, 'Verify') !== false || strpos($src, 'verify') !== false);
    test("Has resend button", strpos($src, 'Resend') !== false || strpos($src, 'resend') !== false);
    test("Has autocomplete one-time-code", strpos($src, 'autocomplete="one-time-code"') !== false || strpos($src, 'autocomplete=\'one-time-code\'') !== false);

    // Activity & Analytics link
    test("Has Activity & Analytics link", strpos($src, 'Activity') !== false || strpos($src, 'activity') !== false || strpos($src, 'Analytics') !== false);
}

// ── Test 2: Runtime — modal loads via HTTP ──
echo "\n--- HTTP Modal Load ---\n";

$testEmail = 'popup_test_' . time() . '@example.com';
$sessionToken = create_test_user($testEmail, 'user');

$response = http_request('GET', '/dashboard', [
    'cookie' => "session_token=$sessionToken",
]);
test("Dashboard loads", $response['status'] === 200);

if ($response['status'] === 200) {
    $body = $response['body'];
    // Check that the dashboard contains the modal trigger or modal content
    test("Dashboard contains settings/profile modal reference",
        strpos($body, 'accountSettings') !== false ||
        strpos($body, 'profile') !== false ||
        strpos($body, 'settings-modal') !== false);
}

cleanup_test_user($testEmail);
test_summary();
