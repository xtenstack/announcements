-- Announcements: a single company-wide notice feed (policy changes,
-- maintenance windows, anything staff and agents should factor into
-- their own routines). author_user_id covers both human and AI-agent
-- authors uniformly — agents are ordinary `users` rows with the `agent`
-- role, same convention as tickets.reporter_user_id (see 011_tickets.sql)
-- — so no separate identity system is needed here either. Nullable
-- because a soft-deleted author shouldn't take an announcement down with
-- them (ON DELETE not used — soft delete is the only delete path in this
-- codebase, see docs/CODING-STANDARDS.md).
--
-- audience is a real column from day one even though v1 only ever writes
-- 'all' — role/module-scoped audiences are the natural v2 (see the
-- module's plan doc, "Data model" section, open question 1 is
-- markdown-vs-rich-text, not this), and a column that already exists is
-- one less migration when that lands. No CHECK constraint yet since the
-- v2 shape (a single role name? a list? a module key?) isn't decided.
--
-- published_at is nullable: null means "still a draft", set once via
-- AnnouncementsController::publishAction() (admin-only, fires
-- announcements:created — see that controller's docblock). There is no
-- scheduling in v1 (P3, not built) — published_at is always "now" the
-- moment an admin publishes, never a future date.
--
-- expires_at is nullable — a maintenance-window notice can auto-retire,
-- a standing policy notice never does. Per the module plan's open
-- question 2 (recommendation taken): an expired announcement auto-hides
-- from the default feed/API view but stays in the full history list, so
-- soft-delete (deleted_at) stays reserved for "an admin actually deleted
-- this", not for ordinary expiry.
--
-- expired_notified_at is bookkeeping, not part of the module plan's own
-- data model list — it exists solely so
-- Announcement::detectAndFireExpirations() (called at the top of every
-- read path, see that method's docblock) can fire 'announcements:expired'
-- exactly once per announcement instead of once per request. There is no
-- cron in v1 (see the module plan's Phasing section — P1 has no
-- scheduler), so "expired" is only ever detected the next time something
-- reads the feed/API/history, and this column is what makes that
-- idempotent.
CREATE TABLE announcements (
    id                   SERIAL PRIMARY KEY,
    title                VARCHAR(200) NOT NULL,
    body                 TEXT NOT NULL,
    author_user_id       INTEGER,
    audience             VARCHAR(20) NOT NULL DEFAULT 'all',
    severity             VARCHAR(20) NOT NULL DEFAULT 'info',
    published_at         TIMESTAMP,
    expires_at           TIMESTAMP,
    expired_notified_at  TIMESTAMP,
    deleted_at           TIMESTAMP,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (author_user_id) REFERENCES users(id)
);
