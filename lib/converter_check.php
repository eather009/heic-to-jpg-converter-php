<?php

declare(strict_types=1);

require_once __DIR__ . '/converter.php';

function converter_available(): bool
{
    if (extension_loaded('imagick')) {
        return true;
    }
    return cli_binary('magick') !== null
        || cli_binary('convert') !== null
        || cli_heif_convert() !== null;
}
