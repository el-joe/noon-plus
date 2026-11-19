# Noon Plus on local Apache — `*.noon-plus.loc`

## Owner: do this once

The app config (`backend/.env`, `website/.env.local`) is already switched to the local domain. Three commands need `sudo`:

```bash
# 1. vhosts (http + https), modules, /etc/hosts entries, storage permissions, configtest + reload
sudo /var/www/noon-plus/deploy/local/install.sh --ssl

# 2-3. (https only) trust the local certificate system-wide (curl, Node, PHP); browsers: see below
sudo install -m 644 /var/www/noon-plus/deploy/local/certs/noon-plus.loc.crt /usr/local/share/ca-certificates/noon-plus.loc.crt
sudo update-ca-certificates
```

Without `--ssl` only the http vhosts are installed (2 and 3 are then not needed).

Then restart the storefront so it uses the rebuilt `NEXT_PUBLIC_*` values (no sudo):

```bash
cd /var/www/noon-plus/website && npm run build   # after step 1: the build reads the API at api.noon-plus.loc
# stop the running `next start -p 3000`, then:
npx next start -p 3000
```

Keep `php artisan reverb:start` running (websockets) and the queue worker as before. `php artisan serve` is no longer needed — Apache serves Laravel.

### Trust the certificate in the browser (https only)

The certificate is self-signed for `noon-plus.loc` and `*.noon-plus.loc` (`make-cert.sh`, 825 days, git-ignored `certs/`). Browsers need it trusted for camera / microphone in the live studio:

- **Chrome / Chromium / Edge (Linux):** they read the NSS store, not `/etc/ssl`:
  ```bash
  sudo apt install libnss3-tools   # if certutil is missing
  certutil -d sql:$HOME/.pki/nssdb -A -t "P,," -n noon-plus.loc -i /var/www/noon-plus/deploy/local/certs/noon-plus.loc.crt
  ```
  Restart the browser. (Alternative: Settings → Privacy and security → Security → Manage certificates → Import.)
- **Firefox:** Settings → Privacy & Security → Certificates → View Certificates → Servers → Import `noon-plus.loc.crt`, or open each https host once and "Accept the Risk and Continue".
- Regenerating the certificate (`make-cert.sh --force`) needs the import again and `sudo systemctl reload apache2`.
- The panels send HSTS on https (`includeSubDomains`); after opening `https://admin.noon-plus.loc` the browser keeps that host on https. To go back to http: `chrome://net-internals/#hsts` → delete the domain.

## URLs

| URL | What | Served by |
|---|---|---|
| http://noon-plus.loc, http://www.noon-plus.loc | storefront | Apache → `next start` 127.0.0.1:3000 |
| http://business.noon-plus.loc | public partner portal (`/` → `/join` → `/{market}/join`) | Apache → 127.0.0.1:3000 |
| http://admin.noon-plus.loc | Admin panel | mod_php, `backend/public` |
| http://partner.noon-plus.loc | Seller Center | mod_php |
| http://travel-agency.noon-plus.loc | Travel Partner | mod_php |
| http://carrier.noon-plus.loc | Shipping | mod_php |
| http://delivery.noon-plus.loc | Delivery | mod_php |
| http://marketer.noon-plus.loc | Creator Studio | mod_php |
| http://api.noon-plus.loc/api/v1 | API, webhooks, `/storage/*` files, `/up` | mod_php |
| ws://ws.noon-plus.loc/app/{key} | Reverb websockets (`/apps/*` = Laravel's event API) | Apache → 127.0.0.1:8080 |

With `--ssl` the same hosts answer on `https://` (Reverb: `wss://ws.noon-plus.loc`). One build serves both: on an https page the panel and website socket clients switch to `wss:443` themselves.

## Files

| File | Purpose |
|---|---|
| `noon-plus.loc.conf` | port 80 vhosts (storefront, portal, Laravel, Reverb) |
| `noon-plus.loc-ssl.conf` | port 443 vhosts, same layout, certificate from `certs/` |
| `install.sh` | copies the vhosts to `/etc/apache2/sites-available`, `a2enmod proxy proxy_http proxy_wstunnel rewrite headers php8.3 [ssl]`, `a2ensite`, appends missing `/etc/hosts` lines, storage permissions, `apache2ctl configtest`, reload. Idempotent; touches no other site. |
| `make-cert.sh` | self-signed wildcard certificate into `certs/` (run by `install.sh --ssl` if missing; no sudo) |
| `hosts.txt` | the `/etc/hosts` entries |

