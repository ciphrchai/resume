<?php
declare(strict_types=1);

final class BraveSearchProvider
{
    public function __construct(
        private readonly ?JsonHttpClient $http = null,
        private readonly ?array $config = null,
    ) {
    }

    public function search(string $query, ?string $country = null, int $count = 8): array
    {
        $query = self::validateText($query, 300, 'Search query');
        if (self::textLength($query) < 3) {
            throw new InvalidArgumentException('Enter a more specific market research query.');
        }
        if ($count < 1 || $count > 10) {
            throw new InvalidArgumentException('Search result count must be between 1 and 10.');
        }
        if ($country !== null && preg_match('/^[a-z]{2}$/i', $country) !== 1) {
            throw new InvalidArgumentException('Choose a valid two-letter country code.');
        }

        $config = $this->config ?? ['api_key' => getenv('BRAVE_SEARCH_API_KEY') ?: ''];
        $apiKey = $config['api_key'] ?? '';
        if (!is_string($apiKey) || trim($apiKey) === '' || preg_match('/[\r\n]/', $apiKey) === 1) {
            throw new RuntimeException('Live market research is not configured. Set BRAVE_SEARCH_API_KEY on the server.', 503);
        }

        $parameters = ['q' => $query, 'count' => $count];
        if ($country !== null) {
            $parameters['country'] = strtoupper($country);
        }
        $url = 'https://api.search.brave.com/res/v1/web/search?' . http_build_query($parameters);
        $response = ($this->http ?? new JsonHttpClient())->get($url, ['X-Subscription-Token' => $apiKey]);
        $results = $response['web']['results'] ?? null;
        if (!is_array($results)) {
            throw new RuntimeException('The search provider response did not contain web results.', 502);
        }

        $retrievedAt = gmdate(DATE_ATOM);
        $sources = [];
        foreach (array_slice($results, 0, $count) as $result) {
            if (!is_array($result)
                || !is_string($result['title'] ?? null)
                || !is_string($result['url'] ?? null)
                || !is_string($result['description'] ?? null)) {
                continue;
            }
            $url = ProviderData::publicHttpsUrl($result['url']);
            if ($url === null) {
                continue;
            }
            $host = parse_url($url, PHP_URL_HOST);
            $sources[] = [
                'title' => ProviderData::cleanText($result['title'], 240, 'Untitled source'),
                'url' => $url,
                'source' => is_string($host) ? strtolower($host) : 'Web source',
                'snippet' => ProviderData::cleanText($result['description'], 1000, 'No source description was provided.'),
                'retrieved_at' => $retrievedAt,
            ];
        }

        return [
            'provider' => 'Brave Search',
            'query' => $query,
            'retrieved_at' => $retrievedAt,
            'sources' => $sources,
        ];
    }

    private static function validateText(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        if ($value === '' || self::textLength($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException($label . ' is empty or outside the supported length.');
        }

        return $value;
    }

    private static function textLength(string $value): int
    {
        $length = preg_match_all('/./us', $value);
        return $length === false ? PHP_INT_MAX : $length;
    }
}
