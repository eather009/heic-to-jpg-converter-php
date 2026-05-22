# High-Quality HEIC to JPG Batch Converter

A lightweight, web-based PHP utility that allows users to upload multiple HEIC (High Efficiency Image Container) files, converts them to high-quality JPEGs, and packages them into a single ZIP archive for easy download.

### URL
Try from [https://heictojpg.eatherahmed.com/](https://heictojpg.eatherahmed.com/)

## Features
- **Bulk Conversion:** Upload up to 50 HEIC files.
- **Background worker:** Conversion runs in `worker.php` (CLI), not inside httpd — keeps the site online on small VPS instances.
- **High quality JPEG:** Configurable quality and optional 4:4:4 chroma (`USE_FULL_CHROMA` in `config.php`).
- **CLI + Imagick:** Uses `magick` / `heif-convert` when available, PHP Imagick as fallback.
- **Clean UI:** Upload progress + polling until ZIP is ready.

### Worker setup (required for production)

See **`deploy/worker-setup.md`** — install the systemd service on your Lightsail box:

```bash
sudo chown -R apache:apache storage
sudo cp deploy/heic-worker.service /etc/systemd/system/
sudo systemctl enable --now heic-worker
```

## Prerequisites

To run this script, your server must have the following:

1. **PHP 7.4+**
2. **PHP-Imagick Extension:** This is the wrapper for ImageMagick.
3. **libheif:** ImageMagick must be compiled with `libheif` support to decode HEIC files.

### Installing Dependencies (Ubuntu/Debian)
```bash
sudo apt update
sudo apt install php-imagick libheif-examples
```

### Quick Start
1. Clone the repository:

```bash
git clone [https://github.com/eather009/heic-to-jpg-converter-php.git](https://github.com/eather009/heic-to-jpg-converter-php.git)
cd heic-to-jpg-converter-php
```

2. Configure PHP settings:

Since HEIC files are highly compressed, they expand significantly in memory during conversion. For small VPS instances (e.g. AWS Lightsail $5), use disk-based chunked uploads (built into `index.php`) instead of raising memory to 512M.

Recommended `php.ini` / FPM settings:
```ini
upload_max_filesize = 25M
post_max_size = 30M
max_file_uploads = 20
memory_limit = 256M
max_execution_time = 600
```

If you use **Apache httpd** + PHP-FPM (typical Lightsail LAMP), see `deploy/httpd-lightsail.conf` and `deploy/apache-timeouts.conf`.

Quick httpd fix for **504 Gateway Timeout**:

```bash
# 1) Global timeouts only (do not use <Proxy> ProxySet — causes AH00526 on AL2023)
sudo tee /etc/httpd/conf.d/heic-converter-timeouts.conf <<'EOF'
Timeout 600
ProxyTimeout 600
EOF

# 2) PHP-FPM pool
sudo sed -i 's/^;*request_terminate_timeout.*/request_terminate_timeout = 600/' /etc/php-fpm.d/www.conf

# 3) Restart
sudo apachectl configtest && sudo systemctl restart php-fpm httpd
```

Amazon Linux 2023: no `php-imagick` RPM — see `deploy/amazon-linux2023.md`. CLI `magick` / `heif-convert` is enough.

Nginx users: see `deploy/nginx-timeouts.conf` instead.

Up to **50 files** are processed **one file per request** to avoid gateway timeouts and OOM on low-RAM servers.

### 503 Service Unavailable

A plain HTML **503** page (not JSON from the app) usually means **PHP-FPM or Apache crashed** or is not running — often after the server runs out of RAM during conversion.

**On the server (SSH):**

```bash
# Check if PHP-FPM is running (Ubuntu/Debian)
sudo systemctl status php*-fpm
sudo systemctl restart php*-fpm
sudo systemctl restart httpd     # Amazon Linux / RHEL
# sudo systemctl restart apache2   # Ubuntu/Debian

# Check OOM kills
sudo dmesg | tail -30 | grep -i kill
```

**Health check** (after deploy): open `https://your-domain/?health=1` — you should see JSON with `"ok": true` and `"imagick": true`.

**Required limits** (must be >= chunk upload size: 3 files × 12 MB ≈ 36 MB):

```ini
post_max_size = 40M
upload_max_filesize = 12M
LimitRequestBody 20971520   # httpd (~20 MB); nginx: client_max_body_size 20M
```

If 503 persists, lower load further in `index.php`: set `CHUNK_SIZE` to `2` or `1`.

### 504 Gateway Timeout

A plain HTML **504** means **httpd gave up waiting** for PHP (default is often **60 seconds**). Conversion was still running.

The app uploads **one HEIC per request** so each call should finish faster. You must still raise **httpd + php-fpm** timeouts — see `deploy/apache-timeouts.conf` and `deploy/httpd-lightsail.conf`.

**Apache httpd (your setup):**

```bash
sudo nano /etc/httpd/conf.d/heic-converter-timeouts.conf
# paste contents from deploy/apache-timeouts.conf

sudo nano /etc/php-fpm.d/www.conf
# set: request_terminate_timeout = 600

sudo apachectl configtest
sudo systemctl restart php-fpm httpd
```

**Nginx** (if you switch later): `deploy/nginx-timeouts.conf`

**CLI converters (Amazon Linux 2023 — you already have these RPMs):**

```bash
command -v magick convert heif-convert
curl -s 'https://your-domain/?health=1'
```

`?health=1` should show `"ok": true` and `"cli_magick": true` or `"cli_heif": true`. PHP `imagick` extension is optional.

3. Run it:
```bash
php -S localhost:8000
```

### Why this converter is better
Standard converters often use 4:2:0 Chroma Subsampling, which discards half of the color information to save space. This tool uses:

- Sampling Factors (1x1, 1x1, 1x1): Preserves full color resolution (4:4:4).
- Imagick Engine: Handles the complex ICC color profiles (like Apple's Display P3) more accurately than standard GD libraries.

### License
Distributed under the MIT License. See LICENSE for more information.