## App configuration (already applied locally; not committed)

`backend/.env` (backup `backend/.env.bak-*`):

| Key | Value |
|---|---|
| `APP_URL` | `http://api.noon-plus.loc` (storage URLs, email links' scheme) |
| `NAWY_BASE_DOMAIN` | `noon-plus.loc` (panel hosts `{panel}.noon-plus.loc`) |
| `NAWY_STOREFRONT_URL` | `http://noon-plus.loc` (CORS origin, mail links, payment returns) |
| `NAWY_CORS_ORIGINS` | `www.` / `business.` (http + https), `https://noon-plus.loc`, `http://localhost:3000` |
| `SESSION_DOMAIN` | `null` — each panel keeps its own host-only session cookie (as on `nawy.test`) |
| `REVERB_HOST` / `PORT` / `SCHEME` | `127.0.0.1` / `8080` / `http` — Laravel publishes straight to the local Reverb, independent of Apache |
| `NAWY_REVERB_PUBLIC_HOST` / `PORT` / `SCHEME` | `ws.noon-plus.loc` / `80` / `http` — the socket sent to apps and viewers |
| `VITE_REVERB_HOST` / `PORT` / `SCHEME` | `ws.noon-plus.loc` / `80` / `http` — panel assets (`npm run build` after a change) |
| `FILESYSTEM_GROUP_WRITABLE` | `true` — uploads/folders created group-writable (0664/0775, private 0660/0770) so www-data and your CLI / queue can both write `storage/` |

`website/.env.local` (backup `website/.env.local.bak-*`):

| Key | Value |
|---|---|
| `NEXT_PUBLIC_API_URL` | `http://api.noon-plus.loc/api/v1` (build time) |
| `API_URL` | `http://api.noon-plus.loc/api/v1` — server-side API root, read at runtime; use `http://127.0.0.1:8000/api/v1` to build/run before the hosts entries exist (needs `artisan serve`) |
| `NEXT_PUBLIC_PORTAL_URL` | `http://business.noon-plus.loc` ("Sell on Nawy" header link) |
| `NEXT_PUBLIC_REVERB_APP_KEY` / `HOST` / `PORT` / `SCHEME` | backend `REVERB_APP_KEY` / `ws.noon-plus.loc` / `80` / `http` |

To go back to `nawy.test` / `localhost`: restore the two `.bak-*` files, `php artisan config:clear`, rebuild panel assets and the website.

## Storage permissions

Apache runs PHP as `www-data`; your CLI (artisan, queue, Reverb) runs as you. `install.sh` gives both write access to `backend/storage` and `backend/bootstrap/cache`: group `www-data` with setgid folders, `g+rw`, and ACLs (`u:www-data`, `g:www-data`, `u:<you>` plus the same as default ACLs so new files inherit them). Nothing is made world-writable; `storage/app/private` gets no access for other users. If `setfacl` is missing: `sudo apt install acl` and run `install.sh` again.

## End-to-end tests against the local domain

```bash
cd /var/www/noon-plus/website
E2E_BASE_DOMAIN=noon-plus.loc E2E_API_PORT=80 \
E2E_API_URL=http://api.noon-plus.loc E2E_STOREFRONT_URL=http://noon-plus.loc \
npm run e2e
```

Defaults (`nawy.test`, `:8000`, `http://localhost:3000`) are unchanged for runs whose `backend/.env` is on `nawy.test`. With this `.env`, `E2E_BASE_DOMAIN=noon-plus.loc E2E_API_PORT=8000` also works against `artisan serve`.

## Troubleshooting

- `apache2ctl -S | grep noon-plus` — the vhosts Apache loaded.
- Logs: `/var/log/apache2/noon-plus.loc_*.log` (storefront / backend / ws, `_ssl_` for 443), Laravel: `backend/storage/logs/laravel.log`.
- `503` on `noon-plus.loc` / `business.`: `next start` is not running on :3000. `503` on `ws.`: `php artisan reverb:start` is not running.
- `Permission denied` in `laravel.log` or a 500 on upload: run `sudo deploy/local/install.sh` again (re-applies the permissions).
- Changed `backend/.env`: `php artisan config:clear` (never `config:cache` locally); Apache needs no reload.
- Changed `VITE_*`: `cd backend && npm run build`. Changed `NEXT_PUBLIC_*`: rebuild and restart the website.
