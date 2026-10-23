# Boarding Hauz — static security and bug audit

## Scope and limits

Reviewed the uploaded ZIP's PHP source, authentication/session helpers, branch switching, payment-proof serving/upload paths, room-image upload validation, SQL schema/patch files, and upload-directory rules. All PHP files in the archive passed `php -l` before the changes below. This is a static review; it was not possible to validate database-dependent workflows because no database server/configuration was supplied. The app has not been penetration-tested and should not be described as production-certified.

## Critical/high-priority findings and changes

### 1. `boarding_hauz/auth.php` — critical runtime authorization-helper defect (fixed)

`requireSelectedBranch()` and `currentBranchLandlordId()` were declared *inside* `requireBranchAccess()`. PHP functions declared inside another function are still global after that function runs, so a later branch authorization call could cause a fatal `Cannot redeclare` error. These helpers have been moved to top-level scope. Branch existence and landlord ownership are now checked for both branch access paths; role/status checks remain enforced by `requireRole()`.

### 2. `boarding_hauz/includes/db_connect.php` — insecure deployment defaults / charset (improved)

The connection hard-coded `root` with an empty password. Environment variables are now supported, and `utf8mb4` is set explicitly. The empty-password fallback is retained only to avoid breaking a local XAMPP-style development setup; **production must set a dedicated least-privilege database user and a strong password** via `BOARDING_HAUZ_DB_USER` and `BOARDING_HAUZ_DB_PASSWORD` (plus the other `BOARDING_HAUZ_DB_*` settings).

### 3. `boarding_hauz/uploads/rooms/.htaccess` — uploaded-file execution defense (added)

Room images were stored under a web-accessible upload directory without an equivalent server rule. The new Apache rule disables directory listings and denies common script extensions. Keep uploads outside the web root if practical; configure the equivalent rule in Nginx/IIS if not using Apache. Room upload validation already restricts the extension and verifies image content.

## Other important review notes (not silently changed)

- **CSRF:** Many state-changing pages already use session tokens (login, room applications, tenant payments/maintenance/messages, and admin payment/maintenance/inquiry/room/tenant operations). Verify every POST action, including profile edits/password changes and logout, has a token check. In particular, review `tenant_profile.php` for CSRF protection before deployment.
- **Rate limiting:** Both tenant and admin login need server-side throttling/monitoring to slow password guessing. Consider a generic error message and audit log for repeated failures.
- **Session security:** The shared auth helper now uses strict mode, cookie-only sessions, HttpOnly, SameSite=Lax, session cookies, and safe logout cookie deletion. HTTPS deployments must enforce HTTPS site-wide; the Secure flag is automatically set when PHP detects HTTPS.
- **Payment proof access:** Tenant and admin proof endpoints bind records to the tenant or selected branch and validate stored paths. Keep proof storage private and never trust client-provided paths. SVG is allowed by the display endpoints for legacy stored files; newly submitted receipts accept JPG/PNG/WebP/PDF. Prefer rejecting/removing legacy SVG proofs or always forcing download to reduce active-content risk.
- **Branch isolation:** Continue auditing every tenant/admin SQL query to ensure branch-scoped operations filter by `branch_id` and tenant operations are tied to the authenticated user's tenant record. A prepared statement prevents injection but does not by itself prevent cross-branch IDOR.
- **Room capacity/allocation:** Validate capacity and occupancy inside a transaction (and, where the schema permits, enforce uniqueness/constraints) to prevent concurrent applications from overbooking. Confirm application approval and tenant assignment update both sides consistently.
- **Payments:** Verify payment status transitions are allow-listed and that approving/rejecting a payment is branch-scoped and atomic. Never treat a client-submitted amount as authoritative; derive the amount from the tenant's current due balance on the server.
- **Output escaping:** Use `e($value)` for every untrusted value inserted into HTML text/attributes. For JavaScript/JSON contexts, use `json_encode()` with appropriate `JSON_HEX_*` flags rather than HTML escaping.
- **Database errors:** Do not expose SQL exception messages to users. Log details server-side and return generic messages.
- **Headers:** Add HTTPS/HSTS (only after HTTPS is fully configured), a restrictive Content-Security-Policy, `X-Content-Type-Options: nosniff`, and a suitable Referrer-Policy at the web-server layer, testing inline scripts/styles first.
- **Secrets:** Ensure `.env`, database dumps, `.vscode` files, logs, and backups are not publicly downloadable. The ZIP contains development editor configuration and SQL dumps; deploy only the application files needed by the server.

## Validation performed

- PHP syntax lint (`php -l`) across every `.php` file in the ZIP: no syntax errors found before patching.
- PHP syntax lint after patching `auth.php` and `includes/db_connect.php`: passed.
- Not performed: live DB migrations, browser-based regression tests, concurrency tests, upload exploit tests, or role/branch integration tests.

## Deployment checklist

1. Back up the database and application directory.
2. Set `BOARDING_HAUZ_DB_HOST`, `BOARDING_HAUZ_DB_PORT`, `BOARDING_HAUZ_DB_NAME`, `BOARDING_HAUZ_DB_USER`, and `BOARDING_HAUZ_DB_PASSWORD` in the server environment; use a dedicated DB user with only required privileges.
3. Ensure the web server honors `.htaccess`, or configure equivalent upload rules for the actual server.
4. Run migrations against a staging database first and check schema compatibility with the app's runtime column checks.
5. Test tenant/admin login, branch switching, branch isolation, payment upload/review, room image upload, maintenance status changes, and profile/password changes with separate test accounts.
6. Enable HTTPS and backups, then review logs and apply rate limits before launch.
