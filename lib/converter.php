<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/storage.php';

function cli_binary(string $name): ?string
{
    static $cache = [];
    if (isset($cache[$name])) {
        return $cache[$name];
    }

    $candidates = [
        '/usr/bin/' . $name,
        '/usr/local/bin/' . $name,
    ];

    foreach ($candidates as $path) {
        if (is_executable($path)) {
            return $cache[$name] = $path;
        }
    }

    $out = [];
    $pathEnv = getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
    exec('PATH=' . escapeshellarg($pathEnv) . ' command -v ' . escapeshellarg($name) . ' 2>/dev/null', $out, $code);
    if ($code === 0 && isset($out[0]) && $out[0] !== '' && is_executable($out[0])) {
        return $cache[$name] = $out[0];
    }

    return $cache[$name] = null;
}

function cli_heif_convert(): ?string
{
    foreach (['heif-convert', 'heif-decoder'] as $name) {
        $bin = cli_binary($name);
        if ($bin !== null) {
            return $bin;
        }
    }
    return null;
}

function converter_env_prefix(): string
{
    $tmp = setup_converter_environment();
    return 'TMPDIR=' . escapeshellarg($tmp)
        . ' MAGICK_TMPDIR=' . escapeshellarg($tmp)
        . ' MAGICK_TEMPORARY_PATH=' . escapeshellarg($tmp)
        . ' ';
}

function sanitize_filename(string $name): string
{
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = preg_replace('/[^\w\-\. ]+/u', '_', $base) ?? 'image';
    $base = trim($base, "._ \t\n\r\0\x0B");
    return ($base !== '' ? $base : 'image') . '.jpg';
}

function imagick_resource_limits(Imagick $imagick): void
{
    $imagick->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 48 * 1024 * 1024);
    $imagick->setResourceLimit(Imagick::RESOURCETYPE_MAP, 48 * 1024 * 1024);
    $imagick->setResourceLimit(Imagick::RESOURCETYPE_DISK, 512 * 1024 * 1024);
    $imagick->setResourceLimit(Imagick::RESOURCETYPE_AREA, 32 * 1024 * 1024);
    $imagick->setResourceLimit(Imagick::RESOURCETYPE_TIME, 300);
}

function shrink_if_oversized(Imagick $imagick): void
{
    $w = $imagick->getImageWidth();
    $h = $imagick->getImageHeight();
    if ($w > MAX_DIMENSION || $h > MAX_DIMENSION) {
        $imagick->resizeImage(MAX_DIMENSION, MAX_DIMENSION, Imagick::FILTER_TRIANGLE, 1, true);
    }
}

/**
 * Prefer heif-convert (lighter on disk); magick only as fallback.
 */
function convert_heic_with_cli(string $sourcePath, string $destPath): bool
{
    $env = converter_env_prefix();

    $heif = cli_heif_convert();
    if ($heif !== null) {
        $cmd = $env . sprintf(
            '%s %s %s --quality %d 2>&1',
            escapeshellarg($heif),
            escapeshellarg($sourcePath),
            escapeshellarg($destPath),
            JPEG_QUALITY
        );
        exec($cmd, $out, $code);
        if ($code === 0 && is_file($destPath)) {
            maybe_resize_jpeg_cli($destPath);
            return true;
        }
    }

    $magick = cli_binary('magick') ?? cli_binary('convert');
    if ($magick !== null) {
        $dim = (string) MAX_DIMENSION;
        $q = (string) JPEG_QUALITY;
        $cmd = $env . sprintf(
            '%s -limit memory 96MiB -limit map 96MiB -limit disk 512MiB %s -auto-orient -resize %sx%s> -quality %s -strip %s 2>&1',
            escapeshellarg($magick),
            escapeshellarg($sourcePath),
            $dim,
            $dim,
            escapeshellarg($q),
            escapeshellarg($destPath)
        );
        exec($cmd, $out, $code);
        return $code === 0 && is_file($destPath);
    }

    return false;
}

function maybe_resize_jpeg_cli(string $jpgPath): void
{
    $info = @getimagesize($jpgPath);
    if ($info === false) {
        return;
    }
    if ($info[0] <= MAX_DIMENSION && $info[1] <= MAX_DIMENSION) {
        return;
    }
    post_resize_jpeg_cli($jpgPath);
}

function post_resize_jpeg_cli(string $jpgPath): void
{
    $magick = cli_binary('magick') ?? cli_binary('convert');
    if ($magick === null) {
        return;
    }
    $tmp = $jpgPath . '.tmp.jpg';
    $dim = (string) MAX_DIMENSION;
    $cmd = converter_env_prefix() . sprintf(
        '%s %s -resize %sx%s> -quality %s -strip %s 2>&1',
        escapeshellarg($magick),
        escapeshellarg($jpgPath),
        $dim,
        $dim,
        (string) JPEG_QUALITY,
        escapeshellarg($tmp)
    );
    exec($cmd, $out, $code);
    if ($code === 0 && is_file($tmp)) {
        rename($tmp, $jpgPath);
    } elseif (is_file($tmp)) {
        @unlink($tmp);
    }
}

function convert_heic_to_jpg_file(string $sourcePath, string $destPath): void
{
    setup_converter_environment();
    cleanup_magick_temp();

    try {
        if (convert_heic_with_cli($sourcePath, $destPath)) {
            return;
        }

        if (!extension_loaded('imagick')) {
            throw new RuntimeException('No CLI converter succeeded and PHP imagick is not installed.');
        }

        $imagick = new Imagick();
        imagick_resource_limits($imagick);

        try {
            $imagick->readImage($sourcePath);
            $imagick->autoOrient();
            shrink_if_oversized($imagick);
            $imagick->setImageFormat('jpeg');
            if (USE_FULL_CHROMA) {
                $imagick->setSamplingFactors(['1x1', '1x1', '1x1']);
            }
            $imagick->setImageCompressionQuality(JPEG_QUALITY);
            $imagick->stripImage();
            $imagick->writeImage($destPath);
        } finally {
            $imagick->clear();
            $imagick->destroy();
            gc_collect_cycles();
        }
    } finally {
        cleanup_magick_temp();
    }
}
