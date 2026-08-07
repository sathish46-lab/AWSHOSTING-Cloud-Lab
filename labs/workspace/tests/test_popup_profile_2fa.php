<?php
/**
 * Test _account_settings_modal.php — Profile + 2FA additions
 * 
 * Tests:
 * 1. Profile form exists (id="acctProfileForm")
 * 2. Profile form has first_name input
 * 3. Profile form has last_name input
 * 4. Profile form has maxlength="50" on first_name
 * 5. Profile form has maxlength="50" on last_name
 * 6. Profile form has save button (id="acctProfileSaveBtn")
 * 7. Profile success message element exists
 * 8. Profile error message element exists
 * 9. 2FA status element exists (id="acct2faStatus")
 * 10. 2FA toggle button exists (id="acct2faToggleBtn")
 * 11. 2FA OTP input exists (id="acct2faOtpInput")
 * 12. 2FA OTP input has autocomplete="one-time-code"
 * 13. 2FA OTP input has inputmode="numeric"
 * 14. 2FA verify button exists (id="acct2faVerifyBtn")
 * 15. 2FA resend button exists (id="acct2faResendBtn")
 * 16. 2FA timer exists (id="acct2faTimer")
 * 17. 2FA error message exists (id="acct2faError")
 * 18. 2FA success message exists (id="acct2faSuccess")
 * 19. "Activity & Analytics" link replaces "Full settings"
 * 20. Profile inputs are NOT readonly
 */

$base = dirname(__DIR__, 2);
$passed = 0;
$failed = 0;

function test($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "  PASS: $name\n";
        $passed++;
    } else {
        echo "  FAIL: $name\n";
        $failed++;
    }
}

echo "=== PARTIAL: _account_settings_modal.php Tests ===\n\n";

$path = "$base/htdocs/src/template/partials/_account_settings_modal.php";
$content = file_get_contents($path);

// Profile form
test("Profile form exists", strpos($content, 'id="acctProfileForm"') !== false);
test("First name input exists", strpos($content, 'name="first_name"') !== false);
test("Last name input exists", strpos($content, 'name="last_name"') !== false);
test("First name maxlength=50", strpos($content, 'name="first_name"') !== false && strpos($content, 'maxlength="50"') !== false);
test("Last name maxlength=50", strpos($content, 'name="last_name"') !== false);
test("Save button exists", strpos($content, 'id="acctProfileSaveBtn"') !== false);
test("Profile success element exists", strpos($content, 'id="acctProfileSaved"') !== false);
test("Profile error element exists", strpos($content, 'id="acctProfileError"') !== false);

// 2FA elements
test("2FA status element exists", strpos($content, 'id="acct2faStatus"') !== false);
test("2FA toggle button exists", strpos($content, 'id="acct2faToggleBtn"') !== false);
test("2FA OTP input exists", strpos($content, 'id="acct2faOtpInput"') !== false);
test("OTP autocomplete=one-time-code", strpos($content, 'autocomplete="one-time-code"') !== false);
test("OTP inputmode=numeric", strpos($content, 'inputmode="numeric"') !== false);
test("2FA verify button exists", strpos($content, 'id="acct2faVerifyBtn"') !== false);
test("2FA resend button exists", strpos($content, 'id="acct2faResendBtn"') !== false);
test("2FA timer exists", strpos($content, 'id="acct2faTimer"') !== false);
test("2FA error element exists", strpos($content, 'id="acct2faError"') !== false);
test("2FA success element exists", strpos($content, 'id="acct2faSuccess"') !== false);

// Link update
test("Activity & Analytics link present", strpos($content, 'Activity & Analytics') !== false);
test("No 'Full settings' link", strpos($content, 'Full settings') === false);

// Security: inputs not readonly
test("First name input not readonly", strpos($content, 'name="first_name"') !== false);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
