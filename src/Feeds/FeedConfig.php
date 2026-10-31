<?php
declare(strict_types=1);

namespace Announcements\Feeds;

/**
 * Loads and sanity-checks src/Feeds/feeds.php (see that file for the
 * shape). A malformed entry throws here, at load time, rather than
 * half-way through a poll — the cron runner records the exception
 * message as the run's output.
 */
final class FeedConfig
{
    /** @return array<string, array<string, mixed>> keyed by feed key */
    public static function all(?string $path = null): array
    {
        $feeds = require $path ?? __DIR__ . '/feeds.php';

        if (!is_array($feeds)) {
            throw new \RuntimeException('feeds.php must return an array');
        }

        foreach ($feeds as $key => $feed) {
            self::validate((string) $key, $feed);
        }

        return $feeds;
    }

    public static function validate(string $key, mixed $feed): void
    {
        if (!is_array($feed) || $key === '' || !preg_match('/^[a-z0-9-]+$/', $key)) {
            throw new \RuntimeException("feeds.php: '{$key}' must be a lowercase key mapping to an array");
        }

        foreach (['label', 'base_url'] as $field) {
            if (empty($feed[$field]) || !is_string($feed[$field])) {
                throw new \RuntimeException("feeds.php: '{$key}' needs a non-empty string '{$field}'");
            }
        }

        if (!preg_match('#^https?://[^/]+$#', $feed['base_url'])) {
            throw new \RuntimeException("feeds.php: '{$key}' base_url must be an origin with no path, got '{$feed['base_url']}'");
        }

        $match = $feed['match'] ?? null;

        if (!is_array($match) || count($match) !== 1 || !in_array(array_key_first($match), ['keywords', 'components'], true)) {
            throw new \RuntimeException("feeds.php: '{$key}' match must be exactly one of ['keywords' => [...]] or ['components' => [...]]");
        }

        $list = reset($match);

        if (!is_array($list) || $list === [] || array_filter($list, fn ($v) => !is_string($v) || trim($v) === '') !== []) {
            throw new \RuntimeException("feeds.php: '{$key}' match list must be a non-empty list of strings");
        }

        if (!array_key_exists('auto_publish', $feed) || !is_bool($feed['auto_publish'])) {
            throw new \RuntimeException("feeds.php: '{$key}' needs an explicit boolean auto_publish");
        }
    }
}
