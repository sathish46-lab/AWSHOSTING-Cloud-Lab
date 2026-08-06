# CHANGELOG-AUDIT.md

Track of audit findings fixed, chronological.

## Phase 1

- [S1] env.json secrets: get_config() now checks env vars first, falls back to env.json with deprecation warning. Added .htaccess rules to block env.json/session.json/ip.json from web access. Files: `htdocs/src/utils/config.php`, `htdocs/.htaccess`. Test: `workspace/tests/test_s1_env_vars.php`.
- [S2] Hardcoded DB creds removed from MySqlManager, PostgreSqlManager, MariaDbManager, RedisManager. Now read from env vars via get_config(). Keys: `mysql_root_pass`, `redis_admin_pass`. Files: `htdocs/src/lib/services/{MySql,PostgreSql,MariaDb,Redis}Manager.php`. Test: `workspace/tests/test_s2_no_hardcoded_creds.php`.
