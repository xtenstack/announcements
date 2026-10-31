<?php
declare(strict_types=1);

namespace Announcements\Tests\Unit;

use Announcements\Feeds\AnnouncementStore;
use Announcements\Feeds\EventMapper;
use Announcements\Feeds\FeedPoller;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixtureSupport.php';

/** In-memory AnnouncementStore with the same open/closed semantics as the model-backed one. */
final class MemoryStore implements AnnouncementStore
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /** @var string[] log of "insert:<external_id>:<published|draft>" / "update:<id>" */
    public array $log = [];

    private int $nextId = 1;

    public function findBySourceKey(string $source, string $externalId): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['source'] === $source && $row['external_id'] === $externalId) {
                return $row;
            }
        }

        return null;
    }

    public function findOpen(string $source, string $now): array
    {
        return array_values(array_filter(
            $this->rows,
            fn ($r) => $r['source'] === $source && ($r['expires_at'] === null || $r['expires_at'] > $now)
        ));
    }

    public function insert(array $fields, bool $publish): int
    {
        $id = $this->nextId++;

        $this->rows[$id] = $fields + ['id' => $id, 'published_at' => $publish ? '2026-09-25 10:00:00' : null];
        $this->log[]     = 'insert:' . $fields['external_id'] . ':' . ($publish ? 'published' : 'draft');

        return $id;
    }

    public function update(int $id, array $fields): void
    {
        $this->rows[$id] = array_merge($this->rows[$id], $fields);
        $this->log[]     = 'update:' . $id;
    }
}

final class FeedPollerTest extends TestCase
{
    use FixtureSupport;

