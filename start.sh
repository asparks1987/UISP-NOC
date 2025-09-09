#!/usr/bin/env bash
set -euo pipefail

# Ensure cache paths for gotify
mkdir -p /var/www/html/cache/gotify

# Start Gotify server in background if present
if command -v gotify >/dev/null 2>&1; then
  echo "[start] Launching embedded Gotify server on :18080"
  (
    # Pass through default user env if provided by compose
    export GOTIFY_DEFAULTUSER_NAME="${GOTIFY_DEFAULTUSER_NAME:-}"
    export GOTIFY_DEFAULTUSER_PASS="${GOTIFY_DEFAULTUSER_PASS:-}"
    exec gotify --config /etc/gotify/config.yml
  ) >/var/log/gotify.log 2>&1 &
else
  echo "[start] Gotify binary not found; skipping embedded notifications server"
fi

# Launch Apache in foreground
echo "[start] Launching Apache"
exec apache2-foreground

