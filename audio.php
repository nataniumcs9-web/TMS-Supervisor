<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Audio playback requires a GET request.');
}
if (empty($_SESSION['username'])) {
    http_response_code(401);
    exit('Sign in to access this recording.');
}

try {
    $pdo = database();
    if (!currentSupervisor($pdo)) {
        http_response_code(403);
        exit('Active Supervisor access is required.');
    }
    session_write_close();

    $recordId = filter_var($_GET['record_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$recordId || $recordId < 1) {
        http_response_code(400);
        exit('A valid recording is required.');
    }

    $record = $pdo->prepare(
        "SELECT audio FROM records
         WHERE id = ?
           AND LOWER(TRIM(status)) IN ('uploaded', 'assigned', 'pending', 'finished', 'suspended')
         LIMIT 1"
    );
    $record->execute([$recordId]);
    $storedAudio = $record->fetchColumn();
    if (!is_string($storedAudio) || $storedAudio === '') {
        http_response_code(404);
        exit('This recording is not available for playback.');
    }
    $relativePath = str_replace('\\', '/', $storedAudio);
    $pathSegments = explode('/', $relativePath);
    if (
        str_starts_with($relativePath, '/')
        || preg_match('/^[A-Za-z]:/', $relativePath)
        || array_filter($pathSegments, static fn(string $segment): bool =>
            $segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")
        )
    ) {
        http_response_code(404);
        exit('The database audio path is invalid.');
    }

    $root = configuredAudioRoot();
    if ($root === false) {
        http_response_code(503);
        exit('Protected audio storage is not configured on the PHP server.');
    }

    $candidate = $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $pathSegments);
    $resolved = realpath($candidate);
    $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $pathComparison = PHP_OS_FAMILY === 'Windows' ? 'strncasecmp' : 'strncmp';
    if (
        $resolved === false
        || !is_file($resolved)
        || $pathComparison($resolved, $rootPrefix, strlen($rootPrefix)) !== 0
    ) {
        http_response_code(404);
        exit('The audio file is not available in the approved audio directory.');
    }

    $mimeTypes = [
        'aac' => 'audio/aac',
        'flac' => 'audio/flac',
        'm4a' => 'audio/mp4',
        'mp3' => 'audio/mpeg',
        'oga' => 'audio/ogg',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
    ];
    $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
    if (!isset($mimeTypes[$extension])) {
        http_response_code(415);
        exit('This audio format is not supported by the Supervisor player.');
    }

    $size = filesize($resolved);
    if ($size === false || $size < 1) {
        http_response_code(404);
        exit('The audio file is empty or unavailable.');
    }

    $start = 0;
    $end = $size - 1;
    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
    if ($rangeHeader !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $range)) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        if ($range[1] === '') {
            $suffixLength = (int) $range[2];
            if ($suffixLength < 1) {
                header('Content-Range: bytes */' . $size);
                http_response_code(416);
                exit;
            }
            $start = max(0, $size - $suffixLength);
        } else {
            $start = (int) $range[1];
            $end = $range[2] === '' ? $end : min((int) $range[2], $end);
        }
        if ($start >= $size || $start > $end) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }

    $length = $end - $start + 1;
    header('Content-Type: ' . $mimeTypes[$extension]);
    header('Content-Length: ' . $length);
    header('Accept-Ranges: bytes');
    header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', basename($resolved)) . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        exit;
    }

    $handle = fopen($resolved, 'rb');
    if ($handle === false || fseek($handle, $start) !== 0) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        http_response_code(500);
        exit('Unable to read this audio file.');
    }

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    while ($length > 0 && !feof($handle)) {
        $chunk = fread($handle, min(65536, $length));
        if ($chunk === false || $chunk === '') {
            error_log('Audio stream ended before the requested range was fully read.');
            break;
        }
        echo $chunk;
        $length -= strlen($chunk);
    }
    fclose($handle);
} catch (Throwable $exception) {
    error_log('TMS supervisor audio access failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to stream this recording.');
}
