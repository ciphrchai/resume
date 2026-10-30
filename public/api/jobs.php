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
    echo json_encode(['error' => 'Use POST to search for jobs.']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json') {
    http_response_code(415);
    echo json_encode(['error' => 'Send job search preferences as JSON.']);
    exit;
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16000) {
    http_response_code(413);
    echo json_encode(['error' => 'Job search request is too large.']);
    exit;
}

try {
    require_once dirname(__DIR__, 2) . '/src/JsonHttpClient.php';
    require_once dirname(__DIR__, 2) . '/src/ProviderData.php';
    require_once dirname(__DIR__, 2) . '/src/AdzunaJobProvider.php';

    $body = file_get_contents('php://input', false, null, 0, 16001);
    if ($body === false || strlen($body) > 16000) {
        http_response_code(413);
        echo json_encode(['error' => 'Job search request is too large.', 'request_id' => $requestId]);
        exit;
    }
    $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($payload)
        || array_diff(array_keys($payload), ['role', 'skills', 'country', 'city', 'page', 'consent']) !== []) {
        throw new InvalidArgumentException('Send role, skills, location, and consent fields.');
    }
    if (($payload['consent'] ?? null) !== true) {
        throw new InvalidArgumentException('Confirm consent before requesting external job listings.');
    }
    $role = $payload['role'] ?? null;
    $country = $payload['country'] ?? null;
    $city = $payload['city'] ?? null;
    $skills = $payload['skills'] ?? [];
    $page = $payload['page'] ?? 1;
    if (!is_string($role) || !is_string($country)
        || ($city !== null && !is_string($city))
        || !is_array($skills)
        || !is_int($page)) {
        throw new InvalidArgumentException('Job search fields have invalid types.');
    }
    if ($city !== null && trim($city) === '') {
        $city = null;
    }

    $result = (new AdzunaJobProvider())->search($role, $country, $city, $skills, $page);
    $result['request_id'] = $requestId;
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['error' => 'The request body must be valid JSON.', 'request_id' => $requestId]);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['error' => $error->getMessage(), 'request_id' => $requestId]);
} catch (RuntimeException $error) {
    http_response_code($error->getCode() >= 500 && $error->getCode() <= 599 ? $error->getCode() : 502);
    error_log('Job search: ' . $error->getMessage() . '; request_id=' . $requestId);
    echo json_encode(['error' => $error->getMessage(), 'request_id' => $requestId]);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('Job search unexpected error: ' . $error::class . '; request_id=' . $requestId);
    echo json_encode(['error' => 'Job search could not be completed.', 'request_id' => $requestId]);
}
