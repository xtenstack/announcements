# Announcements (`announcements`)

A single company-wide notice feed for an [XTen.Stack](https://github.com/xtenstack/app_skeleton)
instance: policy changes, maintenance windows, anything staff, agents or
customers should factor into their own routines. Admins publish; everyone
with access to the instance reads what is addressed to them; an API lets
other systems read and (with an admin key) post; a poller mirrors relevant
events from providers' public status pages.

Plugin tier, module key `announcements`, code `ann`, Composer package
`xtendeploy/announcements`, PHP namespace `Announcements\` → `src/`. Runs on
PostgreSQL, MySQL/MariaDB and SQLite (three migration dirs).

**Free, MIT-licensed** (`LICENSE`): no licence key, `"license":
{"keyRequired": false}`. The MIT licence covers the code only; it does not
cover the XTen name or logo, and the software comes with no warranty (see
`NOTICE`). Released in its own public repository, `xtenstack/announcements`.

## Install

On an app_skeleton instance (its `docs/INTERNAL-MODULES.md` explains
`composer.local.json`): a `vcs` repository pointing at this repository, or a
`path` repository for a local checkout, plus the package in `require`:

```json
{
    "repositories": [ { "type": "vcs", "url": "https://github.com/xtenstack/announcements" } ],
    "require": { "xtendeploy/announcements": "^1.0" }
}
```

```bash
composer update xtendeploy/announcements
./run migrate run           # announcements:001_announcements, 002_announcements_sources
./run modules sync
./run modules enable announcements
```

Requires PHP 8.3, Phalcon 5.8 and `league/commonmark` ^2.4 (pulled in by
Composer; it renders the Markdown body). No other module is needed: the
plugin depends on the platform only and works on a bare Stack.

Tables: `announcements` (title, Markdown body, severity, audience, author,
`published_at`, `expires_at`, soft delete, and the `source` / `external_id` /
`source_url` of a mirrored event). Migration 002 seeds one cron row,
`announcements-feeds` / `poll`, every 30 minutes (see "Provider status feeds").

## Screens

Menu entry **Announcements** (`/announcements/announcements`), for every
signed-in backend user:

- **Feed** (`index`): published, unexpired announcements the reader is in the
  audience of, newest first, top ten.
- **History** (`history`): every announcement with severity, date and search
  filters, sorting and paging. Drafts show to admins and operators only.
- **View** (`view/{id}`): one announcement; a draft's URL is a 404 to anyone
  who cannot see drafts.
- **New / Edit / Publish / Delete** and the bulk actions: **admin only**
  (per action, so operators and agents still read the feed).

Audience (`Announcements\Api\Audience`): `everyone` (customers included),
`all` (all staff and agents: admin, operator, agent, charter_agent), `staff`
(admin and operator). A principal sees the rows addressed to a group it is
in; a customer sees `everyone` only. Severity: `info`, `warning`, `critical`
(aliases accepted on the API). An expired announcement leaves the feed and the
API's default view but stays in History; deletion is a soft delete.

The one page script, `assets/announcements.js`, is served by the plugin
itself (`/announcements/assets/js/...`), so it works under the platform's
`script-src 'self'` policy.

## API

Same API-key authentication as the platform's own API (`X-Api-Key`,
`ApiControllerBase`):

| | Who | What |
|---|---|---|
| `GET /api/announcements` | any principal | the announcements addressed to the key's roles; `?severity=`, `?active=1` (default: published and unexpired only), `?audience=` |
| `GET /api/announcements/{id}` | any principal | one announcement; drafts to admins only |
| `POST /api/announcements` | **admin keys only** (operator and agent keys get 403) | JSON `title`, `body` (Markdown), optional `severity`, `audience`, `expires_at` (`Y-m-d` or `Y-m-d H:i:s`), `publish` (default false: a draft an admin publishes by hand), and `source` + `external_id` + `source_url` for a mirrored item. Idempotent on (`source`, `external_id`): a repeat updates the existing row and answers 200 instead of 201. Validation rules in `Announcements\Api\AnnouncementInput` |

Events on the platform's shared events bus, for other modules to listen to:
`announcements:created`, `announcements:updated`, `announcements:expired`
(the last fired once per announcement, the first time a read path notices it
has expired).

## Provider status feeds

`./run announcements-feeds poll` (the seeded cron row) reads each
[Statuspage.io](https://www.atlassian.com/software/statuspage) site listed in
`src/Feeds/feeds.php` through its public `/api/v2/` JSON and mirrors the
incidents and scheduled maintenances that match that entry's rule (keywords,
or exact component names) into announcements, updated as the provider
updates them, published at once when the entry says `auto_publish`. The
shipped list has DigitalOcean (regions SYD1, SGP1, LON1) and IONOS UK: edit
or replace the array for your own providers; adding one is one array entry
and no code (the file's header documents the keys). An instance that wants no
mirroring disables the cron row.

## Tests

```bash
# pure PHP (no stack): input validation, audiences, the feed matcher and poller
<app_skeleton>/vendor/bin/phpunit -c <announcements>/phpunit.unit.xml

# screens and API over real HTTP, from an app_skeleton root with the plugin enabled
APP_TEST_BASE_URL=http://127.0.0.1:8080 vendor/bin/phpunit -c vendor/xtendeploy/announcements/phpunit.xml
```

`tests/fixtures/statuspage/` holds recorded public responses of the two
shipped providers' status pages (25 September 2026) for the unit suite.

## Changes

See `CHANGELOG.md`.
