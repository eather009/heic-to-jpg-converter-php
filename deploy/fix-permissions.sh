#!/bin/bash
# Run on Lightsail as root. Adjust APP_DIR if needed.
set -euo pipefail

APP_DIR="${1:-/var/www/html/heic-converter}"

echo "Fixing permissions in: $APP_DIR"

if [[ ! -d "$APP_DIR" ]]; then
  echo "Directory not found: $APP_DIR"
  exit 1
fi

# Web + worker run as apache on Amazon Linux httpd
chown -R apache:apache "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 775 {} \;
find "$APP_DIR" -type f -exec chmod 664 {} \;
chmod 775 "$APP_DIR/worker.php" 2>/dev/null || true

# Storage must be writable by apache
mkdir -p "$APP_DIR/storage/jobs"
chown -R apache:apache "$APP_DIR/storage"
chmod 775 "$APP_DIR/storage"
chmod 775 "$APP_DIR/storage/jobs" 2>/dev/null || true

echo "Done. Verify as apache:"
echo "  sudo -u apache test -w $APP_DIR/storage && echo storage OK"
echo "  sudo -u apache php $APP_DIR/worker.php --once"
