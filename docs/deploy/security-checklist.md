# Production security checklist

From the WP11 security review (findings fixed in code are marked ✅).

| Item | Status |
|---|---|
| `APP_DEBUG=false`, `APP_ENV=production` | env |
| Fake payment / social drivers refused at boot in production | ✅ `AppServiceProvider::refuseTestDriversInProduction()` |
| Two-step sign-in enforced on the ops and delivery app APIs (not only the panels) | ✅ `Identity\Support\ApiTwoFactor` |
| Suspended / banned users cannot sign in to or stay in a panel | ✅ `PanelLoginController`, `EnsurePanelMembership` |
| Brute force: per email+IP, per IP, **and per account** (30/h) limits on every sign-in | ✅ `AppServiceProvider::rateLimiters()` |
| Default rate limit on every API route (`NAWY_API_RATE_PER_MINUTE`, 600/min per user or IP) | ✅ `throttleApi('api')` |
| Security headers: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, HSTS on HTTPS, CSP on panel HTML | ✅ `Shared\Http\Middleware\SecurityHeaders` |
| Session cookie `Secure` by default in production; consider `SESSION_ENCRYPT=true` | ✅ `config/session.php` |
| Test login `test@example.com` only seeded in local/testing | ✅ `DatabaseSeeder` |
| CORS: explicit origin allowlist (`NAWY_CORS_ORIGINS`), no credentials | already in place |
| Uploads: mime + size validated, random names, private documents only via signed URLs | already in place |
| Gateway credentials write-only, never flashed or logged; webhooks signature-verified | already in place |
| Secrets only in the secret manager / env; `.env` never committed | process |
