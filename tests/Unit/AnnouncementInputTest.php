<?php
declare(strict_types=1);

namespace Announcements\Tests\Unit;

use Announcements\Api\AnnouncementInput;
use PHPUnit\Framework\TestCase;

final class AnnouncementInputTest extends TestCase
{
    public function testMissingTitleAndBodyAreBothReported(): void
    {
        $result = AnnouncementInput::validate([]);

        $this->assertSame(['title' => 'title is required', 'body' => 'body is required'], $result['errors']);

        $result = AnnouncementInput::validate(['title' => '   ', 'body' => "\n"]);
        $this->assertArrayHasKey('title', $result['errors']);
        $this->assertArrayHasKey('body', $result['errors']);
    }

    public function testMinimalValidInputGetsTheDefaults(): void
    {
        $result = AnnouncementInput::validate(['title' => ' Hello ', 'body' => 'World']);

        $this->assertSame([], $result['errors']);
        $this->assertSame([
            'title'       => 'Hello',
            'body'        => 'World',
            'severity'    => 'info',
            'audience'    => 'all',
            'publish'     => false,
            'expires_at'  => null,
            'source'      => null,
            'external_id' => null,
            'source_url'  => null,
        ], $result['values']);
    }

    public function testTitleLengthLimit(): void
    {
        $ok = AnnouncementInput::validate(['title' => str_repeat('x', 200), 'body' => 'b']);
        $this->assertSame([], $ok['errors']);

        $long = AnnouncementInput::validate(['title' => str_repeat('x', 201), 'body' => 'b']);
        $this->assertSame('title must be at most 200 characters', $long['errors']['title']);

        $multibyte = AnnouncementInput::validate(['title' => str_repeat('é', 200), 'body' => 'b']);
        $this->assertSame([], $multibyte['errors'], 'limit is in characters, not bytes');
    }

    public function testNonStringFieldsAreRejected(): void
    {
        $result = AnnouncementInput::validate(['title' => ['x'], 'body' => 5, 'source' => 1]);

        $this->assertSame('title must be a string', $result['errors']['title']);
        $this->assertSame('body must be a string', $result['errors']['body']);
        $this->assertSame('source must be a string', $result['errors']['source']);
    }

    public function testSeverityAcceptsLevelsAndAliases(): void
    {
        foreach (['info' => 'info', 'Notice' => 'notice', 'IMPORTANT' => 'important', 'warning' => 'notice', 'critical' => 'important'] as $in => $out) {
            $result = AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'severity' => $in]);
            $this->assertSame([], $result['errors'], $in);
            $this->assertSame($out, $result['values']['severity'], $in);
        }

        $bad = AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'severity' => 'urgent']);
        $this->assertArrayHasKey('severity', $bad['errors']);

        $this->assertNull(AnnouncementInput::normalizeSeverity('major'));
    }

    public function testAudiences(): void
    {
        $audience = fn ($value) => AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'audience' => $value]);

        $this->assertSame('all', AnnouncementInput::validate(['title' => 't', 'body' => 'b'])['values']['audience']);
        $this->assertSame('all', $audience('All')['values']['audience']);
        $this->assertSame('staff', $audience('staff')['values']['audience']);
        $this->assertSame('staff', $audience('Staff-Only')['values']['audience']);
        $this->assertSame('everyone', $audience('everyone')['values']['audience']);
        $this->assertSame('everyone', $audience('customers')['values']['audience']);
        $this->assertSame('all', $audience('all-staff-and-agents')['values']['audience']);
        $this->assertArrayHasKey('audience', $audience('admins')['errors']);
        $this->assertArrayHasKey('audience', $audience(['staff'])['errors']);
    }

    public function testPublishFlagParsing(): void
    {
        foreach ([true, 1, '1', 'true', 'yes', 'on'] as $value) {
            $this->assertTrue(AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'publish' => $value])['values']['publish'], var_export($value, true));
        }

        foreach ([false, 0, '0', 'false', 'no', 'off', ''] as $value) {
            $result = AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'publish' => $value]);
            $this->assertSame([], $result['errors'], var_export($value, true));
            $this->assertFalse($result['values']['publish'], var_export($value, true));
        }

        $this->assertArrayHasKey('publish', AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'publish' => 'maybe'])['errors']);
        $this->assertArrayHasKey('publish', AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'publish' => 2])['errors']);
    }

    public function testExpiresAtFormats(): void
    {
        $this->assertSame('2026-10-01 23:59:59', AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'expires_at' => '2026-10-01'])['values']['expires_at'], 'a bare date is end-of-day');
        $this->assertSame('2026-10-01 10:30:00', AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'expires_at' => '2026-10-01 10:30:00'])['values']['expires_at']);
        $this->assertNull(AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'expires_at' => ''])['values']['expires_at']);

        foreach (['2026-02-30', '2026-10-01T10:30:00Z', '01/10/2026', 'tomorrow', '2026-10-01 25:00:00'] as $bad) {
            $this->assertSame('expires_at must be Y-m-d or Y-m-d H:i:s', AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'expires_at' => $bad])['errors']['expires_at'] ?? null, $bad);
        }
    }

    public function testProvenanceFields(): void
    {
        $ok = AnnouncementInput::validate([
            'title' => 't', 'body' => 'b',
            'source' => 'statuspage:digitalocean', 'external_id' => 'zy2258kz0y91', 'source_url' => 'https://stspg.io/b8b2kz6yc7rr',
        ]);
        $this->assertSame([], $ok['errors']);
        $this->assertSame('statuspage:digitalocean', $ok['values']['source']);
        $this->assertSame('zy2258kz0y91', $ok['values']['external_id']);

        $orphan = AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'external_id' => 'abc']);
        $this->assertSame('source is required when external_id is given', $orphan['errors']['source']);

        $this->assertArrayHasKey('source', AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'source' => 'has space'])['errors']);
        $this->assertArrayHasKey('source', AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'source' => str_repeat('s', 41)])['errors']);
        $this->assertArrayHasKey('external_id', AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'source' => 's', 'external_id' => str_repeat('e', 161)])['errors']);
        $this->assertArrayHasKey('source_url', AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'source_url' => 'javascript:alert(1)'])['errors']);
        $this->assertArrayHasKey('source_url', AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'source_url' => 'ftp://x'])['errors']);

        $blank = AnnouncementInput::validate(['title' => 't', 'body' => 'b', 'source' => '', 'external_id' => null, 'source_url' => '  ']);
        $this->assertSame([], $blank['errors']);
        $this->assertNull($blank['values']['source']);
        $this->assertNull($blank['values']['source_url']);
    }
}
