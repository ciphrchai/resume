<?php
declare(strict_types=1);

final class AdzunaJobProvider
{
    private const CURRENCIES = [
        'au' => 'AUD', 'at' => 'EUR', 'be' => 'EUR', 'br' => 'BRL', 'ca' => 'CAD',
        'ch' => 'CHF', 'de' => 'EUR', 'es' => 'EUR', 'fr' => 'EUR', 'gb' => 'GBP',
        'in' => 'INR', 'ie' => 'EUR', 'it' => 'EUR', 'mx' => 'MXN', 'nl' => 'EUR',
        'nz' => 'NZD', 'pl' => 'PLN', 'sg' => 'SGD', 'us' => 'USD', 'za' => 'ZAR',
    ];

    public function __construct(
        private readonly ?JsonHttpClient $http = null,
        private readonly ?array $config = null,
    ) {
    }

    public function search(
        string $role,
        string $country,
        ?string $city,
        array $skills,
        int $page = 1,
    ): array {
        $role = self::validateText($role, 120, 'Target role');
        $country = strtolower(trim($country));
        if (preg_match('/^[a-z]{2}$/', $country) !== 1 || !isset(self::CURRENCIES[$country])) {
            throw new InvalidArgumentException('Choose a valid two-letter country code.');
        }
        if ($city !== null) {
            $city = self::validateText($city, 100, 'City');
        }
        if ($page < 1 || $page > 10) {
            throw new InvalidArgumentException('Job search page must be between 1 and 10.');
        }
        $skills = self::validateSkills($skills);

        $config = $this->config ?? [
            'app_id' => getenv('ADZUNA_APP_ID') ?: '',
            'app_key' => getenv('ADZUNA_APP_KEY') ?: '',
        ];
        $appId = $config['app_id'] ?? '';
        $appKey = $config['app_key'] ?? '';
        if (!is_string($appId) || !is_string($appKey)
            || trim($appId) === '' || trim($appKey) === ''
            || preg_match('/[\r\n]/', $appId . $appKey) === 1) {
            throw new RuntimeException('Live job search is not configured. Set ADZUNA_APP_ID and ADZUNA_APP_KEY on the server.', 503);
        }

        $parameters = [
            'app_id' => $appId,
            'app_key' => $appKey,
            'results_per_page' => 20,
            'what' => $role,
            'sort_by' => 'date',
            'content-type' => 'application/json',
        ];
        if ($city !== null && $city !== '') {
            $parameters['where'] = $city;
        }
        $url = 'https://api.adzuna.com/v1/api/jobs/' . $country . '/search/' . $page . '?' . http_build_query($parameters);
        $payload = ($this->http ?? new JsonHttpClient())->get($url);
        $results = $payload['results'] ?? null;
        if (!is_array($results)) {
            throw new RuntimeException('The job provider response did not contain job listings.', 502);
        }

        $retrievedAt = gmdate(DATE_ATOM);
        $jobs = [];
        $seen = [];
        foreach ($results as $listing) {
            if (!is_array($listing)) {
                continue;
            }
            $id = $listing['id'] ?? null;
            $title = $listing['title'] ?? null;
            $redirectUrl = $listing['redirect_url'] ?? null;
            if ((!is_string($id) && !is_int($id))
                || !is_string($title)
                || !is_string($redirectUrl)
                || isset($seen[(string) $id])) {
                continue;
            }
            $applicationUrl = ProviderData::adzunaListingUrl($redirectUrl);
            if ($applicationUrl === null) {
                continue;
            }
            $title = ProviderData::cleanText($title, 180, 'Untitled job');
            $description = is_string($listing['description'] ?? null)
                ? ProviderData::cleanText($listing['description'], 2000, 'No description was provided.')
                : 'No description was provided.';
            $company = is_array($listing['company'] ?? null)
                && is_string($listing['company']['display_name'] ?? null)
                ? ProviderData::cleanText($listing['company']['display_name'], 160, 'Company not disclosed')
                : 'Company not disclosed';
            $location = is_array($listing['location'] ?? null)
                && is_string($listing['location']['display_name'] ?? null)
                ? ProviderData::cleanText($listing['location']['display_name'], 160, 'Location not provided')
                : 'Location not provided';
            $matchedSkills = [];
            $unmentionedSkills = [];
            foreach ($skills as $skill) {
                if (self::containsSkill($title . ' ' . $description, $skill)) {
                    $matchedSkills[] = $skill;
                } else {
                    $unmentionedSkills[] = $skill;
                }
            }
            $roleMatch = stripos($title, $role) !== false;
            $skillCoverage = $skills === [] ? 0 : (int) round(count($matchedSkills) / count($skills) * 70);
            $matchScore = $skills === [] ? ($roleMatch ? 100 : 0) : $skillCoverage + ($roleMatch ? 30 : 0);
            $reasons = [];
            if ($skills !== []) {
                $reasons[] = count($matchedSkills) . ' of ' . count($skills) . ' analyzed resume skills appear in the listing text.';
            }
            $reasons[] = $roleMatch
                ? 'The target role wording appears in the listing title.'
                : 'The listing title does not contain the full target-role wording.';

            $jobs[] = [
                'id' => (string) $id,
                'title' => $title,
                'company' => $company,
                'location' => $location,
                'description' => $description,
                'employment_type' => self::employmentType($listing),
                'posted_at' => self::formatDate($listing['created'] ?? null),
                'listing_url' => $applicationUrl,
                'source' => 'Adzuna',
                'source_url' => 'https://developer.adzuna.com/docs/search',
                'retrieved_at' => $retrievedAt,
                'match_score' => $matchScore,
                'match_reasons' => $reasons,
                'matched_skills' => $matchedSkills,
                'skills_not_mentioned' => $unmentionedSkills,
                'salary' => self::salary($listing, $country),
            ];
            $seen[(string) $id] = true;
        }

        return [
            'provider' => 'Adzuna',
            'target_role' => $role,
            'country' => strtoupper($country),
            'city' => $city,
            'page' => $page,
            'retrieved_at' => $retrievedAt,
            'status_note' => 'Listings were returned by Adzuna at retrieval time. The employer page and current availability have not been independently verified.',
            'jobs' => $jobs,
        ];
    }

