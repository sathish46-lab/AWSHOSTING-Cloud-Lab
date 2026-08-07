# TEST-DEBT.md

Audit of all test files under `workspace/tests/` — every file using the
`file_get_contents()` + `strpos()` source-text pattern is listed here.

**Rule violated:** Tests must exercise real runtime behavior and assert on real
output. None of these tests call real code, make HTTP requests, instantiate
classes, or verify DB state changes.

---

## Summary

| Metric | Count |
|--------|-------|
| Total test files | 23 |
| Files using file-as-text pattern | **22** (96%) |
| Files with real runtime tests | **1** (test_s1_env_vars.php — 4 tests, minimal) |
| Total test assertions | 327 |
| Assertions that are source-text grep | **313** (95.7%) |
| Tests making HTTP requests | **0** |
| Tests instantiating real classes | **0** |
| Tests verifying DB state | **0** |
| Tests with real negative-path/security behavior | **0** |

---

## File-by-File Debt List

| # | File | Tests | Pattern Used | What It Claims to Test | What It Actually Does |
|---|------|:-----:|-------------|----------------------|----------------------|
| 1 | `test_activity_analytics_api.php` | 29 | `file_get_contents` + `strpos` | Auth, user scoping, IDOR, safe fields | Greps for `Session::getAuthStatus()`, `$_GET`, `'user_agent'` in source text |
| 2 | `test_activity_api.php` | 33 | `file_get_contents` + `strpos` | Auth, IDOR, allowlist, pagination, safe fields | Greps for `$validActions`, `$maxLimit = 100`, etc. in source text |
| 3 | `test_d1d6d7_audit_log.php` | 30 | `file_get_contents` + `strpos` | AuditLog integration, created_by fields | Greps for `AuditLog::log(`, `'created_by'` in 9 files |
| 4 | `test_d3_soft_delete.php` | 12 | `file_get_contents` + `strpos` | Soft delete, query filtering | Greps for `updateOne` + `'status' => 'deleted'` in 10 files |
| 5 | `test_d4d10_purge_maintenance.php` | 16 | `file_get_contents` + `strpos` | Purge script, maintenance script | Greps for `--days`, `session_tokens`, etc. in 2 cron scripts |
| 6 | `test_d5_transactions.php` | 8 | `file_get_contents` + `strpos` | Compensating transactions | Uses `strpos` index to check `insertOne` comes before `deleteOne` in text |
| 7 | `test_d8d9_health_circuit.php` | 18 | `file_get_contents` + `strpos` | Circuit breaker, health checks | Greps for `public static function allow(`, `CircuitBreaker::allow` in source |
| 8 | `test_f1f7_retry_errors.php` | 11 | `file_get_contents` + `strpos` | Retry logic, error responses | Greps for `for ($attempt`, `sleep(min(`, strips comments before checking |
| 9 | `test_popup_profile_2fa.php` | 21 | `file_get_contents` + `strpos` | Profile form, 2FA elements in popup | Greps for `id="acctProfileForm"`, `autocomplete="one-time-code"` in HTML source |
| 10 | `test_q1q5_dlq.php` | 10 | `file_get_contents` + `strpos` | RabbitMQ DLQ config | Greps for `DLQ_NAME`, `x-dead-letter-exchange` in Python source |
| 11 | `test_r1r2_rate_limit_identity.php` | 6 | `file_get_contents` + `strpos` | Rate limit identity, proxy trust | Greps for `$_GET['email']` absence, `trustedProxies` presence in source |
| 12 | `test_s1_env_vars.php` | 4 | **REAL RUNTIME** | `get_config()` env var priority | Calls `putenv()`, `get_config()`, asserts return value — **only real test** |
| 13 | `test_s12s13_upload_validation.php` | 19 | `file_get_contents` + `strpos` | File upload allowlist, MinIO ACL | Greps for `$acl = 'private'`, loops 13 extensions checking text absence |
| 14 | `test_s2_no_hardcoded_creds.php` | 14 | `file_get_contents` + `strpos` | No hardcoded credentials | `require_once` is dead code; greps for `get_config(` in 4 manager files |
| 15 | `test_s3_security_headers.php` | 15 | `file_get_contents` + `strpos` | Security headers middleware | Greps for `header('X-Frame-Options:')`, CSP directives in load.php source |
| 16 | `test_s4_debug_scripts_auth.php` | 15 | `file_get_contents` + `strpos` | Debug scripts auth-gated | Greps for `getRole() !== 'superuser'`, `http_response_code(403)` in 3 files |
| 17 | `test_s5_csrf.php` | 20 | `file_get_contents` + `strpos` | CSRF on mutating endpoints | Greps for `CsrfProtection::require()` text in 8 endpoint files |
| 18 | `test_s7s8_ratelimit_lockout.php` | 11 | `file_get_contents` + `strpos` | Rate limit, account lockout | Greps for `locked_until`, `failed_login_attempts`, `>= 5` in source |
| 19 | `test_se1se2h3_token_hashing.php` | 8 | `file_get_contents` + `strpos` | Token hashing, expiry | Greps for `password_hash($sessionToken`, `30 * 24 * 3600` in source |
| 20 | `test_se3s22_cookie_flags.php` | 5 | `file_get_contents` + `strpos` | Cookie security flags | Finds line numbers of `ini_set` calls, checks ordering by line number |
| 21 | `test_se5s23_password_change.php` | 11 | `file_get_contents` + `strpos` | Password change endpoint | Greps for `CsrfProtection::require()`, `password_verify(` in source |
| 22 | `test_t1t2t3_timeouts.php` | 8 | `file_get_contents` + `strpos` | cURL/RabbitMQ/MongoDB timeouts | Greps for `CURLOPT_CONNECTTIMEOUT`, `read_write_timeout` in source |
| 23 | `test_update_profile.php` | 14 | `file_get_contents` + `strpos` | Profile update, CSRF, XSS | Greps for `mb_substr`, `preg_replace`, `$_POST['user_id']` absence |

