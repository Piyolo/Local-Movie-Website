<?php
/**
 * Native Windows folder picker — host-only helper.
 *
 * Runs tools/folder_picker.ps1, a FIXED command that takes no arguments
 * (no request data ever reaches the command line), and reads the chosen
 * path back from stdout as JSON. The caller (api/library.php) additionally
 * requires an authenticated admin session, a valid CSRF token, and that the
 * request originates from the host machine itself — a phone or TV on the
 * LAN can never pop this dialog. The returned path still goes through the
 * same canonicalization / deny-list / existence validation as every other
 * way of selecting the library folder.
 */
declare(strict_types=1);

/**
 * Does this HTTP request come from the machine running Apache (the host)?
 * True for loopback, or for the server reaching itself via its own LAN IP.
 *
 * Pure function (injected values allowed) so it is unit-testable.
 */
function picker_request_is_host(?string $remote = null, ?string $serverAddr = null): bool
{
    $remote    = $remote    ?? (string)($_SERVER['REMOTE_ADDR']    ?? '');
    $serverAddr = $serverAddr ?? (string)($_SERVER['SERVER_ADDR'] ?? '');

    if ($remote === '127.0.0.1' || $remote === '::1') {
        return true;
    }
    // A request to this machine's own address comes from this machine.
    return $serverAddr !== '' && $remote === $serverAddr;
}

/**
 * Open the Windows folder dialog and return the user's pick.
 *
 * @param int $timeoutSeconds how long to wait for the user before giving up
 * @return array{ok:bool, path:string, cancelled:bool, code:string, message:string}
 */
function native_folder_picker(int $timeoutSeconds = 120): array
{
    $fail = static function (string $code, string $message): array {
        return ['ok' => false, 'path' => '', 'cancelled' => false, 'code' => $code, 'message' => $message];
    };

    $root = dirname(__DIR__);
    $script = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'folder_picker.ps1';
    if (!is_file($script)) {
        return $fail('picker_missing', 'The folder-picker script is missing from tools/.');
    }

    // Array form of proc_open → no shell, no escaping pitfalls (PHP 7.4+/8.x).
    // Fixed command; the request contributes nothing here.
    $cmd = [
        'powershell.exe',
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-STA',
        '-File', $script,
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open($cmd, $descriptors, $pipes, $root);
    if (!is_resource($proc)) {
        return $fail('picker_spawn_failed', 'Could not start the Windows folder dialog.');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    set_time_limit($timeoutSeconds + 30);
    ignore_user_abort(true);

    $stdout = '';
    $stderr = '';
    $start  = time();
    $timedOut = false;

    while (true) {
        $status = proc_get_status($proc);
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        if (!$status['running']) {
            break;
        }
        if (time() - $start >= $timeoutSeconds) {
            $timedOut = true;
            @proc_terminate($proc);          // closes the dialog with the process
            usleep(300000);
            $again = proc_get_status($proc);
            if ($again['running']) {
                @proc_terminate($proc, 9);
            }
            break;
        }
        usleep(200000);
    }

    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    if ($timedOut) {
        return $fail(
            'picker_timeout',
            'The Windows folder dialog did not respond within ' . $timeoutSeconds . ' seconds. Use the in-site folder browser instead.'
        );
    }

    $data = json_decode(trim($stdout), true);
    if (!is_array($data)) {
        $detail = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
        $detail = preg_replace('/\s+/', ' ', $detail) ?? '';
        return $fail('picker_no_output', 'The folder dialog returned no result.'
            . ($detail !== '' ? ' (' . mb_substr($detail, 0, 160) . ')' : ''));
    }
    if (isset($data['error'])) {
        return $fail('picker_failed', 'The folder dialog failed: ' . (string)$data['error']);
    }

    $path = (string)($data['path'] ?? '');
    if (!empty($data['cancelled']) || $path === '') {
        return ['ok' => false, 'path' => '', 'cancelled' => true, 'code' => 'cancelled', 'message' => 'No folder selected.'];
    }

    // Light sanity check here; full policy validation (deny-list, canonical
    // form, writability) still happens in set_movies_dir() before saving.
    if (!is_dir($path)) {
        return $fail('not_a_directory', 'That selection is not a folder.');
    }
    if (!is_readable($path)) {
        return $fail('unreadable', 'The selected folder cannot be read.');
    }

    return ['ok' => true, 'path' => $path, 'cancelled' => false, 'code' => '', 'message' => ''];
}
