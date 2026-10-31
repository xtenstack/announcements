-- SQLite port of postgresql/002_announcements_sources.sql; keep the two in step.
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
ALTER TABLE announcements ADD COLUMN source      VARCHAR(40);
ALTER TABLE announcements ADD COLUMN external_id VARCHAR(160);
ALTER TABLE announcements ADD COLUMN source_url  TEXT;

CREATE UNIQUE INDEX announcements_source_external_id_live
    ON announcements (source, external_id)
    WHERE deleted_at IS NULL AND external_id IS NOT NULL;

-- One cron row for the poller, seeded here rather than via `./run seed`
-- because SeedTask only knows the base engine's own jobs. CronRunner
-- resolves task 'announcements-feeds' + action 'poll' to
-- App_skeleton\Modules\Cli\Tasks\AnnouncementsFeedsTask::pollAction()
-- (autoloaded from this plugin's src/tasks/ via its composer psr-4
-- entry, same mechanism marketing-module's tasks use). Idempotent: a
-- re-run of this migration on an instance that already has the row (or
-- an operator who created it by hand first) inserts nothing.
INSERT INTO cron_jobs (name, task, task_action, frequency, enabled)
SELECT 'Announcements: provider status feeds', 'announcements-feeds', 'poll', '+30 minutes', 1
WHERE NOT EXISTS (SELECT 1 FROM cron_jobs WHERE task = 'announcements-feeds');
