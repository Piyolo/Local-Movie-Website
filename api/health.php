<?php
/** Health/status endpoint — environment checks without leaking paths. */
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$lib = library_status();
$dataOk = is_dir(DIR_DATA) && is_writable(DIR_DATA);

json_response([
    'ok'         => $lib['available'] && $dataOk,
    'php'        => PHP_VERSION,
    'time'       => date('c'),
    'library'    => ['available' => $lib['available'], 'source' => $lib['source']], // no path — health is not admin-gated
    'data_writable' => $dataOk,
    'metadata'   => ['configured' => tmdb_configured()],
], ($lib['available'] && $dataOk) ? 200 : 503);
