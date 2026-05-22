<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

function ensure_storage(): void
{
    foreach (['', 'jobs', 'tmp'] as $dir) {
        $path = storage_path($dir);
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create storage: ' . $path);
        }
        @chmod($path, 0775);
    }
}

/** Disk-backed temp for ImageMagick (avoid small RAM-backed /tmp on Lightsail). */
function magick_temp_dir(): string
{
    ensure_storage();
    return storage_path('tmp');
}

function setup_converter_environment(): string
{
    $tmp = magick_temp_dir();
    putenv('TMPDIR=' . $tmp);
    putenv('MAGICK_TMPDIR=' . $tmp);
    putenv('MAGICK_TEMPORARY_PATH=' . $tmp);
    if (extension_loaded('imagick')) {
        Imagick::setRegistry('temporary-path', $tmp);
    }
    return $tmp;
}

function cleanup_magick_temp(): void
{
    $dirs = [magick_temp_dir(), '/tmp'];
    foreach ($dirs as $dir) {
        if (!is_dir($dir) || !is_readable($dir)) {
            continue;
        }
        foreach (glob($dir . '/magick-*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}

function storage_is_writable(): bool
{
    try {
        ensure_storage();
        $test = storage_path('.write_test_' . getmypid());
        if (file_put_contents($test, 'ok') === false) {
            return false;
        }
        unlink($test);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function worker_trigger_path(): string
{
    return storage_path('worker.trigger');
}

function ping_worker(): void
{
    ensure_storage();
    touch(worker_trigger_path());
}
