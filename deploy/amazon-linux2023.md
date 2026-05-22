# Amazon Linux 2023 — setup notes

## Fix httpd AH00526 (broken config)

If httpd will not start, remove the bad file and use the fixed one:

```bash
sudo rm -f /etc/httpd/conf.d/heic-converter-timeouts.conf
sudo tee /etc/httpd/conf.d/heic-converter-timeouts.conf <<'EOF'
Timeout 600
ProxyTimeout 600
EOF

sudo apachectl configtest
sudo systemctl restart php-fpm httpd
```

## PHP Imagick (optional — CLI tools are enough)

Amazon Linux 2023 has no `php-imagick` RPM. ImageMagick + libheif are already installed; the app uses `magick` / `heif-convert` CLI first.

Check CLI:

```bash
command -v magick convert heif-convert
```

Optional — install PHP Imagick via PECL:

```bash
php -v
sudo dnf install -y php-pear php-devel gcc make ImageMagick-devel libheif-devel
sudo pecl install imagick
echo "extension=imagick.so" | sudo tee /etc/php.d/40-imagick.ini
sudo systemctl restart php-fpm httpd
php -m | grep imagick
```

If `pecl install` fails, the app still works when `magick` or `heif-convert` is on PATH.

## Install heif-convert (libheif-tools)

`libheif` alone does **not** install the CLI. You need **libheif-tools**:

```bash
sudo dnf install -y libheif-tools ImageMagick

# Verify binaries exist
rpm -ql libheif-tools | grep bin
ls -la /usr/bin/heif-convert
sudo -u apache /usr/bin/heif-convert --version

sudo systemctl restart heic-worker httpd
curl -s 'https://heictojpg.eatherahmed.com/?health=1'
# expect "cli_heif": true
```

If `libheif-tools` is not found:

```bash
dnf search heif
```

Conversion will fall back to `magick` (needs `storage/tmp` — see below).

## No space left on device (ImageMagick /tmp)

Lightsail `/tmp` is often a **small RAM disk**. Large HEIC files fill it with `magick-*` cache files.

**On server:**

```bash
# Free stuck magick temp files
sudo rm -f /tmp/magick-*

# Use app storage for ImageMagick temp (after deploy)
mkdir -p /var/www/html/heic-converter/storage/tmp
sudo chown apache:apache /var/www/html/heic-converter/storage/tmp
df -h / /tmp /var/www/html

# Restart worker
sudo systemctl restart heic-worker
```

The app now sets `MAGICK_TMPDIR` to `storage/tmp` on disk and prefers **heif-convert** over **magick**.

## Permissions (converted: 0)

If uploads work but `converted: 0`, fix ownership — **web and worker must both be `apache`**:

```bash
cd /var/www/html/heic-converter
sudo bash deploy/fix-permissions.sh
sudo systemctl restart heic-worker httpd php-fpm
```

## Debug a stuck job

```bash
JOB_ID=your32charhexid
cat storage/jobs/$JOB_ID/meta.json
ls -la storage/jobs/$JOB_ID/incoming/
ls -la storage/jobs/$JOB_ID/output/
tail -20 storage/worker.log

# Test conversion as apache (same user as worker):
sudo -u apache /usr/bin/php worker.php --once
```

## Health check

```bash
curl -s 'https://heictojpg.eatherahmed.com/?health=1'
```

Expect `"ok": true`, `"cli_heif": true` or `"cli_magick": true`, and `"worker_alive": true`.
