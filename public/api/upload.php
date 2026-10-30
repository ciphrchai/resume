<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$requestId = bin2hex(random_bytes(8));
header('X-Request-ID: ' . $requestId);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Use POST to upload a resume.']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (preg_match('/^multipart\/form-data\s*;/i', $contentType) !== 1) {
    http_response_code(415);
    echo json_encode(['error' => 'Upload a PDF, DOCX, or TXT file using multipart form data.']);
    exit;
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 5300000) {
    http_response_code(413);
    echo json_encode(['error' => 'Resume files must be 5 MB or smaller.']);
    exit;
}

try {
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
    require_once dirname(__DIR__, 2) . '/src/ResumeParser.php';

    $upload = $_FILES['resume'] ?? null;
    if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['name'])) {
        throw new InvalidArgumentException('Choose a resume file to upload.');
    }
    if (!is_int($upload['error']) || !is_string($upload['tmp_name']) || !is_string($upload['name'])) {
        throw new InvalidArgumentException('The uploaded resume metadata is invalid.');
    }
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new LengthException('Resume files must be 5 MB or smaller.');
        }
        if ($upload['error'] === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('Choose a resume file to upload.');
        }
        throw new RuntimeException('The resume upload did not complete. Please try again.', 400);
    }

    $filename = basename(str_replace('\\', '/', (string) $upload['name']));
    $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename) ?? '';
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, ['pdf', 'docx', 'txt'], true)) {
        throw new InvalidArgumentException('Choose a PDF, DOCX, or TXT resume.');
    }
    if (!is_uploaded_file($upload['tmp_name'])) {
        throw new InvalidArgumentException('The uploaded resume could not be verified.');
    }
    $fileSize = filesize($upload['tmp_name']);
    if ($fileSize === false || $fileSize > 5242880) {
        throw new LengthException('Resume files must be 5 MB or smaller.');
    }

    $temporaryPath = tempnam(sys_get_temp_dir(), 'resumelab-');
    if ($temporaryPath === false) {
        throw new RuntimeException('A temporary file could not be created.', 500);
    }

    try {
        if (!move_uploaded_file($upload['tmp_name'], $temporaryPath)) {
            throw new RuntimeException('The uploaded resume could not be staged for extraction.', 500);
        }
        if (!chmod($temporaryPath, 0600)) {
            throw new RuntimeException('The uploaded resume could not be secured for extraction.', 500);
        }
        $result = (new ResumeParser())->parse($temporaryPath, $extension);
    } finally {
        if (is_file($temporaryPath) && !unlink($temporaryPath)) {
            error_log('Resume upload cleanup failed; request_id=' . $requestId);
        }
    }

    echo json_encode([
        'filename' => substr($filename, 0, 160),
        'size' => $fileSize,
        'text' => $result['text'],
        'characters' => $result['characters'],
        'warnings' => $result['warnings'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['error' => $error->getMessage(), 'request_id' => $requestId]);
} catch (LengthException $error) {
    http_response_code(413);
    echo json_encode(['error' => $error->getMessage(), 'request_id' => $requestId]);
} catch (RuntimeException $error) {
    http_response_code($error->getCode() >= 400 && $error->getCode() <= 599 ? $error->getCode() : 500);
    error_log('Resume upload: ' . $error->getMessage() . '; request_id=' . $requestId);
    echo json_encode(['error' => $error->getMessage(), 'request_id' => $requestId]);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('Resume upload unexpected error: ' . $error::class . '; request_id=' . $requestId);
    echo json_encode(['error' => 'The resume could not be processed. Please try again.', 'request_id' => $requestId]);
}
