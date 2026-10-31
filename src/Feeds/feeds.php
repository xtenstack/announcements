<?php
declare(strict_types=1);

/**
 * Provider status feeds polled by AnnouncementsFeedsTask (cron
 * 'announcements-feeds'/'poll', seeded by migration 002). Every entry is
 * a Statuspage.io site (Atlassian's hosted status pages — DigitalOcean,
 * IONOS and most other providers use it), read through its public
 * /api/v2/ JSON, so adding a provider is one more array entry here plus
 * a matching rule — no code.
 *
 * Keys:
 *   label        Provider name used as the announcement title prefix
 *                ("DigitalOcean: <event name>").
 *   base_url     The status page's origin, no trailing slash.
 *   match        Exactly one of:
 *                  'keywords'   => [...]  an event is relevant when any
 *                                 affected component's name, the event
 *                                 name, or any update body mentions one
 *                                 of these as a whole word
 *                                 (case-insensitive);
 *                  'components' => [...]  an event is relevant when any
 *                                 affected component's name is exactly
 *                                 one of these (case-insensitive; the
 *                                 "Group - Name" form Statuspage uses in
 *                                 incident_updates[].affected_components
 *                                 matches on the part after the dash).
 *   auto_publish true: a matched event is published the moment it is
 *                inserted (published_at = now), so it reaches the feed
 *                and GET /api/announcements without anyone pressing
 *                Publish. false: inserted as a draft for an admin to
 *                publish by hand. The two feeds below are trusted
 *                provider pages, so they auto-publish; a new feed should
 *                start at false until its noise level is known.
 *
 * Component naming, learned from each page's /api/v2/components.json
 * (saved under tests/fixtures/statuspage/ on 25 Sep 2026):
 *   - DigitalOcean names region components with the bare region code
 *     ("SYD1", "SGP1", "LON1") nested under a product group ("Spaces",
 *     "Droplets", "Managed Databases", ...); an event's own components[]
 *     list carries the bare code, while its incident_updates[].
 *     affected_components[] carries "Spaces - SYD1". Hence keywords, not
 *     an exact component list — and the same rule catches an event whose
 *     name/body mentions the region without listing the component.
 *   - IONOS UK names components exactly as listed below ("VPS", "Cloud
 *     Panel", "Sending Emails", ...), grouped under "Server & Cloud",
 *     "Email", "Domains", "Hosting" etc. "Domains" itself is a group, so
 *     it's unlikely to appear as an event component, but it is kept in
 *     the allow-list as asked.
 */
return [
    'digitalocean' => [
        'label'        => 'DigitalOcean',
        'base_url'     => 'https://status.digitalocean.com',
        'match'        => ['keywords' => ['SYD1', 'SGP1', 'LON1']],
        'auto_publish' => true,
    ],
    'ionos-uk' => [
        'label'        => 'IONOS UK',
        'base_url'     => 'https://www.ionos-status.co.uk',
        'match'        => ['components' => [
            'VPS',
            'Virtual Server',
            'Cloud Server',
            'Dedicated Server',
            'Managed Dedicated Server',
            'DNS',
            'Web Hosting',
            'Sending Emails',
            'Receiving Emails',
            'Cloud Backup',
            'IONOS Login',
            'Cloud Panel',
            'SSL',
            'Domains',
        ]],
        'auto_publish' => true,
    ],
];
