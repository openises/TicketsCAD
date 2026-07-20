# Security policy

TicketsCAD v4 is used by emergency-communications volunteer groups; we
take security seriously. Thank you for taking the time to report
problems responsibly.

## Reporting a vulnerability

**Do not open a public issue.** Use one of the following private
channels:

1. **GitHub Security Advisories** — https://github.com/openises/TicketsCAD/security/advisories/new
2. **Email the maintainer** — `ejosterberg@gmail.com`. Use a subject line
   that begins with `[TicketsCAD security]`.

Please include:

- Affected version (commit SHA or `NEWUI_VERSION`)
- A clear description of the issue and the impact
- Steps to reproduce — a minimal proof-of-concept request, payload, or
  setup is ideal
- Whether the issue has been disclosed publicly anywhere

We aim to acknowledge new reports within **3 business days** and provide
a remediation timeline within **10 business days**. Critical issues
(authentication bypass, RCE, data loss, mass account takeover) are
prioritized for same-week patches.

## Scope

In scope:

- The PHP code in `api/`, `inc/`, top-level pages, and `proxy/`
- The migration tooling in `tools/install_fresh.php`,
  `tools/import-fcc.php`, and the test suite
- The shipped systemd unit and Apache `.htaccess` files
- The schema as defined in `sql/` and applied by `install_fresh`

Out of scope:

- The legacy `openises/tickets` v3.x codebase — file those reports there
- Vendored libraries under `vendor/`, `assets/vendor/` — file upstream
- Penetration testing of a running instance — please coordinate with the
  operator first
- Findings on a clearly out-of-date deployment that has missed published
  patches

## What you can expect from us

- Confirmation of receipt
- A CVSS or severity assessment
- Coordinated disclosure — we ask for **45 days** to patch + ship before
  public disclosure, longer for issues that require schema changes
- Credit in the audit doc and release notes if you want it

## Existing audit + hardening posture

NewUI v4 went through a multi-session security audit in April 2026.
Every CRITICAL and HIGH finding has been remediated with a regression
test. The project's security posture, key management, and CJIS notes are documented
in [`docs/SECURITY-POLICY.md`](docs/SECURITY-POLICY.md).

Run the security tests locally before declaring an installation safe:

```bash
php tests/test_security_f001_upload.php          # upload RCE chain
php tests/test_security_f002_feed.php            # feed fail-closed
php tests/test_security_f003_fileupload.php      # legacy file-upload
php tests/test_security_f004_idor.php            # IDOR triplet
php tests/test_security_f007_sse_visibility.php  # SSE per-user filter
php tests/test_security_csrf_bundle.php          # CSRF on writes
php tests/test_pre_release_fixes.php             # regression bundle
```

## Operator hardening checklist

- Run `php tools/install_fresh.php` after every upgrade — the migration
  ships schema fixes alongside code.
- Set a non-empty `feed_api_key` in Settings → API Keys before exposing
  `api/feed.php` to anything outside the LAN.
- Verify `uploads/.htaccess` exists and contains `php_flag engine off`.
  `install_fresh` writes it if missing.
- Make sure `keys/` lives **outside** the webroot (`/var/www/keys/` for
  the standard layout). PEM files: mode 600, owner = web server user.
- Enable HTTPS for every public install. Session cookies set
  `Secure` automatically when `$_SERVER['HTTPS']` is on.
- Rotate the encryption key (`keys/private.pem`) if compromise is
  suspected — see [`docs/SECURITY-POLICY.md`](docs/SECURITY-POLICY.md).
