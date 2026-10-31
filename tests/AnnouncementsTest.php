<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REQ-226. Real HTTP against the real running app_skeleton stack (see
 * bootstrap.php / HttpClient's own docblock for why — ControllerBase's
 * role/CSRF checks call `exit;` on an early-return response, which would
 * kill an in-process PHPUnit run). Covers the four behaviors the task
 * asked this suite to prove:
 *
 *  - an admin can publish a draft announcement;
 *  - an operator cannot (publishing is admin-only,
 *    AnnouncementsController::requireAdmin());
 *  - an agent-role API caller's GET /api/announcements returns only
 *    active (published, unexpired) announcements;
 *  - an expired announcement is hidden from the feed and the API by
 *    default, but present with include_expired=1 (API) / the full
 *    history list (backend) — module plan's open question 2.
 *
 * Fixtures are created directly via the model (bypassing HTTP), same
 * convention as the ticketing Feature tests (e.g.
 * ApiTicketsCloseControllerTest::newTicket()) — only the behavior under
 * test goes over real HTTP.
 */
final class AnnouncementsTest extends TestCase
{
    private static string $adminEmail;
    private static string $operatorEmail;
    private static string $password = 'PhpunitTest123!';

    private static int $adminId;
    private static int $operatorId;
    private static int $agentId;
    private static int $memberId;
    private static string $memberEmail;

    private static string $agentApiKeyRawToken;
    private static int $agentApiKeyId;

    /** @var int[] */
    private static array $announcementIds = [];

    public static function setUpBeforeClass(): void
    {
        // DI + loader + the Announcement model itself are already set up
        // by bootstrap.php (required once for the whole run, unlike the
        // ticketing Feature tests' per-class setUpBeforeClass() — needed
        // here specifically so requiring \Announcement doesn't fatal on
        // its `use SoftDeletes;` before the loader that resolves that
        // trait is registered; see bootstrap.php's own docblock).
        $operatorRoleId = \Roles::idsByNames(['operator'])[0] ?? null;
        $agentRoleId    = \Roles::idsByNames(['agent'])[0] ?? null;

        self::assertNotNull($operatorRoleId, "fixture setup requires an 'operator' role to exist — run ./run seed");
        self::assertNotNull($agentRoleId, "fixture setup requires an 'agent' role to exist — run ./run seed");

        self::$adminEmail    = 'phpunit-announcements-admin-' . bin2hex(random_bytes(6)) . '@example.invalid';
        self::$operatorEmail = 'phpunit-announcements-operator-' . bin2hex(random_bytes(6)) . '@example.invalid';
        $agentEmail          = 'phpunit-announcements-agent-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $admin                = new \Users();
        $admin->email         = self::$adminEmail;
        $admin->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $admin->first_name    = 'PHPUnit';
        $admin->last_name     = 'AnnouncementsAdmin';
        $admin->role_id       = 1; // admin
        $admin->is_active     = 1;
        self::assertTrue($admin->save(), 'fixture admin failed to save: ' . implode('; ', $admin->getMessages()));
        self::$adminId = (int) $admin->id;

        $operator                = new \Users();
        $operator->email         = self::$operatorEmail;
        $operator->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $operator->first_name    = 'PHPUnit';
        $operator->last_name     = 'AnnouncementsOperator';
        $operator->role_id       = $operatorRoleId;
        $operator->is_active     = 1;
        self::assertTrue($operator->save(), 'fixture operator failed to save: ' . implode('; ', $operator->getMessages()));
        self::$operatorId = (int) $operator->id;

        $agent                = new \Users();
        $agent->email         = $agentEmail;
        $agent->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $agent->first_name    = 'PHPUnit';
        $agent->last_name     = 'AnnouncementsAgent';
        $agent->role_id       = $agentRoleId;
        $agent->is_active     = 1;
        self::assertTrue($agent->save(), 'fixture agent failed to save: ' . implode('; ', $agent->getMessages()));
        self::$agentId = (int) $agent->id;

        $memberRoleId = \Roles::idsByNames(['member'])[0] ?? null;
        self::assertNotNull($memberRoleId, "fixture setup requires a 'member' role to exist — run ./run seed");

        self::$memberEmail = 'phpunit-announcements-member-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $member                = new \Users();
        $member->email         = self::$memberEmail;
        $member->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $member->first_name    = 'PHPUnit';
        $member->last_name     = 'AnnouncementsMember';
        $member->role_id       = $memberRoleId;
        $member->is_active     = 1;
        self::assertTrue($member->save(), 'fixture member failed to save: ' . implode('; ', $member->getMessages()));
        self::$memberId = (int) $member->id;

        self::$agentApiKeyRawToken = 'phpunit-announcements-token-' . bin2hex(random_bytes(16));

        $apiKey               = new \ApiKeys();
        $apiKey->user_id      = self::$agentId;
        $apiKey->name         = 'phpunit-announcements-agent-fixture-key';
        $apiKey->token_hash   = hash('sha256', self::$agentApiKeyRawToken);
        $apiKey->token_prefix = substr(self::$agentApiKeyRawToken, 0, 10);
        self::assertTrue($apiKey->save(), 'fixture api key failed to save: ' . implode('; ', $apiKey->getMessages()));
        self::$agentApiKeyId = (int) $apiKey->id;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$announcementIds as $id) {
            \Announcement::findFirstById($id)?->delete();
        }