    private static function validateSkills(array $skills): array
    {
        if (count($skills) > 20) {
            throw new InvalidArgumentException('Provide no more than 20 resume skills for job matching.');
        }
        $validated = [];
        foreach ($skills as $skill) {
            if (!is_string($skill)) {
                throw new InvalidArgumentException('Resume skills must be text.');
            }
            $skill = self::validateText($skill, 80, 'Resume skill');
            $key = strtolower($skill);
            if (!isset($validated[$key])) {
                $validated[$key] = $skill;
            }
        }

        return array_values($validated);
    }

    private static function validateText(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        $length = preg_match_all('/./us', $value);
        if ($value === '' || $length === false || $length > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException($label . ' is empty or outside the supported length.');
        }

        return $value;
    }

    private static function containsSkill(string $text, string $skill): bool
    {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($skill, '/') . '(?![\p{L}\p{N}])/iu';
        return preg_match($pattern, $text) === 1;
    }

    private static function employmentType(array $listing): ?string
    {
        $parts = [];
        foreach (['contract_time', 'contract_type'] as $field) {
            if (is_string($listing[$field] ?? null)) {
                $value = ProviderData::cleanText($listing[$field], 40, '');
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private static function formatDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }

    private static function salary(array $listing, string $country): ?array
    {
        $minimum = $listing['salary_min'] ?? null;
        $maximum = $listing['salary_max'] ?? null;
        $currency = self::CURRENCIES[$country] ?? null;
        if ($currency === null
            || !is_numeric($minimum)
            || !is_numeric($maximum)
            || !is_finite((float) $minimum)
            || !is_finite((float) $maximum)
            || (float) $minimum <= 0
            || (float) $maximum < (float) $minimum
            || (float) $maximum > 100000000) {
            return null;
        }

        $isPredicted = (string) ($listing['salary_is_predicted'] ?? '') === '1';
        return [
            'minimum' => (float) $minimum,
            'maximum' => (float) $maximum,
            'currency' => $currency,
            'basis' => $isPredicted ? 'Adzuna estimate' : 'Salary data reported to Adzuna',
            'period' => 'Not specified by the listing API',
        ];
    }
}
