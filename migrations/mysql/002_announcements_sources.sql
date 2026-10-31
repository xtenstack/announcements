-- MySQL/MariaDB port of postgresql/002_announcements_sources.sql; keep the two in step.
-- Provenance for machine-authored announcements (REQ-226 follow-up):
-- the provider status-feed poller (AnnouncementsFeedsTask, cron) and the
-- POST /api/announcements create endpoint both need a stable key to
-- upsert against, so the same upstream event never becomes two rows.
--
-- source is a short namespace ("statuspage:digitalocean",
-- "statuspage:ionos-uk", or whatever an API caller passes), external_id
-- the event's own id within that namespace, source_url a link back to
-- the upstream page (Statuspage shortlink). All three are nullable — a
-- human-authored announcement from the backend form has none of them.
--
-- The unique index is partial: only live rows with an external_id take
-- part, so a soft-deleted row never blocks the same event from being
-- re-created, and rows without provenance are unconstrained.
--
-- MySQL/MariaDB have no partial indexes. Same rule via a stored generated
-- column that holds external_id only while the row is live (NULL once
-- soft-deleted, and NULL anyway for rows without one); a UNIQUE index
-- allows any number of NULLs, so (source, external_id_live) is unique
-- among live rows with provenance only, exactly as in Postgres. Unlike
-- Postgres, the comparison is case-insensitive (utf8mb4_unicode_ci), as
-- findBySourceKey()'s lookup already is on MySQL. Nothing writes
-- external_id_live: the model declares no such property, and Phalcon 5
-- leaves it out of INSERT/UPDATE (checked 27 Sep on MySQL 8.4; an
-- explicit value, even NULL, would fail with error 3105).
ALTER TABLE announcements ADD COLUMN source      VARCHAR(40);
ALTER TABLE announcements ADD COLUMN external_id VARCHAR(160);
ALTER TABLE announcements ADD COLUMN source_url  TEXT;
ALTER TABLE announcements
    ADD COLUMN external_id_live VARCHAR(160) AS (CASE WHEN deleted_at IS NULL THEN external_id END) STORED;

CREATE UNIQUE INDEX announcements_source_external_id_live
    ON announcements (source, external_id_live);

-- One cron row for the poller, seeded here rather than via `./run seed`
-- because SeedTask only knows the base engine's own jobs. CronRunner
-- resolves task 'announcements-feeds' + action 'poll' to
-- App_skeleton\Modules\Cli\Tasks\AnnouncementsFeedsTask::pollAction()
-- (autoloaded from this plugin's src/tasks/ via its composer psr-4
-- entry, same mechanism marketing-module's tasks use). Idempotent: a
-- re-run of this migration on an instance that already has the row (or
-- an operator who created it by hand first) inserts nothing.
--
-- FROM DUAL: MySQL doesn't accept a WHERE on a SELECT with no FROM.
INSERT INTO cron_jobs (name, task, task_action, frequency, enabled)
SELECT 'Announcements: provider status feeds', 'announcements-feeds', 'poll', '+30 minutes', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM cron_jobs WHERE task = 'announcements-feeds');
