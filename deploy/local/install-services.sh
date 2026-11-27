#!/usr/bin/env bash
# Install the background services as systemd *user* services (no sudo).
# They start when you log in and restart automatically if they stop.
#   status:  systemctl --user status 'growvia-*'
#   logs:    journalctl --user -u growvia-queue -f
#   restart: systemctl --user restart growvia-queue growvia-scheduler growvia-crawler
set -euo pipefail

PROJECT="$(cd "$(dirname "$0")/../.." && pwd)"
TARGET="$HOME/.config/systemd/user"
mkdir -p "$TARGET"

if pgrep -f "artisan schedule:work" >/dev/null && ! systemctl --user is-active --quiet growvia-scheduler; then
    echo "Stop 'composer dev' first (Ctrl+C in its terminal), then run this again."
    exit 1
fi

for unit in growvia-queue growvia-scheduler growvia-crawler; do
    sed "s#__PROJECT__#${PROJECT}#g" "$PROJECT/deploy/local/systemd/${unit}.service" > "$TARGET/${unit}.service"
done

systemctl --user daemon-reload
systemctl --user enable --now growvia-queue growvia-scheduler growvia-crawler
# Optional: keep them running even when logged out (may ask for your password).
loginctl enable-linger "$USER" 2>/dev/null || true

systemctl --user --no-pager --lines=0 status growvia-queue growvia-scheduler growvia-crawler | grep -E "●|Active:"
