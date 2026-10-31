<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * POST /api/announcements and GET /api/announcements/{id} — real HTTP
 * against the running app_skeleton stack, same conventions as
 * AnnouncementsTest (see that class and tests/bootstrap.php for why
 * these can't run in-process). Needs the Docker stack, `./run migrate
 * run` (migration 002) and `./run seed`; the validation rules
 * themselves are covered without the stack by
 * tests/Unit/AnnouncementInputTest.php.
 *
 * Proves:
 *  - agent- and operator-role API keys get 403 on POST (read stays
 *    allowed); creating is admin only (27 Sep 2026 decision);
 *  - an admin key gets 422 with per-field errors on a bad body;
 *  - an admin key can create (201), published when asked, and the
 *    row then shows in GET /api/announcements and GET /api/announcements/{id};
 *  - a second POST with the same (source, external_id) updates the same
 *    row and answers 200, not a duplicate;
 *  - a draft is a 404 on its own URL for an agent key, 200 for an admin.
 */
final class AnnouncementsCreateApiTest extends TestCase
{
    private static string $password = 'PhpunitTest123!';

    /** @var array<string, int> role name => fixture user id */
    private static array $userIds = [];

    /** @var array<string, string> role name => raw API token */
    private static array $tokens = [];

    /** @var int[] */
    private static array $apiKeyIds = [];

    /** @var int[] */
    private static array $announcementIds = [];

    private static string $source;

    public static function setUpBeforeClass(): void
    {
        self::$source = 'phpunit:' . bin2hex(random_bytes(4));

        foreach (['admin', 'operator', 'agent'] as $roleName) {
            $roleId = $roleName === 'admin' ? 1 : (\Roles::idsByNames([$roleName])[0] ?? null);
            self::assertNotNull($roleId, "fixture setup requires a '{$roleName}' role to exist — run ./run seed");

            $user                = new \Users();
            $user->email         = 'phpunit-announcements-create-' . $roleName . '-' . bin2hex(random_bytes(6)) . '@example.invalid';
            $user->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
            $user->first_name    = 'PHPUnit';
            $user->last_name     = 'AnnouncementsCreate' . ucfirst($roleName);
            $user->role_id       = $roleId;
            $user->is_active     = 1;
            self::assertTrue($user->save(), "fixture {$roleName} failed to save: " . implode('; ', $user->getMessages()));
            self::$userIds[$roleName] = (int) $user->id;

            $token = 'phpunit-announcements-create-' . bin2hex(random_bytes(16));

            $apiKey               = new \ApiKeys();
            $apiKey->user_id      = (int) $user->id;
            $apiKey->name         = 'phpunit-announcements-create-' . $roleName;
            $apiKey->token_hash   = hash('sha256', $token);
            $apiKey->token_prefix = substr($token, 0, 10);
            self::assertTrue($apiKey->save(), 'fixture api key failed to save: ' . implode('; ', $apiKey->getMessages()));

            self::$tokens[$roleName] = $token;
            self::$apiKeyIds[]       = (int) $apiKey->id;
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (\Announcement::find(['conditions' => 'source = :source:', 'bind' => ['source' => self::$source]]) as $announcement) {
            $announcement->delete();
        }

        foreach (self::$announcementIds as $id) {
            \Announcement::findFirstById($id)?->delete();
        }

        foreach (self::$apiKeyIds as $id) {
            \ApiKeys::findFirstById($id)?->delete();
        }

        foreach (self::$userIds as $userId) {
            \Users::findFirstWithTrashed(['conditions' => 'id = :id:', 'bind' => ['id' => $userId]])?->softDelete();
        }
    }

    /** @return array{status: int, body: string, json: array} */
    private function post(string $role, array $body): array
    {
        $response = (new HttpClient())->postJson('/api/announcements', $body, ['X-Api-Key: ' . self::$tokens[$role]]);
        $decoded  = json_decode($response['body'], true);

        return $response + ['json' => is_array($decoded) ? $decoded : []];
    }

    /** @return array{status: int, body: string, json: array} */
    private function get(string $role, string $path): array
    {
        $response = (new HttpClient())->getWithHeaders($path, ['X-Api-Key: ' . self::$tokens[$role]]);
        $decoded  = json_decode($response['body'], true);

        return $response + ['json' => is_array($decoded) ? $decoded : []];
    }

    public function testAgentRoleCannotCreate(): void
    {
        $response = $this->post('agent', ['title' => 'phpunit agent attempt', 'body' => 'should be refused']);

        $this->assertSame(403, $response['status'], $response['body']);
        $this->assertNull(\Announcement::findFirst(['conditions' => 'title = :t:', 'bind' => ['t' => 'phpunit agent attempt']]));

        $read = $this->get('agent', '/api/announcements');
        $this->assertSame(200, $read['status'], 'the agent role keeps its read access');
    }

    public function testOperatorRoleCannotCreate(): void
    {
        $response = $this->post('operator', ['title' => 'phpunit operator attempt', 'body' => 'should be refused', 'publish' => true]);
        $this->assertSame(403, $response['status'], 'operator (Charter Agent) keys must not create announcements');
    }

    public function testMissingFieldsGive422WithFieldErrors(): void
    {
        $response = $this->post('admin', ['severity' => 'bogus', 'expires_at' => '2026-02-30']);

        $this->assertSame(422, $response['status'], $response['body']);
        $this->assertSame('title is required', $response['json']['errors']['title'] ?? null);
        $this->assertSame('body is required', $response['json']['errors']['body'] ?? null);
        $this->assertArrayHasKey('severity', $response['json']['errors']);
        $this->assertArrayHasKey('expires_at', $response['json']['errors']);

        $tooLong = $this->post('admin', ['title' => str_repeat('x', 201), 'body' => 'b']);
        $this->assertSame(422, $tooLong['status']);
        $this->assertArrayHasKey('title', $tooLong['json']['errors']);
    }

    public function testAdminCanCreateAndPublishAndTheRowIsReadable(): void
    {
        $response = $this->post('admin', [
            'title'      => 'phpunit created ' . self::$source,
            'body'       => 'Created over the API.',
            'severity'   => 'warning',
            'publish'    => true,
            'expires_at' => date('Y-m-d', strtotime('+2 days')),
        ]);

        $this->assertSame(201, $response['status'], $response['body']);
        $this->assertTrue($response['json']['created']);

        $row = $response['json']['announcement'];
        self::$announcementIds[] = (int) $row['id'];

        $this->assertSame('notice', $row['severity'], "'warning' is an alias of notice");
        $this->assertNotNull($row['published_at']);
        $this->assertSame(date('Y-m-d', strtotime('+2 days')) . ' 23:59:59', $row['expires_at']);
        $this->assertSame(self::$userIds['admin'], $row['author_user_id']);

        $list = $this->get('agent', '/api/announcements');
        $this->assertContains((int) $row['id'], array_column($list['json']['announcements'] ?? [], 'id'));

        $one = $this->get('agent', '/api/announcements/' . $row['id']);
        $this->assertSame(200, $one['status'], $one['body']);
        $this->assertSame($row['title'], $one['json']['announcement']['title']);
    }

    public function testSameSourceAndExternalIdUpdatesInsteadOfDuplicating(): void
    {
        $first = $this->post('admin', [
            'title' => 'phpunit dedupe v1', 'body' => 'v1', 'severity' => 'info',
            'source' => self::$source, 'external_id' => 'evt-1', 'source_url' => 'https://example.invalid/evt-1',
        ]);
        $this->assertSame(201, $first['status'], $first['body']);
        $id = (int) $first['json']['announcement']['id'];
        $this->assertNull($first['json']['announcement']['published_at'], 'draft by default');

        $second = $this->post('admin', [
            'title' => 'phpunit dedupe v2', 'body' => 'v2', 'severity' => 'critical', 'publish' => true,
            'source' => self::$source, 'external_id' => 'evt-1',
        ]);
        $this->assertSame(200, $second['status'], $second['body']);
        $this->assertFalse($second['json']['created']);
        $this->assertSame($id, (int) $second['json']['announcement']['id']);
        $this->assertSame('phpunit dedupe v2', $second['json']['announcement']['title']);
        $this->assertSame('important', $second['json']['announcement']['severity']);
        $this->assertNotNull($second['json']['announcement']['published_at'], 'publish: true on an update publishes a draft');

        $this->assertCount(1, \Announcement::find(['conditions' => 'source = :s: AND external_id = :e:', 'bind' => ['s' => self::$source, 'e' => 'evt-1']]));
    }

    public function testDraftIs404ForAgentAnd200ForAdmin(): void
    {
        $draft = $this->post('admin', ['title' => 'phpunit draft ' . self::$source, 'body' => 'unpublished']);
        $this->assertSame(201, $draft['status'], $draft['body']);
        $id = (int) $draft['json']['announcement']['id'];
        self::$announcementIds[] = $id;

        $this->assertSame(404, $this->get('agent', '/api/announcements/' . $id)['status']);
        $this->assertSame(200, $this->get('admin', '/api/announcements/' . $id)['status']);
        $this->assertSame(404, $this->get('admin', '/api/announcements/999999999')['status']);
    }
}
