<?php
declare(strict_types=1);

namespace Announcements\Feeds;

/**
 * Turns one matched Statuspage event into the announcement row the
 * poller upserts, and answers the two follow-up questions the poller has
 * about a stored row (has the event changed? has it finished?). Pure —
 * the only inputs are the event array, the feed config and the clock —
 * so the whole rendering is unit-tested against the saved fixtures.
 *
 * Times: Statuspage returns ISO-8601 with an offset (each page has its
 * own display zone — DigitalOcean UTC, IONOS UK Europe/London). The body
 * shows every time in AWST (Australia/Perth, where the team reads it)
 * and in UTC (what the provider's engineers quote), while expires_at is
 * written in the application's own storage zone
 * (date_default_timezone_get(), the same zone date('Y-m-d H:i:s') uses
 * everywhere else in this codebase for published_at/expires_at), so
 * comparisons against NOW in the read paths stay consistent.
 */
final class EventMapper
{
    public const DISPLAY_ZONE = 'Australia/Perth';

    public const SOURCE_PREFIX = 'statuspage:';

    /**
     * Severity in the model's own vocabulary (Announcement::SEVERITIES:
     * info/notice/important). The brief for this feature spoke of
     * "warning" and "critical"; those are accepted as aliases by
     * Announcement::normalizeSeverity() and stored as the two levels
     * below, so the backend badges, the history filter and the read API
     * keep one three-level scale.
     */
    public const SEVERITY_WARNING  = 'notice';
    public const SEVERITY_CRITICAL = 'important';

    private \DateTimeZone $storageZone;

    private \DateTimeZone $displayZone;

    private \DateTimeZone $utc;

    public function __construct(?\DateTimeZone $storageZone = null)
    {
        $this->storageZone = $storageZone ?? new \DateTimeZone(date_default_timezone_get());
        $this->displayZone = new \DateTimeZone(self::DISPLAY_ZONE);
        $this->utc         = new \DateTimeZone('UTC');
    }

    public static function source(string $feedKey): string
    {
        return self::SOURCE_PREFIX . $feedKey;
    }

    public static function isMaintenance(array $event): bool
    {
        return ($event['impact'] ?? null) === 'maintenance'
            || array_key_exists('scheduled_for', $event);
    }

    /**
     * The row to store for this event.
     *
     * @param array<string, mixed> $feed   one feeds.php entry
     * @param array<string, mixed> $event  a Statuspage incident / scheduled maintenance
     * @return array{source: string, external_id: string, source_url: ?string, title: string,
     *               body: string, severity: string, audience: string, expires_at: ?string}
     */
    public function map(string $feedKey, array $feed, array $event): array
    {
        $isMaintenance = self::isMaintenance($event);
        $name          = trim((string) ($event['name'] ?? 'Untitled event'));
        $label         = trim((string) ($feed['label'] ?? $feedKey));

        $title = $label . ': ' . $name;

        if (mb_strlen($title) > 200) {
            $title = mb_substr($title, 0, 199) . '…';
        }

        return [
            'source'      => self::source($feedKey),
            'external_id' => (string) $event['id'],
            'source_url'  => isset($event['shortlink']) && $event['shortlink'] !== '' ? (string) $event['shortlink'] : null,
            'title'       => $title,
            'body'        => $this->body($event, $isMaintenance),
            'severity'    => self::severity($event, $isMaintenance),
            // Provider outages reach customers too (25 Sep 2026).
            'audience'    => \Announcements\Api\Audience::EVERYONE,
            'expires_at'  => $isMaintenance ? $this->toStorage($event['scheduled_until'] ?? null) : null,
        ];
    }

    /**
     * Scheduled maintenance is a warning by definition (planned, bounded);
     * an incident is a warning until the provider calls it major or
     * critical.
     */
    public static function severity(array $event, ?bool $isMaintenance = null): string
    {
        if ($isMaintenance ?? self::isMaintenance($event)) {
            return self::SEVERITY_WARNING;
        }

        return in_array(strtolower((string) ($event['impact'] ?? 'none')), ['major', 'critical'], true)
            ? self::SEVERITY_CRITICAL
            : self::SEVERITY_WARNING;
    }

    /**
     * Only the fields the poller is allowed to rewrite on an existing row
     * — title/body/severity/expires_at (and the shortlink, which never
     * changes in practice). published_at, author and audience are left
     * alone so an admin's own edits to those survive a re-poll.
     *
     * @param array<string, mixed> $stored  the existing row
     * @param array<string, mixed> $mapped  map()'s output
     */
    public static function hasChanges(array $stored, array $mapped): bool
    {
        foreach (['title', 'body', 'severity', 'source_url'] as $field) {
            if ((string) ($stored[$field] ?? '') !== (string) ($mapped[$field] ?? '')) {
                return true;
            }
        }

        return self::normalizeStamp($stored['expires_at'] ?? null) !== self::normalizeStamp($mapped['expires_at'] ?? null);
    }

    /** @return string[] */
    public static function updatableFields(): array
    {
        return ['title', 'body', 'severity', 'expires_at', 'source_url'];
    }

    /** Resolved incident or completed maintenance, per the detail endpoint. */
    public static function isFinished(array $event): bool
    {
        $status = strtolower((string) ($event['status'] ?? ''));

        return in_array($status, ['resolved', 'completed'], true)
            || (isset($event['resolved_at']) && $event['resolved_at'] !== null && $event['resolved_at'] !== '');
    }

