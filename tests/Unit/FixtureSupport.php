<?php
declare(strict_types=1);

namespace Announcements\Tests\Unit;

use Announcements\Feeds\StatuspageClient;

/**
 * Shared helpers for the unit suite: fixture loading, the two feed
 * configs under test, and a StatuspageClient that answers from the
 * saved fixtures (or a scripted 404/500) instead of the network.
 */
trait FixtureSupport
{
    /** @return array<string, mixed> */
    protected static function fixture(string $name): array
    {
        $path = ANNOUNCEMENTS_FIXTURES . '/' . $name;
        $doc  = json_decode((string) file_get_contents($path), true);

        if (!is_array($doc)) {
            throw new \RuntimeException('fixture unreadable: ' . $path);
        }

        return $doc;
    }

    /** @return array<string, mixed> one event by id from a list fixture */
    protected static function event(string $fixture, string $id): array
    {
        $doc  = self::fixture($fixture);
        $list = $doc['incidents'] ?? $doc['scheduled_maintenances'] ?? [];

        foreach ($list as $event) {
            if (($event['id'] ?? null) === $id) {
                return $event;
            }
        }

        throw new \RuntimeException("no event {$id} in {$fixture}");
    }

    /** @return array<string, array<string, mixed>> the real feeds.php */
    protected static function feeds(): array
    {
        return \Announcements\Feeds\FeedConfig::all();
    }
}

/**
 * Serves canned responses by URL path suffix. Anything not scripted is
 * a 404, matching what Statuspage does for an unknown id.
 */
final class FixtureClient extends StatuspageClient
{
    /** @var array<string, array{status: int, body: string}> path => response */
    private array $responses = [];

    /** @var string[] every URL requested, in order */
    public array $requested = [];

    /** @var array<string, \RuntimeException> path => transport failure to throw */
    private array $failures = [];

    public function serveDoc(string $path, array $doc): self
    {
        $this->responses[$path] = ['status' => 200, 'body' => (string) json_encode($doc)];

        return $this;
    }

    public function serveStatus(string $path, int $status, string $body = ''): self
    {
        $this->responses[$path] = ['status' => $status, 'body' => $body];

        return $this;
    }

    public function failTransport(string $path, string $message = 'simulated timeout'): self
    {
        $this->failures[$path] = new \RuntimeException($message);

        return $this;
    }

    protected function get(string $url): array
    {
        $this->requested[] = $url;

        foreach ($this->failures as $path => $exception) {
            if (str_ends_with($url, $path)) {
                throw $exception;
            }
        }

        foreach ($this->responses as $path => $response) {
            if (str_ends_with($url, $path)) {
                return $response;
            }
        }

        return ['status' => 404, 'body' => '{"error":"not found"}'];
    }
}
