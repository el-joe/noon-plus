#!/usr/bin/env bash
# Self-signed wildcard certificate for the local HTTPS vhosts (*.noon-plus.loc).
# Writes deploy/local/certs/noon-plus.loc.{crt,key} (git-ignored). No sudo needed.
# Local development only: never use this certificate anywhere else.
#
#   deploy/local/make-cert.sh           create if missing
#   deploy/local/make-cert.sh --force   replace
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/certs"
CRT="$DIR/noon-plus.loc.crt"
KEY="$DIR/noon-plus.loc.key"

if [[ -f "$CRT" && -f "$KEY" && "${1:-}" != "--force" ]]; then
    echo "Certificate already exists: $CRT (use --force to replace)"
    exit 0
fi

mkdir -p "$DIR"
chmod 700 "$DIR"

CONF="$(mktemp)"
trap 'rm -f "$CONF"' EXIT
cat > "$CONF" <<'CNF'
[req]
distinguished_name = dn
x509_extensions = ext
prompt = no

[dn]
CN = noon-plus.loc
O = Noon Plus local development

[ext]
subjectAltName = @alt
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth

[alt]
DNS.1 = noon-plus.loc
DNS.2 = *.noon-plus.loc
CNF

openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 825 \
    -keyout "$KEY" -out "$CRT" -config "$CONF"

chmod 600 "$KEY"
chmod 644 "$CRT"

echo "Created $CRT"
openssl x509 -in "$CRT" -noout -subject -enddate -ext subjectAltName