    /**
     * The line appended to a stored body once its event has finished.
     * Uses the provider's own resolved_at when present (the accurate
     * time), else the moment we noticed.
     */
    public function resolvedAppendix(array $event, \DateTimeImmutable $noticedAt): string
    {
        $at = $this->parse($event['resolved_at'] ?? null) ?? $noticedAt;

        return "\n\n**Resolved** " . $this->both($at) . '.';
    }

    private function body(array $event, bool $isMaintenance): string
    {
        $lines = [
            '- **Type:** ' . ($isMaintenance ? 'Scheduled maintenance' : 'Incident'),
            '- **Status:** ' . self::humanize((string) ($event['status'] ?? 'unknown')),
            '- **Impact:** ' . self::humanize((string) ($event['impact'] ?? 'none')),
            '- **Affected components:** ' . (self::affectedComponents($event) ?: 'not listed'),
        ];

        if ($isMaintenance) {
            $lines[] = '- **Scheduled window:** ' . $this->window($event['scheduled_for'] ?? null, $event['scheduled_until'] ?? null);
        } else {
            $started = $this->parse($event['started_at'] ?? $event['created_at'] ?? null);
            $lines[] = '- **Started:** ' . ($started ? $this->both($started) : 'unknown');
        }

        $out = implode("\n", $lines);

        $latest = self::latestUpdate($event);

        if ($latest !== null) {
            $stamp = $this->parse($latest['display_at'] ?? $latest['created_at'] ?? null);
            $out  .= "\n\n**Latest update**"
                . ($stamp ? ' (' . $this->both($stamp) . ')' : '')
                . (isset($latest['status']) ? ' — ' . self::humanize((string) $latest['status']) : '')
                . ":\n\n"
                . trim((string) ($latest['body'] ?? ''));
        }

        if (!empty($event['shortlink'])) {
            $out .= "\n\nSource: " . $event['shortlink'];
        }

        return $out;
    }

    /**
     * Names in the "Group - Name" form where an update lists them
     * (DigitalOcean: "Spaces - SYD1" reads better than a bare "SYD1"),
     * falling back to the bare components[] name. Order follows the
     * event's components[] list.
     */
    public static function affectedComponents(array $event): string
    {
        $qualified = [];

        foreach ((array) ($event['incident_updates'] ?? []) as $update) {
            foreach ((array) ($update['affected_components'] ?? []) as $component) {
                if (is_array($component) && isset($component['code'], $component['name'])) {
                    $qualified[(string) $component['code']] ??= trim((string) $component['name']);
                }
            }
        }

        $names = [];

        foreach ((array) ($event['components'] ?? []) as $component) {
            if (!is_array($component)) {
                continue;
            }

            $name = $qualified[(string) ($component['id'] ?? '')] ?? trim((string) ($component['name'] ?? ''));

            if ($name !== '') {
                $names[$name] = true;
            }
        }

        if ($names === []) {
            foreach ($qualified as $name) {
                $names[$name] = true;
            }
        }

        return implode(', ', array_keys($names));
    }

    /** @return array<string, mixed>|null the newest update by display_at/created_at */
    public static function latestUpdate(array $event): ?array
    {
        $latest = null;
        $latestStamp = null;

        foreach ((array) ($event['incident_updates'] ?? []) as $update) {
            if (!is_array($update)) {
                continue;
            }

            $stamp = strtotime((string) ($update['display_at'] ?? $update['created_at'] ?? '')) ?: 0;

            if ($latest === null || $stamp > $latestStamp) {
                $latest      = $update;
                $latestStamp = $stamp;
            }
        }

        return $latest;
    }

    /** "24 Aug 2026 13:30–20:00 AWST (05:30–12:00 UTC)", or a from–to pair spanning days. */
    public function window(mixed $from, mixed $until): string
    {
        $start = $this->parse($from);
        $end   = $this->parse($until);

        if ($start === null && $end === null) {
            return 'not announced';
        }

        if ($start === null || $end === null) {
            return $this->both($start ?? $end);
        }

        return $this->range($start, $end, $this->displayZone) . ' (' . $this->range($start, $end, $this->utc) . ')';
    }

    /** "24 Aug 2026 13:30 AWST (05:30 UTC)" — the UTC half repeats the date only when it differs. */
    public function both(\DateTimeImmutable $at): string
    {
        $local = $at->setTimezone($this->displayZone);
        $utc   = $at->setTimezone($this->utc);

        $utcPart = $local->format('Y-m-d') === $utc->format('Y-m-d')
            ? $utc->format('H:i T')
            : $utc->format('j M Y H:i T');

        return $local->format('j M Y H:i T') . ' (' . $utcPart . ')';
    }

    private function range(\DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeZone $zone): string
    {
        $s = $start->setTimezone($zone);
        $e = $end->setTimezone($zone);

        if ($s->format('Y-m-d') === $e->format('Y-m-d')) {
            return $s->format('j M Y H:i') . '–' . $e->format('H:i T');
        }

        return $s->format('j M Y H:i') . ' – ' . $e->format('j M Y H:i T');
    }

    private function toStorage(mixed $iso): ?string
    {
        $at = $this->parse($iso);

        return $at?->setTimezone($this->storageZone)->format('Y-m-d H:i:s');
    }

    private function parse(mixed $iso): ?\DateTimeImmutable
    {
        if (!is_string($iso) || trim($iso) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($iso);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function normalizeStamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $ts = strtotime((string) $value);

        return $ts === false ? (string) $value : date('Y-m-d H:i:s', $ts);
    }

    private static function humanize(string $value): string
    {
        return ucfirst(str_replace('_', ' ', trim($value)));
    }
}
