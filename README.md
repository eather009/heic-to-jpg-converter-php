# High-Quality HEIC to JPG Batch Converter

A lightweight, web-based PHP utility that allows users to upload multiple HEIC (High Efficiency Image Container) files, converts them to high-quality JPEGs, and packages them into a single ZIP archive for easy download.

### URL
Try from [https://heictojpg.eatherahmed.com/](https://heictojpg.eatherahmed.com/)

## Features
- **Bulk Conversion:** Upload multiple files at once.
- **Maximum Quality:** Specifically configured to bypass standard compression loss:
  - Disables chroma subsampling (`1x1` sampling factors).
  - Sets JPEG compression quality to `95-100`.
- **In-Memory Processing:** Processes images efficiently and bundles them into a ZIP on the fly.
- **Clean UI:** Simple, responsive interface for ease of use.

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

Since HEIC files are highly compressed, they expand significantly in memory during conversion. Ensure your php.ini allows for large uploads:
```ini
upload_max_filesize = 100M
post_max_size = 110M
memory_limit = 512M
```

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

