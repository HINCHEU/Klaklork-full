#!/usr/bin/env bash
# =============================================================================
# setup-https.sh — one-time: host nginx + Let's Encrypt in front of Docker
# =============================================================================
# Run on the VPS from the repo root, AFTER `docker compose up -d` has moved the
# frontend off port 80 (FRONTEND_PORT=3000 in .env):
#   sudo bash deploy/setup-https.sh you@example.com
# The domain's A record must already point at this server.
# =============================================================================
set -euo pipefail

EMAIL=${1:?usage: sudo bash deploy/setup-https.sh <email-for-letsencrypt>}
DOMAIN=game.hincheu.online

apt-get update
apt-get install -y nginx certbot python3-certbot-nginx

cp deploy/nginx/klaklork.conf /etc/nginx/sites-available/klaklork.conf
ln -sf /etc/nginx/sites-available/klaklork.conf /etc/nginx/sites-enabled/klaklork.conf
rm -f /etc/nginx/sites-enabled/default

# Ubuntu ships worker_connections 768. Every player's WebSocket holds two (client
# + Reverb upstream), so 2 workers ran out at ~770 players and nginx dropped
# connections. Raise it, and the open-file limit it needs.
sed -i 's/^\(\s*worker_connections\).*/\1 4096;/' /etc/nginx/nginx.conf
grep -q '^worker_rlimit_nofile' /etc/nginx/nginx.conf \
    || sed -i '/^worker_processes/a worker_rlimit_nofile 8192;' /etc/nginx/nginx.conf
nginx -t
systemctl reload nginx

if ufw status | grep -q 'Status: active'; then ufw allow 'Nginx Full'; fi

# Adds the 443 server, the HTTP -> HTTPS 301 and HSTS; certbot's systemd timer renews.
certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --no-eff-email \
    --redirect --hsts --non-interactive

echo "==> https://$DOMAIN is live. Renewal check: certbot renew --dry-run"
