<?php
declare(strict_types=1);

namespace Announcements\Feeds;

/**
 * Thin HTTP reader for a Statuspage.io site's public /api/v2/ JSON. Every
 * call has a hard 10 s connect + 10 s total timeout so one slow provider
 * can't hold the cron tick — a failure returns null and is available via
 * lastError(); the poller logs it and moves on, never throws. get() is
 * the single seam over curl, overridden by the unit tests to serve the
 * saved fixtures instead.
 */
class StatuspageClient
{
    public const TIMEOUT_SECONDS = 10;

    private string $lastError = '';

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * Decoded JSON document, or null (with lastError() set) on any
     * transport, HTTP or decode failure.
     *
     * @return array<string, mixed>|null
     */
    public function fetchJson(string $url): ?array
    {
        $this->lastError = '';

        try {
            $response = $this->get($url);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return null;
        }

        if ($response['status'] !== 200) {
            $this->lastError = 'HTTP ' . $response['status'] . ' from ' . $url;

            return null;
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            $this->lastError = 'Invalid JSON from ' . $url;

            return null;
        }

        return $decoded;
    }

    /**
     * The events list from one of the three polled endpoints, keyed as
     * Statuspage returns them ('incidents' or 'scheduled_maintenances').
     *
     * @return array<int, array<string, mixed>>|null null when the fetch failed
     */
    public function fetchEvents(string $baseUrl, string $path, string $listKey): ?array
    {
        $doc = $this->fetchJson($baseUrl . '/api/v2/' . ltrim($path, '/'));

        if ($doc === null) {
            return null;
        }

        $events = $doc[$listKey] ?? null;

        if (!is_array($events)) {
            $this->lastError = "No '{$listKey}' list in " . $path;

            return null;
        }

        return array_values(array_filter($events, fn ($e) => is_array($e) && isset($e['id'])));
    }

    /**
     * One event by id, for the "did it finish?" check on an event that
     * has dropped out of the polled lists. Statuspage serves incidents
     * and scheduled maintenances from separate detail endpoints and each
     * 404s for the other kind's id (confirmed against DigitalOcean 25 Sep
     * 2026), and a stored announcement doesn't record which kind it was —
     * so try the incident endpoint first, then the maintenance one. Both
     * 404ing (provider deleted the event) is reported as an error, not
     * as "finished": the announcement is left as-is for a human.
     *
     * @return array<string, mixed>|null
     */
    public function fetchDetail(string $baseUrl, string $eventId): ?array
    {
        $id = rawurlencode($eventId);

        $doc = $this->fetchJson($baseUrl . '/api/v2/incidents/' . $id . '.json');

        if ($doc !== null && isset($doc['incident']) && is_array($doc['incident'])) {
            return $doc['incident'];
        }

        $doc = $this->fetchJson($baseUrl . '/api/v2/scheduled-maintenances/' . $id . '.json');

        if ($doc !== null && isset($doc['scheduled_maintenance']) && is_array($doc['scheduled_maintenance'])) {
            return $doc['scheduled_maintenance'];
        }

        if ($this->lastError === '') {
            $this->lastError = 'Unexpected detail shape for event ' . $eventId;
        }

        return null;
    }

    /**
     * @return array{status: int, body: string}
     * @throws \RuntimeException on a transport failure
     */
    protected function get(string $url): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new \RuntimeException('curl_init failed for ' . $url);
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: xten-announcements-feeds/1.0'],
        ]);

        $body = curl_exec($ch);

        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new \RuntimeException('curl: ' . $error . ' (' . $url . ')');
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body];
    }
}
