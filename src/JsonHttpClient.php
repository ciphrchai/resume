<?php
declare(strict_types=1);

final class JsonHttpClient
{
    private const MAX_RESPONSE_BYTES = 1048576;
    private const ALLOWED_HOSTS = ['api.search.brave.com', 'api.adzuna.com'];

    public function __construct(private readonly ?Closure $transport = null)
    {
    }

    public function get(string $url, array $headers = []): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !in_array(strtolower($parts['host'] ?? ''), self::ALLOWED_HOSTS, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The research provider endpoint is not allowed.');
        }

        foreach ($headers as $name => $value) {
            if (!is_string($name)
                || !is_string($value)
                || preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1
                || preg_match('/[\r\n]/', $value) === 1
                || strlen($value) > 4096) {
                throw new InvalidArgumentException('The research provider request headers are invalid.');
            }
        }

        $response = $this->transport === null
            ? $this->sendRequest($url, $headers)
            : ($this->transport)($url, $headers);

        if (!is_array($response)
            || !isset($response['status'], $response['body'])
            || !is_int($response['status'])
            || !is_string($response['body'])
            || strlen($response['body']) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('The research provider returned an invalid response.', 502);
        }
        if ($response['status'] === 429) {
            throw new RuntimeException('The research provider is rate-limiting requests. Try again later.', 503);
        }
        if (in_array($response['status'], [401, 403], true)) {
            throw new RuntimeException('The research provider rejected its server credentials. Check the server configuration.', 503);
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('The research provider could not complete the request. Please try again.', 502);
        }

        try {
            $payload = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('The research provider returned unreadable JSON.', 502, $error);
        }
        if (!is_array($payload)) {
            throw new RuntimeException('The research provider returned an invalid JSON document.', 502);
        }

        return $payload;
    }

    private function sendRequest(string $url, array $headers): array
    {
        $requestHeaders = array_merge(['Accept' => 'application/json'], $headers);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", array_map(
                    static fn(string $name, string $value): string => $name . ': ' . $value,
                    array_keys($requestHeaders),
                    array_values($requestHeaders),
                )),
                'timeout' => 12,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException('Could not reach the configured research provider.', 502);
        }

        $body = stream_get_contents($stream, self::MAX_RESPONSE_BYTES + 1);
        fclose($stream);
        if ($body === false) {
            throw new RuntimeException('Could not read the research provider response.', 502);
        }
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('The research provider response exceeds the allowed size.', 502);
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }
        if ($status === 0) {
            throw new RuntimeException('The research provider returned an invalid HTTP response.', 502);
        }

        return ['status' => $status, 'body' => $body];
    }
}
