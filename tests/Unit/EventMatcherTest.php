<?php
declare(strict_types=1);

namespace Announcements\Tests\Unit;

use Announcements\Feeds\EventMatcher;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixtureSupport.php';

/**
 * Matching rules against real events saved from both status pages on
 * 25 Sep 2026 (tests/fixtures/statuspage/). Only synthetic0001 is
 * hand-made: DigitalOcean's history had no live example of an event
 * that names a target region in an update body but not in its name or
 * components, so one was derived from a real non-matching incident.
 */
final class EventMatcherTest extends TestCase
{
    use FixtureSupport;

    public function testDigitalOceanComponentNamesAreBareRegionCodesGroupedUnderProducts(): void
    {
        $components = self::fixture('digitalocean-components.json')['components'];
        $byId       = array_column($components, null, 'id');

        $regionRows = array_values(array_filter($components, fn ($c) => in_array($c['name'], ['SYD1', 'SGP1', 'LON1'], true)));

        $this->assertNotEmpty($regionRows, 'expected bare SYD1/SGP1/LON1 component rows');

        foreach ($regionRows as $row) {
            $this->assertFalse($row['group'], 'a region row is a leaf, not a group');
            $this->assertNotNull($row['group_id']);
            $this->assertTrue($byId[$row['group_id']]['group'], 'a region row sits under a product group');
        }

        $groups = array_unique(array_map(fn ($r) => $byId[$r['group_id']]['name'], $regionRows));
        $this->assertContains('Spaces', $groups);
        $this->assertContains('Droplets', $groups);
    }

    public function testIonosComponentNamesMatchTheAllowListExactly(): void
    {
        $names   = array_column(self::fixture('ionos-uk-components.json')['components'], 'name');
        $allowed = self::feeds()['ionos-uk']['match']['components'];

        foreach ($allowed as $name) {
            $this->assertContains($name, $names, "allow-listed '{$name}' should be a real IONOS UK component name");
        }
    }

    public function testDigitalOceanMaintenanceListingTargetRegionsMatches(): void
    {
        $event = self::event('digitalocean-scheduled-maintenances.json', 'zy2258kz0y91');

        $this->assertTrue(EventMatcher::matches(self::feeds()['digitalocean'], $event));
        $this->assertContains('Spaces - SYD1', EventMatcher::componentNames($event));
        $this->assertContains('SYD1', EventMatcher::componentNames($event));
    }

    public function testDigitalOceanIncidentWithTargetRegionComponentMatches(): void
    {
        $event = self::event('digitalocean-incidents.json', '84v7d9gkkzzf');

        $this->assertTrue(EventMatcher::matches(self::feeds()['digitalocean'], $event));
    }

    public function testDigitalOceanIncidentMentioningRegionOnlyInUpdateBodyMatches(): void
    {
        $event = self::event('digitalocean-incidents.json', 'synthetic0001');

        $this->assertSame([], $event['components']);
        $this->assertStringNotContainsString('SYD1', $event['name']);
        $this->assertTrue(EventMatcher::matches(self::feeds()['digitalocean'], $event));
    }

    public function testDigitalOceanIncidentsElsewhereDoNotMatch(): void
    {
        foreach (['4qwm6pzlx1z3', 'grb2v0td1nq5'] as $id) {
            $this->assertFalse(EventMatcher::matches(self::feeds()['digitalocean'], self::event('digitalocean-incidents.json', $id)), $id);
        }
    }

    public function testKeywordMatchIsWholeWordAndCaseInsensitive(): void
    {
        $feed = self::feeds()['digitalocean'];

        $this->assertTrue(EventMatcher::matches($feed, ['name' => 'Networking in syd1 (degraded)']));
        $this->assertTrue(EventMatcher::matches($feed, ['name' => 'x', 'incident_updates' => [['body' => 'Regions: NYC3, LON1.']]]));
        $this->assertFalse(EventMatcher::matches($feed, ['name' => 'Cluster SYD10 degraded']), 'SYD1 must not match inside SYD10');
        $this->assertFalse(EventMatcher::matches($feed, ['name' => 'ASGP1 rollout']), 'SGP1 must not match inside ASGP1');
        $this->assertFalse(EventMatcher::matches($feed, []));
    }

    public function testIonosIncidentOnAllowListedComponentMatches(): void
    {
        $event = self::event('ionos-uk-incidents.json', 'nht6wrmm6txk');

        $this->assertContains('Sending Emails', array_column($event['components'], 'name'));
        $this->assertTrue(EventMatcher::matches(self::feeds()['ionos-uk'], $event));
    }

    public function testIonosIncidentOnOtherComponentsDoesNotMatch(): void
    {
        $event = self::event('ionos-uk-incidents.json', 'dc2csqp7sfsc');

        $this->assertSame(['Wordpress Hosting', 'Wordpress Pro'], array_column($event['components'], 'name'));
        $this->assertFalse(EventMatcher::matches(self::feeds()['ionos-uk'], $event));
    }

    public function testIonosMaintenanceMatching(): void
    {
        $feed = self::feeds()['ionos-uk'];

        $this->assertTrue(EventMatcher::matches($feed, self::event('ionos-uk-scheduled-maintenances.json', 'm9pq5t86msn5')), 'DNS/SSL maintenance');
        $this->assertFalse(EventMatcher::matches($feed, self::event('ionos-uk-scheduled-maintenances.json', 'hfjq579wjwg0')), 'Support Tool/Billing maintenance');
    }

    public function testComponentMatchAcceptsGroupPrefixedAffectedComponentNames(): void
    {
        $feed  = self::feeds()['ionos-uk'];
        $event = [
            'name'             => 'Server outage',
            'components'       => [],
            'incident_updates' => [['body' => '...', 'affected_components' => [['code' => 'x', 'name' => 'Server & Cloud - VPS']]]],
        ];

        $this->assertTrue(EventMatcher::matches($feed, $event));

        $event['incident_updates'][0]['affected_components'][0]['name'] = 'Hosting - Wordpress Hosting';
        $this->assertFalse(EventMatcher::matches($feed, $event));
    }

    public function testComponentMatchIsExactNotSubstring(): void
    {
        $feed = self::feeds()['ionos-uk'];

        $this->assertFalse(EventMatcher::matches($feed, ['components' => [['name' => 'Web Hosting Windows']]]), 'Web Hosting Windows is a different component');
        $this->assertTrue(EventMatcher::matches($feed, ['components' => [['name' => 'web hosting']]]), 'case-insensitive');
    }
}
