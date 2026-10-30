<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/ResumeAnalyzer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Use POST to analyze a resume.']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
if ($mediaType !== 'application/json') {
    http_response_code(415);
    echo json_encode(['error' => 'Send the resume as a JSON request.']);
    exit;
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 82000) {
    http_response_code(413);
    echo json_encode(['error' => 'Request is too large. Resume text must be 20,000 characters or fewer.']);
    exit;
}

$body = file_get_contents('php://input', false, null, 0, 82001);
if ($body === false || strlen($body) > 82000) {
    http_response_code(413);
    echo json_encode(['error' => 'Request is too large. Resume text must be 20,000 characters or fewer.']);
    exit;
}

try {
    $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || array_diff(array_keys($payload), ['resume', 'preferences', 'consent']) !== []) {
        throw new InvalidArgumentException('Send a JSON object with resume text, optional preferences, and consent.');
    }
    if (($payload['consent'] ?? null) !== true) {
        throw new InvalidArgumentException('Confirm consent before sending resume text to the AI provider.');
    }
    $resume = ResumeAnalyzer::validateResume($payload['resume'] ?? null);
    $preferences = $payload['preferences'] ?? [];
    if (!is_array($preferences)) {
        throw new InvalidArgumentException('Career preferences must be a JSON object.');
    }
    $analysis = (new ResumeAnalyzer())->analyze($resume, $preferences);
    echo json_encode(['analysis' => $analysis], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['error' => 'The request body must be valid JSON.']);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['error' => $error->getMessage()]);
} catch (RuntimeException $error) {
    http_response_code($error->getCode() >= 500 && $error->getCode() <= 599 ? $error->getCode() : 502);
    error_log('Resume analyzer: ' . $error->getMessage());
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('Resume analyzer unexpected error: ' . $error::class);
    echo json_encode(['error' => 'An unexpected error occurred. Please try again.']);
}
