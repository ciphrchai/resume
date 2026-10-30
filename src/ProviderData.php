<?php
declare(strict_types=1);

final class ProviderData
{
    public static function cleanText(string $value, int $maxLength, string $fallback): string
    {
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $length = self::textLength($value);
        if ($length === null || $value === '') {
            return $fallback;
        }
        if ($length <= $maxLength) {
            return $value;
        }

        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($characters) ? implode('', array_slice($characters, 0, $maxLength)) : $fallback;
    }

    public static function publicHttpsUrl(string $url, bool $upgradeAdzuna = false): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if ($scheme === 'http'
            && $upgradeAdzuna
            && preg_match('/(^|\.)adzuna\.[a-z]{2,3}(?:\.[a-z]{2})?$/i', $host) === 1
            && ($port === null || $port === 80)) {
            $url = 'https://' . $host . ($parts['path'] ?? '')
                . (isset($parts['query']) ? '?' . $parts['query'] : '');
            $scheme = 'https';
            $port = null;
        }
        if ($scheme !== 'https' || ($port !== null && $port !== 443)) {
            return null;
        }

        $ip = trim($host, '[]');
        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        return $url;
    }

    public static function adzunaListingUrl(string $url): ?string
    {
        $url = self::publicHttpsUrl($url, true);
        if ($url === null) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return preg_match('/(^|\.)adzuna\.[a-z]{2,3}(?:\.[a-z]{2})?$/i', $host) === 1 ? $url : null;
    }

    private static function textLength(string $value): ?int
    {
        $length = preg_match_all('/./us', $value);
        return $length === false ? null : $length;
    }
}
