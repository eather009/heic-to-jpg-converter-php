# Background worker setup (Amazon Linux 2023 / httpd)

The web app only **uploads** files. **worker.php** converts HEIC → JPG and builds the ZIP so php-fpm stays light.

## 1. Permissions

```bash
# Your app path (adjust if different):
APP=/var/www/html/heic-converter

sudo bash deploy/fix-permissions.sh "$APP"
```

## 2. Install systemd service

Edit `deploy/heic-worker.service` — set correct `WorkingDirectory`.

```bash
sudo cp deploy/heic-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now heic-worker
sudo systemctl status heic-worker
```

## 3. Verify

```bash
curl -s 'https://heictojpg.eatherahmed.com/?health=1'
```

Expect:

```json
{"ok":true,"storage_writable":true,"worker_alive":true,...}
```

If `"worker_alive": false`, uploads return **503** with a clear JSON message (not a crash).

## 4. Logs

```bash
sudo journalctl -u heic-worker -f
```

## Fallback (no systemd)

Cron every minute:

```bash
* * * * * apache /usr/bin/php /var/www/html/heic-to-jpg-converter-php/worker.php --once
```

`job_submit` also runs `worker.php --once` in the background as a backup wake-up.

## httpd timeouts

Upload requests are short; you can keep `Timeout 600` for large ZIP downloads. Conversion no longer runs inside httpd.
