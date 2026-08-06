# CHANGELOG-AUDIT.md

Track of audit findings fixed, chronological.

## Phase 1

- [S1] env.json secrets: get_config() now checks env vars first, falls back to env.json with deprecation warning. Added .htaccess rules to block env.json/session.json/ip.json from web access. Files: `htdocs/src/utils/config.php`, `htdocs/.htaccess`. Test: `tests/test_s1_env_vars.php`.
