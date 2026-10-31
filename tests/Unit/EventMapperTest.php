<?php
declare(strict_types=1);

namespace Announcements\Tests\Unit;

use Announcements\Feeds\EventMapper;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixtureSupport.php';

final class EventMapperTest extends TestCase
{
    use FixtureSupport;

    private EventMapper $mapper;

    protected function setUp(): void
    {
        // Storage zone pinned to UTC so expires_at expectations don't
        // depend on the machine running the suite.
        $this->mapper = new EventMapper(new \DateTimeZone('UTC'));
    }

    public function testMaintenanceMapsToWarningWithWindowInAwstAndUtcAndExpiresAtScheduledUntil(): void
    {
        $event  = self::event('digitalocean-scheduled-maintenances.json', 'zy2258kz0y91');
        $mapped = $this->mapper->map('digitalocean', self::feeds()['digitalocean'], $event);

        $this->assertSame('statuspage:digitalocean', $mapped['source']);
        $this->assertSame('zy2258kz0y91', $mapped['external_id']);
        $this->assertSame('https://stspg.io/b8b2kz6yc7rr', $mapped['source_url']);
        $this->assertSame('DigitalOcean: Maintenance Window Extended in LON1, SGP1, SYD1.', $mapped['title']);
        $this->assertSame(EventMapper::SEVERITY_WARNING, $mapped['severity']);
        $this->assertSame('notice', $mapped['severity']);
        $this->assertSame('everyone', $mapped['audience']);
        // scheduled_until 2026-08-24T12:00:00Z, storage zone UTC
        $this->assertSame('2026-08-24 12:00:00', $mapped['expires_at']);

        $body = $mapped['body'];
        $this->assertStringContainsString('- **Type:** Scheduled maintenance', $body);
        $this->assertStringContainsString('- **Status:** Completed', $body);
        $this->assertStringContainsString('- **Impact:** Maintenance', $body);
        $this->assertStringContainsString('- **Affected components:** Spaces - SGP1, Spaces - SYD1, Spaces - LON1', $body);
        // 08:30–12:00 UTC is 16:30–20:00 in Australia/Perth (+08:00, no DST)
        $this->assertStringContainsString('- **Scheduled window:** 24 Aug 2026 16:30–20:00 AWST (24 Aug 2026 08:30–12:00 UTC)', $body);
        $this->assertStringContainsString('**Latest update** (24 Aug 2026 20:00 AWST (12:00 UTC)) — Completed:', $body);
        $this->assertStringContainsString('The scheduled maintenance has been completed.', $body);
        $this->assertStringEndsWith('Source: https://stspg.io/b8b2kz6yc7rr', $body);
    }

    public function testMinorIncidentMapsToWarningWithNoExpiry(): void
    {
        $event  = self::event('digitalocean-incidents.json', '84v7d9gkkzzf');
        $mapped = $this->mapper->map('digitalocean', self::feeds()['digitalocean'], $event);

        $this->assertSame('notice', $mapped['severity']);
        $this->assertNull($mapped['expires_at']);
        $this->assertStringContainsString('- **Type:** Incident', $mapped['body']);
        $this->assertStringContainsString('- **Affected components:** Volumes - BLR1, Volumes - NYC1, Volumes - NYC3, Volumes - SGP1, Volumes - SYD1', $mapped['body']);
        $this->assertStringContainsString('- **Started:** 19 Jul 2026 17:51 AWST (09:51 UTC)', $mapped['body']);
        $this->assertStringNotContainsString('Scheduled window', $mapped['body']);
    }

    public function testMajorAndCriticalIncidentsMapToCritical(): void
    {
        $this->assertSame('important', EventMapper::severity(self::event('digitalocean-incidents.json', 'synthetic0001')), 'major');
        $this->assertSame('important', EventMapper::severity(self::event('digitalocean-incidents.json', '4qwm6pzlx1z3')), 'critical');
        $this->assertSame('notice', EventMapper::severity(['impact' => 'none']), 'none');
        $this->assertSame('notice', EventMapper::severity(['impact' => 'minor']), 'minor');
        $this->assertSame('notice', EventMapper::severity(['impact' => 'critical', 'scheduled_for' => '2026-01-01T00:00:00Z']), 'a maintenance is a warning whatever its impact field says');
    }

