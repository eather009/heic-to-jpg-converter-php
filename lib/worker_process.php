<?php

declare(strict_types=1);

require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/converter.php';

function touch_worker_heartbeat(): void
{
    ensure_storage();
    file_put_contents(worker_heartbeat_path(), (string) time(), LOCK_EX);
}

function worker_log(string $message): void
{
    ensure_storage();
    $line = date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
    file_put_contents(storage_path('worker.log'), $line, FILE_APPEND | LOCK_EX);
}

function build_result_zip(string $jobId): string
{
    $zipPath = job_dir($jobId) . '/result.zip';
    if (is_file($zipPath)) {
        @unlink($zipPath);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create ZIP.');
    }

    foreach (glob(job_dir($jobId) . '/output/*.jpg') ?: [] as $jpg) {
        $zip->addFile($jpg, basename($jpg));
    }
    $zip->close();

    return $zipPath;
}

function process_job(string $jobId): void
{
    $lockPath = job_dir($jobId) . '/worker.lock';
    $lockFp = fopen($lockPath, 'c');
    if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
        if ($lockFp !== false) {
            fclose($lockFp);
        }
        return;
    }

    try {
        process_job_locked($jobId);
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}

function process_job_locked(string $jobId): void
{
    $meta = read_job_meta($jobId);
    if (($meta['status'] ?? '') !== 'queued') {
        return;
    }

    worker_log('Processing job ' . $jobId);

    $meta['status'] = 'processing';
    $meta['converted'] = 0;
    $meta['errors'] = [];
    write_job_meta($jobId, $meta);

    $incomingDir = job_dir($jobId) . '/incoming';
    $outputDir = job_dir($jobId) . '/output';

    if (!is_readable($incomingDir) || !is_writable($outputDir)) {
        $meta['status'] = 'failed';
        $meta['errors'][] = 'Permission denied on job folders (incoming/output). Run: sudo chown -R apache:apache storage';
        write_job_meta($jobId, $meta);
        worker_log('Job ' . $jobId . ' failed: directory permissions');
        return;
    }

    $incoming = glob($incomingDir . '/*') ?: [];
    natsort($incoming);
    $index = 0;

    if ($incoming === []) {
        $meta['status'] = 'failed';
        $meta['errors'][] = 'No files found in incoming/';
        write_job_meta($jobId, $meta);
        worker_log('Job ' . $jobId . ' failed: no incoming files');
        return;
    }

    foreach ($incoming as $heicPath) {
        if (!is_file($heicPath) || !is_readable($heicPath)) {
            $meta['errors'][] = basename($heicPath) . ': not readable';
            write_job_meta($jobId, $meta);
            continue;
        }
        $index++;
        $jpgName = sprintf('%04d_%s', $index, sanitize_filename(basename($heicPath)));
        $jpgPath = $outputDir . '/' . $jpgName;

        try {
            convert_heic_to_jpg_file($heicPath, $jpgPath);
            if (!is_file($jpgPath)) {
                throw new RuntimeException('Output JPG was not created.');
            }
            $meta['converted'] = (int) $meta['converted'] + 1;
            worker_log('Job ' . $jobId . ' converted ' . basename($heicPath));
        } catch (Throwable $e) {
            $msg = basename($heicPath) . ': ' . $e->getMessage();
            $meta['errors'][] = $msg;
            worker_log('Job ' . $jobId . ' error: ' . $msg);
        }
        write_job_meta($jobId, $meta);
        gc_collect_cycles();
    }

    $meta = read_job_meta($jobId);
    if ((int) ($meta['converted'] ?? 0) < 1) {
        $meta['status'] = 'failed';
        if ($meta['errors'] === []) {
            $meta['errors'][] = 'Conversion failed. Check storage/worker.log and install heif-convert or imagick.';
        }
        write_job_meta($jobId, $meta);
        worker_log('Job ' . $jobId . ' failed with 0 conversions');
        return;
    }

    try {
        build_result_zip($jobId);
        $meta['status'] = 'completed';
    } catch (Throwable $e) {
        $meta['status'] = 'failed';
        $meta['errors'][] = 'ZIP: ' . $e->getMessage();
    }

    $meta['expires'] = time() + JOB_TTL_SECONDS;
    write_job_meta($jobId, $meta);
}
