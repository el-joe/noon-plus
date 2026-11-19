#!/usr/bin/env bash
# Install the local Apache vhosts for Noon Plus (*.noon-plus.loc).
#
#   sudo deploy/local/install.sh          http only (port 80)
#   sudo deploy/local/install.sh --ssl    http + https (port 443, self-signed wildcard cert)
#
# What it does (and nothing else):
#   1. copies noon-plus.loc.conf (+ noon-plus.loc-ssl.conf) to /etc/apache2/sites-available/
#   2. a2enmod proxy proxy_http proxy_wstunnel rewrite headers php8.3 (+ ssl), a2ensite ours
#   3. appends the hosts.txt entries to /etc/hosts, only those not present yet
#   4. lets www-data and the repo owner both write backend/storage and
#      backend/bootstrap/cache (group www-data + ACLs; nothing becomes world-writable)
#   5. apache2ctl configtest, then reloads Apache (only if the test passes)
# Other sites' Apache files are never touched. Safe to run again.
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Run with sudo: sudo $0 $*" >&2
    exit 1
fi

SSL=0
for arg in "$@"; do
    case "$arg" in
        --ssl) SSL=1 ;;
        -h|--help) sed -n '2,16p' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
BACKEND="$REPO/backend"
OWNER="${SUDO_USER:-$(stat -c %U "$BACKEND")}"
WEB_USER="www-data"
WEB_GROUP="www-data"

echo "==> Repo: $REPO (owner: $OWNER)"

# --- 1. vhost files ---------------------------------------------------------
if [[ $SSL -eq 1 && ! -f "$HERE/certs/noon-plus.loc.crt" ]]; then
    echo "==> Creating the self-signed certificate (as $OWNER)"
    sudo -u "$OWNER" "$HERE/make-cert.sh"
fi

install -m 644 "$HERE/noon-plus.loc.conf" /etc/apache2/sites-available/noon-plus.loc.conf
echo "==> Installed /etc/apache2/sites-available/noon-plus.loc.conf"
if [[ $SSL -eq 1 ]]; then
    install -m 644 "$HERE/noon-plus.loc-ssl.conf" /etc/apache2/sites-available/noon-plus.loc-ssl.conf
    echo "==> Installed /etc/apache2/sites-available/noon-plus.loc-ssl.conf"
fi

# --- 2. modules and sites ---------------------------------------------------
MODS=(proxy proxy_http proxy_wstunnel rewrite headers)
[[ -f /etc/apache2/mods-available/php8.3.load ]] && MODS+=(php8.3)
[[ $SSL -eq 1 ]] && MODS+=(ssl)
a2enmod -q "${MODS[@]}"
a2ensite -q noon-plus.loc
[[ $SSL -eq 1 ]] && a2ensite -q noon-plus.loc-ssl

# --- 3. /etc/hosts ----------------------------------------------------------
ADDED=0
while read -r ip host _; do
    [[ -z "${ip:-}" || "$ip" == \#* ]] && continue
    if ! grep -Eq "^[^#]*[[:space:]]${host//./\\.}([[:space:]]|$)" /etc/hosts; then
        [[ $ADDED -eq 0 ]] && printf '\n# noon-plus local (deploy/local/hosts.txt)\n' >> /etc/hosts
        printf '%s %s\n' "$ip" "$host" >> /etc/hosts
        ADDED=$((ADDED + 1))
    fi
done < "$HERE/hosts.txt"
echo "==> /etc/hosts: $ADDED entr$( [[ $ADDED -eq 1 ]] && echo y || echo ies ) added"

# --- 4. writable storage for www-data and the owner -------------------------
echo "==> Storage permissions (group $WEB_GROUP, ACLs for $WEB_USER and $OWNER)"
for dir in "$BACKEND/storage" "$BACKEND/bootstrap/cache"; do
    chgrp -R "$WEB_GROUP" "$dir"
    find "$dir" -type d -exec chmod u+rwx,g+rwxs {} +
    find "$dir" -type f -exec chmod u+rw,g+rw {} +
    find "$dir" ! -type l -perm -o+w -exec chmod o-w {} +
    if command -v setfacl >/dev/null 2>&1; then
        setfacl -R -m "u:$WEB_USER:rwX,g:$WEB_GROUP:rwX,u:$OWNER:rwX" "$dir"
        setfacl -R -d -m "u:$WEB_USER:rwX,g:$WEB_GROUP:rwX,u:$OWNER:rwX" "$dir"
    else
        echo "    setfacl not found (apt install acl): only group permissions were set" >&2
    fi
done
# Private uploads (documents, contracts, proofs) stay closed to other users.
if command -v setfacl >/dev/null 2>&1 && [[ -d "$BACKEND/storage/app/private" ]]; then
    setfacl -R -d -m o::--- "$BACKEND/storage/app/private"
fi

# --- 5. test and reload -----------------------------------------------------
echo "==> apache2ctl configtest"
apache2ctl configtest
systemctl reload apache2
echo "==> Apache reloaded"

cat <<EOF

Done. Open:
  http://noon-plus.loc                 storefront
  http://business.noon-plus.loc        partner portal
  http://admin.noon-plus.loc           admin panel (also partner. travel-agency. carrier. delivery. marketer.)
  http://api.noon-plus.loc/up          API health
  ws://ws.noon-plus.loc/app/{key}      Reverb (needs: php artisan reverb:start)
EOF
if [[ $SSL -eq 1 ]]; then
    echo "  https://… the same hosts on 443 (trust the certificate: deploy/local/README.md)"
fi
echo "Next.js must be running on 127.0.0.1:3000 (next start) for the storefront and portal."
