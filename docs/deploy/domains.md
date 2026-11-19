# Domains and subdomains (QC-044)

**Admin → Countries & gateways → Domains** maps hostnames to what they serve. It needs the permission `domains.view` or `domains.manage`; only super admins have it by default. The map lives in the central `domains` table. It is cached and forgotten on every change, and every change is audited in the `domains` activity log.

| Kind | Example | Effect |
|---|---|---|
| `storefront` | `nawy.om`, `ar.nawy.om` | The website opens that host's market (country plus language; the country default when the language is empty) for paths without a market prefix. The **primary** storefront host of a country is used in emails, payment returns and `meta.storefront_host` of `GET /api/v1/{market}/context`. |
| `panel` | `admin.omn.nawy.com` | An alias. The backend answers 302 to the panel's canonical host (`{panel}.{NAWY_BASE_DOMAIN}`), adding `?country={cc}` when the alias is locked to a country. Panels themselves stay on their canonical hosts, so sessions and cookies are unchanged. |
| `portal` / `api` | `business.nawy.om`, `api.nawy.om` | Bookkeeping for DNS and TLS. A `redirect_to` host still redirects. |

- **`redirect_to`.** The host answers **301** to another host. On the website, the proxy does the redirect; on the backend, the `MapCustomHost` middleware does. Example: `www.nawy.om` → `nawy.om`.
- **Primary host.** Each kind, country (and panel) has at most one; setting a new primary clears the old one.
- **Refused hostnames.** The canonical panel hosts and the API host (`APP_URL`) cannot be mapped.

## Lookups

- **Website.** `proxy.ts` calls `GET /api/v1/domains/resolve?host=…` and keeps each answer for 5 minutes. A 404 means the host is not mapped, and the website uses its usual default or cookie market.
- **Backend.** `App\Domains\Countries\Http\Middleware\MapCustomHost` is a global middleware. It reads the cached map only for hosts that are not the API host or a canonical panel host.

## Adding a custom domain (runbook)

1. **DNS.**
   - Storefront hosts: `CNAME` (or `ALIAS`/`ANAME` at the apex) to the website edge, `NAWY_DOMAINS_STOREFRONT_TARGET`.
   - Panel, portal and API hosts: point to the backend load balancer, `NAWY_DOMAINS_BACKEND_TARGET`.
   - Both targets are shown on the Domains page.
2. **TLS.** Issue a certificate that covers the host on the edge or load balancer: Let's Encrypt HTTP-01 once DNS resolves, or DNS-01 for wildcards. Renewal is automatic on the edge.
3. **Web server.** Make sure the backend virtual host accepts the new `server_name` (for panel aliases and redirect hosts). Make sure the website host accepts it too (Next.js accepts any host).
4. **Admin.** Add the domain, tick *DNS verified*, and set *TLS certificate = issued*.
5. **Check.**
   - `curl -sI https://{host}/` should redirect to `/{market}` for a storefront host, or to the canonical panel host for a panel alias.
   - `curl https://{api}/api/v1/domains/resolve?host={host}` should show the mapping.
6. **Search and email.** Set the canonical host per market on the storefront if needed. Mail links follow the primary host automatically.

Removing a domain stops the mapping at once in the backend, and within 5 minutes on the website. Remove the DNS record afterwards.

## Environment

| Variable | Use |
|---|---|
| `NAWY_DOMAINS_STOREFRONT_TARGET` | DNS target shown for storefront hosts, e.g. `edge.nawy.com` |
| `NAWY_DOMAINS_BACKEND_TARGET` | DNS target shown for panel and API hosts, e.g. `lb.nawy.com` |
