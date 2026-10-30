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
    echo json_encode(['error' => 'Use POST to request market research.']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json') {
    http_response_code(415);
    echo json_encode(['error' => 'Send market research preferences as JSON.']);
    exit;
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16000) {
    http_response_code(413);
    echo json_encode(['error' => 'Market research request is too large.']);
    exit;
}

try {
    require_once dirname(__DIR__, 2) . '/src/JsonHttpClient.php';
    require_once dirname(__DIR__, 2) . '/src/ProviderData.php';
    require_once dirname(__DIR__, 2) . '/src/BraveSearchProvider.php';

    $body = file_get_contents('php://input', false, null, 0, 16001);
    if ($body === false || strlen($body) > 16000) {
        http_response_code(413);
        echo json_encode(['error' => 'Market research request is too large.', 'request_id' => $requestId]);
        exit;
    }
    $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($payload)
        || array_diff(array_keys($payload), ['role', 'skills', 'country', 'city', 'consent']) !== []) {
        throw new InvalidArgumentException('Send role, skills, location, and consent fields.');
    }
    if (($payload['consent'] ?? null) !== true) {
        throw new InvalidArgumentException('Confirm consent before requesting external market research.');
    }
    $role = $payload['role'] ?? null;
    if (!is_string($role)) {
        throw new InvalidArgumentException('A target role is required for market research.');
    }
    $skills = $payload['skills'] ?? [];
    if (!is_array($skills) || count($skills) > 10) {
        throw new InvalidArgumentException('Provide no more than 10 skills for market research.');
    }
    $country = $payload['country'] ?? null;
    if ($country !== null && (!is_string($country) || preg_match('/^[a-z]{2}$/i', $country) !== 1)) {
        throw new InvalidArgumentException('Country must be a two-letter country code.');
    }
    $city = $payload['city'] ?? null;
    if ($city !== null && !is_string($city)) {
        throw new InvalidArgumentException('City must be text.');
    }

    $role = trim($role);
    $roleLength = preg_match_all('/./us', $role);
    if ($role === '' || $roleLength === false || $roleLength > 120 || preg_match('/[\x00-\x1F\x7F]/', $role) === 1) {
        throw new InvalidArgumentException('Target role is empty or outside the supported length.');
    }
    $query = $role . ' hiring trends and in-demand skills ' . gmdate('Y');
    if ($city !== null && trim($city) !== '') {
        $city = trim($city);
        $cityLength = preg_match_all('/./us', $city);
        if ($cityLength === false || $cityLength > 100 || preg_match('/[\x00-\x1F\x7F]/', $city) === 1) {
            throw new InvalidArgumentException('City is outside the supported length.');
        }
        if (strlen($query . ' in ' . $city) <= 250) {
            $query .= ' in ' . $city;
        }
    }
    foreach ($skills as $skill) {
        if (!is_string($skill)) {
            throw new InvalidArgumentException('Resume skills must be text.');
        }
        $skill = trim($skill);
        $skillLength = preg_match_all('/./us', $skill);
        if ($skill === '' || $skillLength === false || $skillLength > 80 || preg_match('/[\x00-\x1F\x7F]/', $skill) === 1) {
            throw new InvalidArgumentException('A resume skill is empty or outside the supported length.');
        }
        if (strlen($query . ' ' . $skill) <= 290) {
            $query .= ' ' . $skill;
        }
    }

    $result = (new BraveSearchProvider())->search($query, $country, 8);
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
    error_log('Market research: ' . $error->getMessage() . '; request_id=' . $requestId);
    echo json_encode(['error' => $error->getMessage(), 'request_id' => $requestId]);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('Market research unexpected error: ' . $error::class . '; request_id=' . $requestId);
    echo json_encode(['error' => 'Market research could not be completed.', 'request_id' => $requestId]);
}