        $apiKey = \ApiKeys::findFirstById(self::$agentApiKeyId);
        $apiKey?->delete();

        foreach ([self::$adminId, self::$operatorId, self::$agentId, self::$memberId] as $userId) {
            \Users::findFirstWithTrashed(['conditions' => 'id = :id:', 'bind' => ['id' => $userId]])?->softDelete();
        }
    }

    private function draftAnnouncement(array $overrides = []): \Announcement
    {
        $announcement                 = new \Announcement();
        $announcement->title          = 'phpunit-announcements-fixture-' . bin2hex(random_bytes(4));
        $announcement->body           = 'Fixture body for AnnouncementsTest.';
        $announcement->audience       = \Announcement::AUDIENCE_ALL;
        $announcement->severity       = 'info';
        $announcement->author_user_id = self::$adminId;

        foreach ($overrides as $field => $value) {
            $announcement->$field = $value;
        }

        self::assertTrue($announcement->save(), 'fixture announcement failed to save: ' . implode('; ', $announcement->getMessages()));
        self::$announcementIds[] = (int) $announcement->id;

        return $announcement;
    }

    private function loggedInClient(string $email): HttpClient
    {
        $client    = new HttpClient();
        $loginPage = $client->get('/backend/session');
        $csrf      = HttpClient::extractCsrf($loginPage['body']);

        $response = $client->post('/backend/session/login', [
            'email'      => $email,
            'password'   => self::$password,
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringNotContainsString(
            'Invalid email or password',
            $response['body'],
            'fixture user failed to log in — can\'t test the controller without a real authenticated session'
        );

        return $client;
    }

    public function testAdminCanPublishADraftAnnouncement(): void
    {
        $announcement = $this->draftAnnouncement();
        $this->assertNull($announcement->published_at, 'fixture should start as an unpublished draft');

        $client = $this->loggedInClient(self::$adminEmail);
        $csrf   = HttpClient::extractCsrf($client->get('/announcements/announcements/view/' . $announcement->id)['body']);

        $response = $client->post('/announcements/announcements/publish/' . $announcement->id, [
            $csrf['key'] => $csrf['token'],
        ]);

        $announcement->refresh();
        $this->assertNotNull($announcement->published_at, 'admin publish should set published_at: HTTP ' . $response['status']);
    }

    public function testOperatorCannotPublishAnAnnouncement(): void
    {
        $announcement = $this->draftAnnouncement();

        $client = $this->loggedInClient(self::$operatorEmail);
        // A real CSRF token is required here so this actually exercises
        // the role gate (requireAdmin(), checked first in
        // publishAction()) rather than incidentally failing earlier at
        // ControllerBase::enforceCsrf() with a redirect, which would
        // make the 403 assertion below meaningless.
        $csrf = HttpClient::extractCsrf($client->get('/announcements/announcements/view/' . $announcement->id)['body']);

        $response = $client->post('/announcements/announcements/publish/' . $announcement->id, [
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertSame(403, $response['status'], 'operator role should be forbidden from publishing (admin-only)');

        $announcement->refresh();
        $this->assertNull($announcement->published_at, 'published_at changed despite an insufficient role');
    }

    public function testAgentRoleApiReadReturnsOnlyActiveAnnouncements(): void
    {
        $active = $this->draftAnnouncement(['published_at' => date('Y-m-d H:i:s')]);
        $draft  = $this->draftAnnouncement(); // published_at stays null

        $client = new HttpClient();

        // HttpClient::get() sends no custom headers, so API-key auth
        // needs getWithApiKey() instead — see that method's own docblock.
        $response = $this->getWithApiKey($client, '/api/announcements');

        $this->assertSame(200, $response['status'], 'agent-role API read should succeed: ' . $response['body']);

        $payload = json_decode($response['body'], true);
        $ids     = array_column($payload['announcements'] ?? [], 'id');

        $this->assertContains((int) $active->id, $ids, 'active announcement should be in the default API read');
        $this->assertNotContains((int) $draft->id, $ids, 'an unpublished draft should never appear in the API read');
    }

    public function testExpiredAnnouncementsHiddenFromFeedAndApiByDefaultButPresentWithIncludeExpired(): void
    {
        $expired = $this->draftAnnouncement([
            'published_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'expires_at'   => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $client = $this->loggedInClient(self::$adminEmail);

        $feed = $client->get('/announcements/announcements');
        $this->assertStringNotContainsString(
            $this->escapeForAssert($expired->title),
            $feed['body'],
            'an expired announcement should be hidden from the default feed'
        );

        $history = $client->get('/announcements/announcements/history');
        $this->assertStringContainsString(
            $this->escapeForAssert($expired->title),
            $history['body'],
            'an expired announcement should still be present in the full history list'
        );

        $apiDefault = $this->getWithApiKey($client, '/api/announcements');
        $defaultIds = array_column(json_decode($apiDefault['body'], true)['announcements'] ?? [], 'id');
        $this->assertNotContains((int) $expired->id, $defaultIds, 'an expired announcement should be hidden from the default API read');

        $apiIncludeExpired = $this->getWithApiKey($client, '/api/announcements?include_expired=1');
        $includeExpiredIds = array_column(json_decode($apiIncludeExpired['body'], true)['announcements'] ?? [], 'id');
        $this->assertContains((int) $expired->id, $includeExpiredIds, 'include_expired=1 should surface the expired announcement');
    }

    /**
     * Review fix L2 (25 Sep 2026): drafts are staff-internal. A member
     * (any role outside admin/operator) sees published rows only in the
     * history list and gets a 404 — not a 403, nothing to confirm — on a
     * draft's own URL. Operators keep draft access, which is also what
     * testOperatorCannotPublishAnAnnouncement() relies on to fetch a CSRF
     * token from a draft's view page.
     */
    public function testMemberCannotSeeDrafts(): void
    {
        $draft = $this->draftAnnouncement();

        $client = $this->loggedInClient(self::$memberEmail);

        $history = $client->get('/announcements/announcements/history');
        $this->assertSame(200, $history['status']);
        $this->assertStringNotContainsString(
            $this->escapeForAssert($draft->title),
            $history['body'],
            'a member should not see an unpublished draft in the history list'
        );

        $view = $client->get('/announcements/announcements/view/' . $draft->id);
        $this->assertSame(404, $view['status'], 'a draft should 404 for a member');
    }

    /** @return array{status: int, body: string} */
    private function getWithApiKey(HttpClient $client, string $path): array
    {
        // HttpClient::get() sends no custom headers, and postJson() is
        // POST-only — this module's read API is GET-only, so neither
        // covers X-Api-Key auth against it. A fresh curl call mirroring
        // HttpClient::request()'s own options, scoped to this one test
        // file, is simpler than extending the shared helper for one caller.
        $ch = curl_init((getenv('APP_TEST_BASE_URL') ?: 'http://caddy') . $path);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            // Host: localhost is required for the same reason
            // HttpClient::request() sets it (see that method's own
            // docblock) — Caddy routes on the Host header, matching
            // SITE_DOMAIN ("localhost" by default), and doesn't
            // recognize the Docker Compose service name "caddy" from
            // $baseUrl on its own.
            CURLOPT_HTTPHEADER     => [
                'Host: ' . (getenv('APP_TEST_HOST') ?: 'localhost'),
                'X-Api-Key: ' . self::$agentApiKeyRawToken,
            ],
        ]);

        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => $body];
    }

    private function escapeForAssert(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
