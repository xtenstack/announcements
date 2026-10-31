<?php
declare(strict_types=1);

namespace Announcements\Feeds;

/**
 * Decides whether one Statuspage event (an incident or a scheduled
 * maintenance, same shape) is relevant to a feed's 'match' rule — see
 * feeds.php for the two rule kinds and why each provider gets the one it
 * does. Pure: no I/O, no framework, unit-tested against the saved
 * fixtures.
 */
final class EventMatcher
{
    /** @param array<string, mixed> $feed  @param array<string, mixed> $event */
    public static function matches(array $feed, array $event): bool
    {
        $match = $feed['match'] ?? [];

        if (isset($match['keywords'])) {
            return self::matchesKeywords((array) $match['keywords'], $event);
        }

        if (isset($match['components'])) {
            return self::matchesComponents((array) $match['components'], $event);
        }

        return false;
    }

    /**
     * Whole-word, case-insensitive: "SYD1" must not match inside another
     * token, but does match "SYD1," / "(SYD1)" / "SYD1." as they appear
     * in DigitalOcean's prose.
     *
     * @param string[] $keywords  @param array<string, mixed> $event
     */
    public static function matchesKeywords(array $keywords, array $event): bool
    {
        $haystack = implode("\n", array_merge(
            [(string) ($event['name'] ?? '')],
            self::componentNames($event),
            self::updateBodies($event)
        ));

        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);

            if ($keyword === '') {
                continue;
            }

            if (preg_match('/(?<![A-Za-z0-9])' . preg_quote($keyword, '/') . '(?![A-Za-z0-9])/iu', $haystack)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exact (case-insensitive) component name, taken from the event's
     * components[] list or from any update's affected_components[] — the
     * latter carry Statuspage's "Group - Name" form, so the part after
     * the first " - " is tried as well.
     *
     * @param string[] $allowList  @param array<string, mixed> $event
     */
    public static function matchesComponents(array $allowList, array $event): bool
    {
        $allowed = [];

        foreach ($allowList as $name) {
            $allowed[mb_strtolower(trim((string) $name))] = true;
        }

        unset($allowed['']);

        foreach (self::componentNames($event) as $name) {
            $candidates = [mb_strtolower($name)];

            if (str_contains($name, ' - ')) {
                $candidates[] = mb_strtolower(trim(substr($name, strpos($name, ' - ') + 3)));
            }

            foreach ($candidates as $candidate) {
                if (isset($allowed[$candidate])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every component name the event mentions, in both forms Statuspage
     * uses (bare name in components[], "Group - Name" in
     * incident_updates[].affected_components[]), deduplicated, in first-
     * seen order.
     *
     * @param array<string, mixed> $event
     * @return string[]
     */
    public static function componentNames(array $event): array
    {
        $names = [];

        foreach ((array) ($event['components'] ?? []) as $component) {
            if (is_array($component) && isset($component['name']) && trim((string) $component['name']) !== '') {
                $names[trim((string) $component['name'])] = true;
            }
        }

        foreach ((array) ($event['incident_updates'] ?? []) as $update) {
            foreach ((array) ($update['affected_components'] ?? []) as $component) {
                if (is_array($component) && isset($component['name']) && trim((string) $component['name']) !== '') {
                    $names[trim((string) $component['name'])] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * @param array<string, mixed> $event
     * @return string[]
     */
    public static function updateBodies(array $event): array
    {
        $bodies = [];

        foreach ((array) ($event['incident_updates'] ?? []) as $update) {
            if (is_array($update) && isset($update['body'])) {
                $bodies[] = (string) $update['body'];
            }
        }

        return $bodies;
    }
}
