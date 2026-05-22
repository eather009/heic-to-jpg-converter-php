<?php

declare(strict_types=1);

const MAX_FILES = 50;
const MAX_FILE_BYTES = 12 * 1024 * 1024;
const JPEG_QUALITY = 88;
const UPLOAD_CHUNK_SIZE = 1;
const MAX_DIMENSION = 3000;
const USE_FULL_CHROMA = false;
const JOB_TTL_SECONDS = 3600;
const WORKER_SLEEP_SECONDS = 2;

function project_root(): string
{
    return __DIR__;
}

function storage_path(string $sub = ''): string
{
    $base = project_root() . '/storage';
    return $sub !== '' ? $base . '/' . ltrim($sub, '/') : $base;
}

