#!/usr/bin/env bash
# One-time local setup for http://growvia-seo.test (run with sudo).
set -euo pipefail

HOST=growvia-seo.test
IP=127.0.1.6
DIR="$(cd "$(dirname "$0")" && pwd)"

[[ $EUID -eq 0 ]] || { echo "Run with sudo: sudo $0"; exit 1; }

cp "$DIR/$HOST.conf" "/etc/apache2/sites-available/$HOST.conf"
a2enmod rewrite >/dev/null
a2ensite "$HOST.conf" >/dev/null

if ! grep -qE "[[:space:]]$HOST(\s|$)" /etc/hosts; then
    printf '%s\t%s\n' "$IP" "$HOST" >> /etc/hosts
fi

apache2ctl configtest
systemctl reload apache2
echo "Ready: http://$HOST"
