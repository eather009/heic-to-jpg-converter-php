<?php

declare(strict_types=1);

require_once __DIR__ . '/storage.php';

function valid_job_id(string $id): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}$/', $id);
}

function job_dir(string $jobId): string
{
    if (!valid_job_id($jobId)) {
        throw new InvalidArgumentException('Invalid job id.');
    }
    return storage_path('jobs/' . $jobId);
}

/** @return array<string, mixed> */
function read_job_meta(string $jobId): array
{
    $path = job_dir($jobId) . '/meta.json';
    if (!is_file($path)) {
        throw new RuntimeException('Job not found.');
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException('Corrupt job metadata.');
    }
    return $data;
}

/** @param array<string, mixed> $meta */
function write_job_meta(string $jobId, array $meta): void
{
    $meta['updated'] = time();
    file_put_contents(job_dir($jobId) . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
}

function create_job(): string
{
    if (!storage_is_writable()) {
        throw new RuntimeException('Storage is not writable. Run: sudo chown -R apache:apache storage');
    }

    $jobId = bin2hex(random_bytes(16));
    $dir = job_dir($jobId);

    foreach (['incoming', 'output'] as $sub) {
        $subDir = $dir . '/' . $sub;
        if (!is_dir($subDir) && !mkdir($subDir, 0775, true) && !is_dir($subDir)) {
            throw new RuntimeException('Cannot create job directory.');
        }
        @chmod($subDir, 0775);
    }

    write_job_meta($jobId, [
        'id' => $jobId,
        'status' => 'uploading',
        'created' => time(),
        'expires' => time() + JOB_TTL_SECONDS,
        'uploaded' => 0,
        'converted' => 0,
        'errors' => [],
    ]);

    return $jobId;
}

function delete_job(string $jobId): void
{
    if (!valid_job_id($jobId)) {
        return;
    }
    $dir = job_dir($jobId);
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $entry) {
        if (is_file($entry)) {
            @unlink($entry);
        } elseif (is_dir($entry)) {
            foreach (glob($entry . '/*') ?: [] as $sub) {
                if (is_file($sub)) {
                    @unlink($sub);
                }
            }
            @rmdir($entry);
        }
    }
    @rmdir($dir);
}

function cleanup_expired_jobs(int $limit = 10): void
{
    $jobsRoot = storage_path('jobs');
    if (!is_dir($jobsRoot)) {
        return;
    }
    $removed = 0;
    foreach (glob($jobsRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if ($removed >= $limit) {
            break;
        }
        $jobId = basename($dir);
        if (!valid_job_id($jobId)) {
            continue;
        }
        try {
            $meta = read_job_meta($jobId);
            if (($meta['expires'] ?? 0) < time()) {
                delete_job($jobId);
                $removed++;
            }
        } catch (Throwable) {
            delete_job($jobId);
            $removed++;
        }
    }
}

/**
 * @param array{name: string, tmp_name: string, error: int, size: int} $file
 */
function save_uploaded_file(string $jobId, array $file): void
{
    $meta = read_job_meta($jobId);
    if (($meta['status'] ?? '') !== 'uploading') {
        throw new RuntimeException('Job is not accepting uploads.');
    }
    if (($meta['uploaded'] ?? 0) >= MAX_FILES) {
        throw new RuntimeException('Maximum ' . MAX_FILES . ' files per job.');
    }

    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $hint = match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => ' (file too large for server limit)',
            UPLOAD_ERR_PARTIAL => ' (partial upload)',
            UPLOAD_ERR_NO_FILE => ' (no file)',
            default => '',
        };
        throw new RuntimeException('Upload failed for ' . ($file['name'] ?? 'file') . $hint);
    }

    if (($file['size'] ?? 0) > MAX_FILE_BYTES) {
        throw new RuntimeException(($file['name'] ?? 'file') . ' exceeds ' . (MAX_FILE_BYTES / 1024 / 1024) . 'MB limit.');
    }

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'heic') {
        throw new RuntimeException('Only HEIC files are allowed.');
    }

    $tmp = (string) $file['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
    }

    $index = (int) $meta['uploaded'] + 1;
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string) $file['name'])) ?? 'file.heic';
    $dest = job_dir($jobId) . '/incoming/' . sprintf('%04d_%s', $index, $safeName);

    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not save file. Check storage permissions.');
    }

    $meta['uploaded'] = $index;
    $meta['expires'] = time() + JOB_TTL_SECONDS;
    write_job_meta($jobId, $meta);
}

function queue_job(string $jobId): void
{
    $meta = read_job_meta($jobId);
    if (($meta['uploaded'] ?? 0) < 1) {
        throw new RuntimeException('No files uploaded.');
    }
    $meta['status'] = 'queued';
    $meta['expires'] = time() + JOB_TTL_SECONDS;
    write_job_meta($jobId, $meta);
    ping_worker();
}

function worker_heartbeat_path(): string
{
    return storage_path('worker.heartbeat');
}

function worker_is_recently_alive(int $maxAgeSeconds = 60): bool
{
    $path = worker_heartbeat_path();
    if (!is_file($path)) {
        return false;
    }
    $ts = (int) trim((string) file_get_contents($path));
    return $ts > 0 && (time() - $ts) <= $maxAgeSeconds;
}

function find_next_queued_job(): ?string
{
    $jobsRoot = storage_path('jobs');
    if (!is_dir($jobsRoot)) {
        return null;
    }
    $candidates = [];
    foreach (glob($jobsRoot . '/*/meta.json') ?: [] as $metaFile) {
        $jobId = basename(dirname($metaFile));
        if (!valid_job_id($jobId)) {
            continue;
        }
        try {
            $meta = read_job_meta($jobId);
            if (($meta['status'] ?? '') === 'queued') {
                $candidates[$jobId] = (int) ($meta['created'] ?? 0);
            }
        } catch (Throwable) {
            continue;
        }
    }
    if ($candidates === []) {
        return null;
    }
    asort($candidates);
    return array_key_first($candidates);
}

/** @return array<string, mixed> */
function job_status_payload(string $jobId): array
{
    $meta = read_job_meta($jobId);
    $total = (int) ($meta['uploaded'] ?? 0);
    $done = (int) ($meta['converted'] ?? 0);
    $status = (string) ($meta['status'] ?? 'unknown');

    return [
        'job_id' => $jobId,
        'status' => $status,
        'uploaded' => $total,
        'converted' => $done,
        'total' => $total,
        'errors' => $meta['errors'] ?? [],
        'progress' => $total > 0 ? min(100, (int) round(($done / $total) * 100)) : 0,
        'worker_alive' => worker_is_recently_alive(90),
    ];
}
