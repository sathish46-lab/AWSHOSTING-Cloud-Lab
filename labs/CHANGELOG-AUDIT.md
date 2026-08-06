# CHANGELOG-AUDIT.md

Track of audit findings fixed, chronological.

## Phase 1

- [S1] env.json secrets: get_config() now checks env vars first, falls back to env.json with deprecation warning. Added .htaccess rules to block env.json/session.json/ip.json from web access. Files: `htdocs/src/utils/config.php`, `htdocs/.htaccess`. Test: `workspace/tests/test_s1_env_vars.php`.
- [S2] Hardcoded DB creds removed from MySqlManager, PostgreSqlManager, MariaDbManager, RedisManager. Now read from env vars via get_config(). Keys: `mysql_root_pass`, `redis_admin_pass`. Files: `htdocs/src/lib/services/{MySql,PostgreSql,MariaDb,Redis}Manager.php`. Test: `workspace/tests/test_s2_no_hardcoded_creds.php`.
- [S3] Added global security headers middleware in load.php: X-Frame-Options, X-Content-Type-Options, X-XSS-Protection, CSP, Referrer-Policy, Permissions-Policy, HSTS (HTTPS only). File: `htdocs/src/load.php`. Test: `workspace/tests/test_s3_security_headers.php`.
- [S4] Auth-gated debug scripts: set_admin.php, fix.php, sync_ip_registry.php now require superuser role. Return 401/403 for unauthorized access. Log admin actions. Files: `htdocs/{set_admin,fix,sync_ip_registry}.php`. Test: `workspace/tests/test_s4_debug_scripts_auth.php`.
- [R1+R2] Fixed rate limit identity bypass: removed $_GET['email'] (only POST now), added proxy allowlist for X-Forwarded-For trust. File: `htdocs/src/utils/ratelimit.php`. Test: `workspace/tests/test_r1r2_rate_limit_identity.php`.
- [S12+S13] Added file type allowlist validation on uploads (upload_file.php, file_upload.php). Changed MinIO ACL from public-read to private. Added Storage::getSignedUrl() for secure access. Files: `htdocs/src/lib/core/Storage.class.php`, `htdocs/src/api/account/upload_file.php`, `htdocs/src/api/instances/file_upload.php`. Test: `workspace/tests/test_s12s13_upload_validation.php`.

## Phase 2

- [SE1+SE2+H3] Session tokens now hashed with password_hash() before MongoDB storage. Added 30-day token expiry enforcement on auto-login. Logout matches by hashed token. Files: `htdocs/src/lib/core/UserSession.class.php`, `htdocs/src/lib/core/WebAPI.class.php`. Test: `workspace/tests/test_se1se2h3_token_hashing.php`.
- [SE3+S22] Added ini_set for session.cookie_secure and session.cookie_samesite before session_start(). Secure flag is conditional on HTTPS. File: `htdocs/src/load.php`. Test: `workspace/tests/test_se3s22_cookie_flags.php`.
- [T1+T2+T3] Added explicit timeouts: VPN cURL (5s connect/10s total), RabbitMQ (5s read_write_timeout), MongoDB PHP (5s serverSelection + connect timeout). Replaced die() with graceful JSON 503 response in DatabaseConnection. Files: `htdocs/src/lib/core/{VPN,RabbitClient,DatabaseConnection}.class.php`. Test: `workspace/tests/test_t1t2t3_timeouts.php`.
- [F1-F7] Added retry-with-backoff to VPN API calls (2 retries, exponential backoff). Fixed die() calls in WebAPI.class.php and vpn/download.php to return proper HTTP/JSON responses. Sanitized device name in Content-Disposition header. Files: `htdocs/src/lib/core/{VPN/WebAPI}.class.php`, `htdocs/src/api/vpn/download.php`. Test: `workspace/tests/test_f1f7_retry_errors.php`.
- [Q1-Q5] Configured RabbitMQ Dead Letter Queues for labs_jobs, ai_jobs, ai_content_jobs. Added _publish_to_dlq() helper in labs_worker.py to publish failed jobs for later inspection. DLQs have 7-day retention. Files: `worker/labs_worker.py`, `worker/ai_worker.py`. Test: `workspace/tests/test_q1q5_dlq.php`.
- [D1+D6+D7] Created AuditLog class (`htdocs/src/lib/core/AuditLog.class.php`) with write-through log() and query() methods. Integrated into 8 critical mutating endpoints: instance create/trash/restore/permanent_delete, VPN add/delete, MySQL create/delete. Added `created_by`/`updated_by` fields to instance and service records. Auto-detects user from Session. Stores IP, user-agent, request URI, method. Test: `workspace/tests/test_d1d6d7_audit_log.php`.
