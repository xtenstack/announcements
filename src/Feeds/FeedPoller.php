<?php
declare(strict_types=1);

namespace Announcements\Feeds;

/**
 * One poll of one feed: fetch the three "what's current" lists, upsert
 * every matching event, then check whether any stored open event has
 * dropped out of those lists and, if so, whether it finished. Framework-
 * free; the cron task (AnnouncementsFeedsTask) supplies the real client
 * and store and prints the totals.
 */
final class FeedPoller
{
    /** endpoint => the list key Statuspage wraps its events in */
    public const ENDPOINTS = [
        'scheduled-maintenances/upcoming.json' => 'scheduled_maintenances',
        'scheduled-maintenances/active.json'   => 'scheduled_maintenances',
        'incidents/unresolved.json'            => 'incidents',
    ];

    public function __construct(
        private StatuspageClient $client,
        private AnnouncementStore $store,
        private EventMapper $mapper,
    ) {
    }

    /**
     * @param array<string, mixed> $feed one feeds.php entry
     * @return array{feed: string, fetched: bool, matched: int, inserted: int, updated: int,
     *               resolved: int, failures: string[]}
     */
    public function poll(string $feedKey, array $feed, ?\DateTimeImmutable $now = null): array
    {
        $now    = $now ?? new \DateTimeImmutable('now');
        $source = EventMapper::source($feedKey);
        $base   = rtrim((string) $feed['base_url'], '/');

        $result = ['feed' => $feedKey, 'fetched' => false, 'matched' => 0, 'inserted' => 0, 'updated' => 0, 'resolved' => 0, 'failures' => []];

        $events   = [];
        $complete = true;

        foreach (self::ENDPOINTS as $path => $listKey) {
            $list = $this->client->fetchEvents($base, $path, $listKey);

            if ($list === null) {
                $complete            = false;
                $result['failures'][] = "{$feedKey} {$path}: " . $this->client->lastError();

                continue;
            }

            $result['fetched'] = true;

            foreach ($list as $event) {
                $events[(string) $event['id']] = $event;
            }
        }

        foreach ($events as $event) {
            if (!EventMatcher::matches($feed, $event)) {
                continue;
            }

            $result['matched']++;

            $mapped   = $this->mapper->map($feedKey, $feed, $event);
            $existing = $this->store->findBySourceKey($source, $mapped['external_id']);

            if ($existing === null) {
                $this->store->insert($mapped, (bool) ($feed['auto_publish'] ?? false));
                $result['inserted']++;
            } elseif (EventMapper::hasChanges($existing, $mapped)) {
                $this->store->update((int) $existing['id'], array_intersect_key($mapped, array_flip(EventMapper::updatableFields())));
                $result['updated']++;
            }
        }

        // Only when every list came back: a missing list would make its
        // events look "gone" and trigger a detail fetch for each of them.
        if (!$complete) {
            return $result;
        }

        foreach ($this->store->findOpen($source, $now->format('Y-m-d H:i:s')) as $row) {
            $externalId = (string) ($row['external_id'] ?? '');

            if ($externalId === '' || isset($events[$externalId])) {
                continue;
            }

            $detail = $this->client->fetchDetail($base, $externalId);

            if ($detail === null) {
                $result['failures'][] = "{$feedKey} detail {$externalId}: " . $this->client->lastError();

                continue;
            }

            if (!EventMapper::isFinished($detail)) {
                continue;
            }

            $this->store->update((int) $row['id'], [
                'body'       => rtrim((string) $row['body']) . $this->mapper->resolvedAppendix($detail, $now),
                'expires_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $result['resolved']++;
        }

        return $result;
    }
}