    private const EMPTY_INCIDENTS    = ['incidents' => []];
    private const EMPTY_MAINTENANCES = ['scheduled_maintenances' => []];

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-25 02:00:00', new \DateTimeZone('UTC'));
    }

    private function poller(FixtureClient $client, MemoryStore $store): FeedPoller
    {
        return new FeedPoller($client, $store, new EventMapper(new \DateTimeZone('UTC')));
    }

    /** DigitalOcean: one matching maintenance upcoming, the incident fixtures unresolved. */
    private function digitalOceanClient(): FixtureClient
    {
        return (new FixtureClient())
            ->serveDoc('/api/v2/scheduled-maintenances/upcoming.json', self::fixture('digitalocean-scheduled-maintenances.json'))
            ->serveDoc('/api/v2/scheduled-maintenances/active.json', self::EMPTY_MAINTENANCES)
            ->serveDoc('/api/v2/incidents/unresolved.json', self::fixture('digitalocean-incidents.json'));
    }

    public function testFirstPollInsertsOnlyMatchingEventsAndAutoPublishes(): void
    {
        $store  = new MemoryStore();
        $result = $this->poller($this->digitalOceanClient(), $store)->poll('digitalocean', self::feeds()['digitalocean'], $this->now);

        $this->assertTrue($result['fetched']);
        $this->assertSame([], $result['failures']);
        $this->assertSame(3, $result['matched'], 'maintenance + SGP1/SYD1 incident + body-only synthetic');
        $this->assertSame(3, $result['inserted']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['resolved']);

        $externalIds = array_column($store->rows, 'external_id');
        sort($externalIds);
        $this->assertSame(['84v7d9gkkzzf', 'synthetic0001', 'zy2258kz0y91'], $externalIds);

        foreach ($store->rows as $row) {
            $this->assertNotNull($row['published_at'], 'digitalocean is auto_publish => true');
            $this->assertSame('statuspage:digitalocean', $row['source']);
        }
    }

    public function testDraftFeedInsertsUnpublished(): void
    {
        $feed                 = self::feeds()['digitalocean'];
        $feed['auto_publish'] = false;

        $store = new MemoryStore();
        $this->poller($this->digitalOceanClient(), $store)->poll('digitalocean', $feed, $this->now);

        foreach ($store->rows as $row) {
            $this->assertNull($row['published_at']);
        }
    }

    public function testRepeatPollIsIdempotentAndAnUpdatedEventIsRewritten(): void
    {
        $store  = new MemoryStore();
        $poller = $this->poller($this->digitalOceanClient(), $store);
        $feed   = self::feeds()['digitalocean'];

        $poller->poll('digitalocean', $feed, $this->now);
        $second = $poller->poll('digitalocean', $feed, $this->now);

        $this->assertSame(0, $second['inserted']);
        $this->assertSame(0, $second['updated']);
        $this->assertCount(3, $store->rows);

        // Provider extends the maintenance window and posts an update.
        $maintenances = self::fixture('digitalocean-scheduled-maintenances.json');
        $maintenances['scheduled_maintenances'][0]['scheduled_until'] = '2026-08-24T14:00:00.000Z';
        $maintenances['scheduled_maintenances'][0]['updated_at']      = '2026-08-24T13:00:00.000Z';
        array_unshift($maintenances['scheduled_maintenances'][0]['incident_updates'], [
            'id' => 'u-new', 'status' => 'in_progress', 'body' => 'Window extended to 14:00 UTC.',
            'display_at' => '2026-08-24T13:00:00.000Z', 'created_at' => '2026-08-24T13:00:00.000Z', 'affected_components' => [],
        ]);

        $client = $this->digitalOceanClient()->serveDoc('/api/v2/scheduled-maintenances/upcoming.json', $maintenances);
        $third  = $this->poller($client, $store)->poll('digitalocean', $feed, $this->now);

        $this->assertSame(1, $third['updated']);
        $this->assertSame(0, $third['inserted']);

        $row = $store->findBySourceKey('statuspage:digitalocean', 'zy2258kz0y91');
        $this->assertSame('2026-08-24 14:00:00', $row['expires_at']);
        $this->assertStringContainsString('Window extended to 14:00 UTC.', $row['body']);
        $this->assertNotNull($row['published_at'], 'an update never touches published_at');
    }

    public function testEventThatDisappearsIsResolvedViaDetailLookup(): void
    {
        $store = new MemoryStore();
        $feed  = self::feeds()['digitalocean'];

        $this->poller($this->digitalOceanClient(), $store)->poll('digitalocean', $feed, $this->now);

        // Next poll: the SGP1/SYD1 incident is gone from unresolved.json;
        // its detail endpoint reports it resolved. The synthetic one is
        // gone too but its detail lookups 404 on both endpoints
        // (provider deleted it) — that is a failure, not a resolution.
        $incidents = self::fixture('digitalocean-incidents.json');
        $incidents['incidents'] = array_values(array_filter($incidents['incidents'], fn ($i) => !in_array($i['id'], ['84v7d9gkkzzf', 'synthetic0001'], true)));

        $client = $this->digitalOceanClient()
            ->serveDoc('/api/v2/incidents/unresolved.json', $incidents)
            ->serveDoc('/api/v2/incidents/84v7d9gkkzzf.json', self::fixture('digitalocean-incident-detail.json'));

        $result = $this->poller($client, $store)->poll('digitalocean', $feed, $this->now);

        $this->assertSame(1, $result['resolved']);
        $this->assertCount(1, $result['failures']);
        $this->assertStringContainsString('synthetic0001', $result['failures'][0]);

        $resolved = $store->findBySourceKey('statuspage:digitalocean', '84v7d9gkkzzf');
        $this->assertSame('2026-09-25 02:00:00', $resolved['expires_at'], 'expires_at = now on resolution');
        $this->assertStringEndsWith("**Resolved** 19 Jul 2026 23:32 AWST (15:32 UTC).", $resolved['body']);

        $deleted = $store->findBySourceKey('statuspage:digitalocean', 'synthetic0001');
        $this->assertNull($deleted['expires_at'], 'left open for a human');

        // The maintenance is still listed (upcoming) — its window is in
        // the past relative to $now but it was never asked about.
        $requested = implode("\n", $client->requested);
        $this->assertStringNotContainsString('zy2258kz0y91.json', $requested);

        // Once resolved it is closed: a further poll asks nothing about it.
        $client->requested = [];
        $again = $this->poller($client, $store)->poll('digitalocean', $feed, $this->now);
        $this->assertSame(0, $again['resolved']);
        $this->assertStringNotContainsString('84v7d9gkkzzf.json', implode("\n", $client->requested));
    }

    public function testMaintenanceDetailIsTriedWhenTheIncidentEndpointIs404(): void
    {
        $store = new MemoryStore();
        $feed  = self::feeds()['digitalocean'];

        $this->poller($this->digitalOceanClient(), $store)->poll('digitalocean', $feed, $this->now);

        // The maintenance's expires_at (its scheduled_until, Aug 2026)
        // is before $now, so it is not "open" any more: use a $now
        // inside its window to exercise the drop-out path.
        $duringWindow = new \DateTimeImmutable('2026-08-24 11:00:00', new \DateTimeZone('UTC'));

        $client = $this->digitalOceanClient()
            ->serveDoc('/api/v2/scheduled-maintenances/upcoming.json', self::EMPTY_MAINTENANCES)
            ->serveDoc('/api/v2/scheduled-maintenances/zy2258kz0y91.json', self::fixture('digitalocean-scheduled-maintenance-detail.json'));

        $result = $this->poller($client, $store)->poll('digitalocean', $feed, $duringWindow);

        $this->assertSame(1, $result['resolved']);
        $this->assertContains('https://status.digitalocean.com/api/v2/incidents/zy2258kz0y91.json', $client->requested, 'incident endpoint tried first');
        $this->assertContains('https://status.digitalocean.com/api/v2/scheduled-maintenances/zy2258kz0y91.json', $client->requested);

        $row = $store->findBySourceKey('statuspage:digitalocean', 'zy2258kz0y91');
        $this->assertSame('2026-08-24 11:00:00', $row['expires_at']);
        $this->assertStringContainsString('**Resolved** 24 Aug 2026 20:00 AWST (12:00 UTC).', $row['body']);
    }

    public function testOneFailingEndpointIsLoggedAndSkipsTheResolutionPass(): void
    {
        $store = new MemoryStore();
        $feed  = self::feeds()['digitalocean'];

        $this->poller($this->digitalOceanClient(), $store)->poll('digitalocean', $feed, $this->now);

        $client = $this->digitalOceanClient()
            ->serveDoc('/api/v2/incidents/unresolved.json', self::EMPTY_INCIDENTS)
            ->serveStatus('/api/v2/scheduled-maintenances/active.json', 503, 'upstream down');

        $result = $this->poller($client, $store)->poll('digitalocean', $feed, $this->now);

        $this->assertTrue($result['fetched']);
        $this->assertCount(1, $result['failures']);
        $this->assertStringContainsString('HTTP 503', $result['failures'][0]);
        $this->assertSame(0, $result['resolved'], 'no detail lookups while a list is missing');
        $this->assertStringNotContainsString('84v7d9gkkzzf.json', implode("\n", $client->requested));
        $this->assertSame(1, $result['matched'], 'the lists that did load are still processed');
    }

    public function testWholeFeedDownProducesFailuresNotExceptions(): void
    {
        $client = (new FixtureClient())
            ->failTransport('/api/v2/scheduled-maintenances/upcoming.json')
            ->failTransport('/api/v2/scheduled-maintenances/active.json')
            ->serveStatus('/api/v2/incidents/unresolved.json', 200, 'not json');

        $store  = new MemoryStore();
        $result = $this->poller($client, $store)->poll('digitalocean', self::feeds()['digitalocean'], $this->now);

        $this->assertFalse($result['fetched']);
        $this->assertCount(3, $result['failures']);
        $this->assertStringContainsString('simulated timeout', $result['failures'][0]);
        $this->assertStringContainsString('Invalid JSON', $result['failures'][2]);
        $this->assertSame([], $store->rows);
    }

    public function testIonosFeedAgainstItsFixtures(): void
    {
        $client = (new FixtureClient())
            ->serveDoc('/api/v2/scheduled-maintenances/upcoming.json', self::fixture('ionos-uk-scheduled-maintenances.json'))
            ->serveDoc('/api/v2/scheduled-maintenances/active.json', self::EMPTY_MAINTENANCES)
            ->serveDoc('/api/v2/incidents/unresolved.json', self::fixture('ionos-uk-incidents.json'));

        $store  = new MemoryStore();
        $result = $this->poller($client, $store)->poll('ionos-uk', self::feeds()['ionos-uk'], $this->now);

        $this->assertSame(2, $result['matched'], 'DNS/SSL maintenance + Sending Emails incident; WordPress and SAP events ignored');
        $this->assertSame(2, $result['inserted']);

        $titles = array_column($store->rows, 'title');
        sort($titles);
        $this->assertSame(['IONOS UK: Planned Maintenance: Customer APIs (Domains, DNS, SSL)', 'IONOS UK: Sending emails may be delayed'], $titles);
    }
}