    public function testIonosTimesAreConvertedFromLondonOffset(): void
    {
        $event  = self::event('ionos-uk-scheduled-maintenances.json', 'm9pq5t86msn5');
        $mapped = $this->mapper->map('ionos-uk', self::feeds()['ionos-uk'], $event);

        $this->assertSame('IONOS UK: Planned Maintenance: Customer APIs (Domains, DNS, SSL)', $mapped['title']);
        // 2026-03-17T07:00+00:00 → 2026-03-18T12:00+00:00 spans two days
        $this->assertStringContainsString('- **Scheduled window:** 17 Mar 2026 15:00 – 18 Mar 2026 20:00 AWST (17 Mar 2026 07:00 – 18 Mar 2026 12:00 UTC)', $mapped['body']);
        $this->assertSame('2026-03-18 12:00:00', $mapped['expires_at']);

        $incident = $this->mapper->map('ionos-uk', self::feeds()['ionos-uk'], self::event('ionos-uk-incidents.json', 'dc2csqp7sfsc'));
        // started_at 2026-09-22T22:36:46+01:00 = 21:36 UTC = 23 Sep 05:36 AWST: the UTC half repeats the date when it differs
        $this->assertStringContainsString('- **Started:** 23 Sep 2026 05:36 AWST (22 Sep 2026 21:36 UTC)', $incident['body']);
    }

    public function testExpiresAtIsWrittenInTheStorageZone(): void
    {
        $perth  = new EventMapper(new \DateTimeZone('Australia/Perth'));
        $mapped = $perth->map('digitalocean', self::feeds()['digitalocean'], self::event('digitalocean-scheduled-maintenances.json', 'zy2258kz0y91'));

        $this->assertSame('2026-08-24 20:00:00', $mapped['expires_at']);
    }

    public function testTitleIsClampedToTheColumnWidth(): void
    {
        $mapped = $this->mapper->map('digitalocean', self::feeds()['digitalocean'], ['id' => 'x', 'name' => str_repeat('a', 300)]);

        $this->assertSame(200, mb_strlen($mapped['title']));
    }

    public function testUpdateDetection(): void
    {
        $event  = self::event('digitalocean-scheduled-maintenances.json', 'zy2258kz0y91');
        $mapped = $this->mapper->map('digitalocean', self::feeds()['digitalocean'], $event);

        $stored = $mapped + ['id' => 7, 'published_at' => '2026-08-24 08:30:00'];
        $this->assertFalse(EventMapper::hasChanges($stored, $mapped), 'identical content is not a change');

        // A later poll after the provider posted a new update.
        $event['updated_at']       = '2026-08-24T13:00:00.000Z';
        $event['incident_updates'] = array_merge([[
            'id' => 'new', 'status' => 'completed', 'body' => 'Post-maintenance validation finished.',
            'display_at' => '2026-08-24T13:00:00.000Z', 'created_at' => '2026-08-24T13:00:00.000Z', 'affected_components' => [],
        ]], $event['incident_updates']);

        $again = $this->mapper->map('digitalocean', self::feeds()['digitalocean'], $event);
        $this->assertTrue(EventMapper::hasChanges($stored, $again));
        $this->assertStringContainsString('Post-maintenance validation finished.', $again['body']);

        // An extended window alone is a change too (expires_at moves).
        $extended                    = $mapped;
        $extended['expires_at']      = '2026-08-24 13:00:00';
        $this->assertTrue(EventMapper::hasChanges($stored, $extended));

        // Postgres hands timestamps back without the seconds' fraction
        // and possibly formatted differently; compare as instants.
        $this->assertFalse(EventMapper::hasChanges($stored + ['expires_at' => '2026-08-24 12:00:00.000000'], $mapped));
    }

    public function testFinishedDetectionAndResolvedAppendix(): void
    {
        $incident    = self::fixture('digitalocean-incident-detail.json')['incident'];
        $maintenance = self::fixture('digitalocean-scheduled-maintenance-detail.json')['scheduled_maintenance'];

        $this->assertTrue(EventMapper::isFinished($incident));
        $this->assertTrue(EventMapper::isFinished($maintenance));
        $this->assertFalse(EventMapper::isFinished(['status' => 'monitoring', 'resolved_at' => null]));
        $this->assertFalse(EventMapper::isFinished(['status' => 'in_progress']));

        $noticed = new \DateTimeImmutable('2026-09-25 02:00:00', new \DateTimeZone('UTC'));

        $this->assertSame("\n\n**Resolved** 19 Jul 2026 23:32 AWST (15:32 UTC).", $this->mapper->resolvedAppendix($incident, $noticed));
        $this->assertSame("\n\n**Resolved** 25 Sep 2026 10:00 AWST (02:00 UTC).", $this->mapper->resolvedAppendix(['status' => 'resolved'], $noticed), 'falls back to the time we noticed');
    }

    public function testLatestUpdateIsTheNewestByDisplayTime(): void
    {
        $event = self::event('digitalocean-scheduled-maintenances.json', 'zy2258kz0y91');
        $event['incident_updates'] = array_reverse($event['incident_updates']);

        $this->assertSame('2pgr9r0wr8kw', EventMapper::latestUpdate($event)['id'], 'order in the list must not matter');
        $this->assertNull(EventMapper::latestUpdate(['incident_updates' => []]));
    }
}
