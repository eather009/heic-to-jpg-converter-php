#!/usr/bin/env php
<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/lib/worker_process.php';

ini_set('memory_limit', '192M');
set_time_limit(0);

$once = in_array('--once', $argv ?? [], true);

touch_worker_heartbeat();
cleanup_expired_jobs(20);

do {
    if (is_file(worker_trigger_path())) {
        @unlink(worker_trigger_path());
    }

    $jobId = find_next_queued_job();
    if ($jobId !== null) {
        try {
            process_job($jobId);
        } catch (Throwable $e) {
            try {
                $meta = read_job_meta($jobId);
                $meta['status'] = 'failed';
                $meta['errors'][] = $e->getMessage();
                write_job_meta($jobId, $meta);
            } catch (Throwable) {
                // ignore
            }
        }
        touch_worker_heartbeat();
        cleanup_expired_jobs(5);
    }

    if ($once) {
        break;
    }

    sleep(is_file(worker_trigger_path()) ? 1 : WORKER_SLEEP_SECONDS);
    touch_worker_heartbeat();
} while (true);
