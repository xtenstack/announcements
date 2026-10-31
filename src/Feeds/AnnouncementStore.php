<?php
declare(strict_types=1);

namespace Announcements\Feeds;

/**
 * The poller's only view of the announcements table, so FeedPoller can
 * be unit-tested end to end against an in-memory implementation while
 * ModelAnnouncementStore is what the cron task actually wires in.
 *
 * Rows are plain arrays with at least: id, title, body, severity,
 * expires_at, published_at, source, external_id, source_url.
 */
interface AnnouncementStore
{
    /** @return array<string, mixed>|null the live (not soft-deleted) row for this event, if any */
    public function findBySourceKey(string $source, string $externalId): ?array;

    /**
     * Live rows for this source that are still current — no expires_at,
     * or one still in the future. A resolved incident (expires_at set to
     * the moment it finished) and a maintenance whose window has passed
     * are both excluded, so the "has it finished?" lookup is only ever
     * made for events we still consider open.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findOpen(string $source, string $now): array;

    /**
     * @param array<string, mixed> $fields EventMapper::map()'s output
     * @return int the new row's id
     */
    public function insert(array $fields, bool $publish): int;

    /** @param array<string, mixed> $fields a subset of EventMapper::updatableFields() */
    public function update(int $id, array $fields): void;
}