---

## Why Each Test Is Invalid (Examples)

**test_s5_csrf.php (20 tests, ALL source-text):**
- Claims: "CSRF validation required on 8 endpoints"
- Actually: checks that the string `CsrfProtection::require()` appears in each file
- Would still pass if: the endpoint included the file but never called the function (dead code), or if the function was empty
- Real test: send a POST without CSRF token → assert 403; send with valid token → assert 200

**test_d3_soft_delete.php (12 tests, ALL source-text):**
- Claims: "Soft delete works, queries filter deleted records"
- Actually: checks that `updateOne` and `'status' => 'deleted'` appear as text strings
- Would still pass if: the soft-deleted record query used a wrong field name, or the `updateOne` was unreachable
- Real test: insert a document, soft-delete it, query with `status: {$ne: 'deleted'}` → assert 0 results

**test_d8d9_health_circuit.php (18 tests, ALL source-text):**
- Claims: "Circuit breaker blocks when open, transitions to half-open"
- Actually: greps for `STATE_OPEN`, `return false`, `STATE_HALF_OPEN` in source
- Would still pass if: the state machine logic was broken (e.g., never transitions to half-open)
- Real test: call `recordFailure()` 5 times → assert `allow()` returns false; wait for cooldown → assert `allow()` returns true

---

## Rewrite Priority

### P0 — Security-critical (rewrite first)
These tests verify auth, CSRF, IDOR, input validation — the highest-risk surface:

1. `test_activity_api.php` — IDOR, allowlist injection
2. `test_activity_analytics_api.php` — IDOR, data leakage
3. `test_s5_csrf.php` — CSRF enforcement
4. `test_update_profile.php` — XSS, CSRF, input validation
5. `test_se5s23_password_change.php` — password change auth, CSRF
6. `test_r1r2_rate_limit_identity.php` — rate limit bypass
7. `test_s7s8_ratelimit_lockout.php` — lockout behavior
8. `test_s4_debug_scripts_auth.php` — debug script auth

### P1 — Core behavior (rewrite second)
These verify critical platform mechanics:

9. `test_d3_soft_delete.php` — soft delete + query filtering
10. `test_d5_transactions.php` — compensating transactions
11. `test_s1_env_vars.php` — expand existing real tests
12. `test_se1se2h3_token_hashing.php` — token hashing + expiry
13. `test_se3s22_cookie_flags.php` — cookie flags

### P2 — Infrastructure (rewrite third)
These verify infrastructure features:

14. `test_d8d9_health_circuit.php` — circuit breaker state machine
15. `test_t1t2t3_timeouts.php` — timeout behavior
16. `test_f1f7_retry_errors.php` — retry logic
17. `test_s3_security_headers.php` — security headers
18. `test_s12s13_upload_validation.php` — file upload validation

### P3 — Lower priority (rewrite last)
These verify less critical features:

19. `test_d1d6d7_audit_log.php` — audit log integration
20. `test_d4d10_purge_maintenance.php` — cron scripts
21. `test_q1q5_dlq.php` — DLQ config
22. `test_popup_profile_2fa.php` — popup HTML structure
23. `test_s2_no_hardcoded_creds.php` — credential patterns
