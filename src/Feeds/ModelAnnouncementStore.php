<?php
declare(strict_types=1);

namespace Announcements\Feeds;

/**
 * AnnouncementStore over the real \Announcement model. Fires the same
 * events the backend controller does — 'announcements:created' when an
 * auto-published event is inserted, 'announcements:updated' when a
 * published one changes or resolves — on the shared eventsBus, so a
 * listener can't tell a poller-authored notice from an admin's. A draft
 * (auto_publish false) fires nothing, exactly like a draft saved from
 * the form. author_user_id stays null: the poller is not a users row.
 */
final class ModelAnnouncementStore implements AnnouncementStore
{
    public function findBySourceKey(string $source, string $externalId): ?array
    {
        $row = \Announcement::findBySourceKey($source, $externalId);

        return $row?->toReadArray();
    }

    public function findOpen(string $source, string $now): array
    {
        $rows = \Announcement::find([
            'conditions' => 'source = :source: AND (expires_at IS NULL OR expires_at > :now:)',
            'bind'       => ['source' => $source, 'now' => $now],
        ]);

        return array_map(fn (\Announcement $a) => $a->toReadArray(), iterator_to_array($rows));
    }

    public function insert(array $fields, bool $publish): int
    {
        $announcement = new \Announcement();

        foreach (['source', 'external_id', 'source_url', 'title', 'body', 'severity', 'expires_at'] as $field) {
            $announcement->$field = $fields[$field] ?? null;
        }

        $announcement->audience     = $fields['audience'] ?? \Announcements\Api\Audience::EVERYONE;
        $announcement->published_at = $publish ? date('Y-m-d H:i:s') : null;

        if (!$announcement->save()) {
            throw new \RuntimeException('announcement insert failed: ' . implode('; ', array_map('strval', $announcement->getMessages())));
        }

        if ($publish) {
            $this->fire('announcements:created', $announcement);
        }

        return (int) $announcement->id;
    }

    public function update(int $id, array $fields): void
    {
        $announcement = \Announcement::findFirstById($id);

        if (!$announcement) {
            return;
        }

        $expiryChanged = false;

        foreach ($fields as $field => $value) {
            if (!in_array($field, EventMapper::updatableFields(), true)) {
                continue;
            }

            if ($field === 'expires_at' && (string) $announcement->expires_at !== (string) $value) {
                $expiryChanged = true;
            }

            $announcement->$field = $value;
        }

        // Same rule as the backend form (AnnouncementsController::
        // fillFromRequest()): a moved expiry is eligible to fire
        // announcements:expired again.
        if ($expiryChanged) {
            $announcement->expired_notified_at = null;
        }

        if (!$announcement->save()) {
            throw new \RuntimeException("announcement {$id} update failed: " . implode('; ', array_map('strval', $announcement->getMessages())));
        }

        if ($announcement->isPublished()) {
            $this->fire('announcements:updated', $announcement);
        }
    }

    private function fire(string $event, \Announcement $announcement): void
    {
        $di = \Phalcon\Di\Di::getDefault();

        if ($di !== null && $di->has('eventsBus')) {
            $di->getShared('eventsBus')->fire($event, $announcement, $announcement->toReadArray());
        }
    }
}
