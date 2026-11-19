#!/usr/bin/env bash
# Print the newest sign-in / verification code mailed to an address (dev: MAIL_MAILER=log).
#   deploy/local/last-code.sh e2e-admin@nawy.test
# Needs the queue worker running (php artisan horizon), because mail is queued.
set -euo pipefail
EMAIL="${1:?usage: $0 <email>}"
LOG="$(cd "$(dirname "$0")/../.." && pwd)/backend/storage/logs/laravel.log"
awk -v to="To: $EMAIL" '
  { sub(/\r$/, "") }
  $0 == to { mine = 1; next }
  /^To: / { mine = 0 }
  mine && /^Subject:/ && match($0, /[0-9]{6}/) { code = substr($0, RSTART, 6); mine = 0 }
  END { if (code) print code; else { print "no code found for this email yet (is php artisan horizon running?)" > "/dev/stderr"; exit 1 } }
' "$LOG"
