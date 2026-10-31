<?php
declare(strict_types=1);

namespace Announcements\Controllers\Api;

use Announcements\Api\AnnouncementInput;
use Announcements\Api\Audience;
use Announcements\Controllers\ApiControllerBase;

/**
 * JSON API — module plan: "A read-only JSON API endpoint
 * (GET /api/announcements — filtered by audience/severity/active-only by
 * default), so any agent... can poll or be event-notified without
 * needing dashboard/UI access." The write side (createAction, REQ-226
 * follow-up) exists so an agent or an integration can raise a notice
 * without the backend form — admin principals only, drafts by
 * default, idempotent on (source, external_id).
 *
 * Reachable at /api/announcements (custom routes, see
 * Module::registerRoutes() — this module's generic
 * /announcements/:controller/:action routing would otherwise produce
 * /announcements/api/announcements/... instead, per docs/MODULE-SPEC.md;
 * same extension point the sibling ai-ssa-chat/phone/email plugins
 * already use for their own /api/... surfaces). Any authenticated
 * principal may read, including the `agent` role — ApiControllerBase
 * leaves $allowedRoles null here, unlike the sibling plugins' admin-only
 * config APIs; the create action gates itself via requireRoles().
 * Reads are filtered by audience (Api\Audience): an agent-role key sees
 * `everyone` + `all`, a customer (`member`) key `everyone` only.
 */
class AnnouncementsController extends ApiControllerBase
{
    public function indexAction()
    {
        \Announcement::detectAndFireExpirations();

        $conditions = ['published_at IS NOT NULL'];
        $bind       = [];

        $placeholders = [];

        foreach (array_values($this->visibleAudiences()) as $i => $audience) {
            $placeholders[]        = ':audience' . $i . ':';
            $bind['audience' . $i] = $audience;
        }

        $conditions[] = 'audience IN (' . implode(', ', $placeholders) . ')';

        $includeExpired = (string) $this->request->getQuery('include_expired', 'string', '');

        if ($includeExpired !== '1') {
            $conditions[] = '(expires_at IS NULL OR expires_at > :now:)';
            $bind['now']  = date('Y-m-d H:i:s');
        }

        $severity = (string) $this->request->getQuery('severity', 'string', '');

        if ($severity !== '' && \Announcement::normalizeSeverity($severity) !== null) {
            $conditions[]     = 'severity = :severity:';
            $bind['severity'] = \Announcement::normalizeSeverity($severity);
        }

        $since = (string) $this->request->getQuery('since', 'string', '');

        if ($since !== '' && strtotime($since) !== false) {
            $conditions[]  = 'published_at >= :since:';
            $bind['since'] = date('Y-m-d H:i:s', strtotime($since));
        }

        $announcements = \Announcement::find([
            'conditions' => implode(' AND ', $conditions),
            'bind'       => $bind,
            'order'      => 'published_at DESC',
        ]);

        return $this->response->setJsonContent([
            'announcements' => array_map(
                fn (\Announcement $a) => $a->toReadArray(),
                iterator_to_array($announcements)
            ),
        ]);
    }

    /**
     * GET /api/announcements/{id}. Any principal; a draft is visible to
     * admin/operator only and is a 404 (not a 403) for anyone else —
     * same rule as the backend view page, don't confirm that something
     * unannounced exists.
     */
    public function viewAction($id)
    {
        \Announcement::detectAndFireExpirations();

        $announcement = \Announcement::findFirstById((int) $id);

        if (!$announcement
            || (!$announcement->isPublished() && !$this->hasRole(['admin', 'operator']))
            || !in_array($announcement->audience, $this->visibleAudiences(), true)) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Announcement not found']);
        }

        return $this->response->setJsonContent(['announcement' => $announcement->toReadArray()]);
    }

    /**
     * POST /api/announcements — JSON body: title, body (required),
     * severity, audience, publish (bool), expires_at (Y-m-d or
     * Y-m-d H:i:s), source, external_id, source_url. Rules live in
     * Announcements\Api\AnnouncementInput (unit-tested).
     *
     * Roles: admin only; operator and agent keys get 403. Same as the
     * backend form (decided 27 Sep 2026: operator-role principals, which
     * include an instance's charter agents, must not create or publish
     * announcements). An automated feeder posts with an admin key.
     * `publish` still defaults to false, so the default path is "draft, an
     * admin presses Publish".
     *
     * Idempotent on (source, external_id): a second POST for the same
     * external event updates title/body/severity/expires_at (and
     * source_url, and audience when the body sends one) on the live row and answers 200 with the same id,
     * instead of a duplicate. A `publish: true` on such an update
     * publishes a still-draft row; it never un-publishes. New rows
     * answer 201. Events fire as the backend does: announcements:created
     * the moment a row is published, announcements:updated when a
     * published row changes.
     */
    public function createAction()
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        if (!$this->requireRoles(['admin'])) {
            return $this->response;
        }

        $input = AnnouncementInput::validate($this->getJsonBody());

        if ($input['errors'] !== []) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'Validation failed', 'errors' => $input['errors']]);
        }

        $values = $input['values'];
        $now    = date('Y-m-d H:i:s');

        $existing = $values['source'] !== null && $values['external_id'] !== null
            ? \Announcement::findBySourceKey($values['source'], $values['external_id'])
            : null;

        if ($existing) {
            $wasPublished = $existing->isPublished();

            if ((string) $existing->expires_at !== (string) $values['expires_at']) {
                // Same rule as the backend form: a moved expiry may fire
                // announcements:expired again.
                $existing->expired_notified_at = null;
            }

            // Only an explicit audience changes an existing row's audience:
            // the OneDrive inbox feeder re-posts edited files without one,
            // and that must not reset a Staff-Only notice to the default.
            if (array_key_exists('audience', $this->getJsonBody())) {
                $existing->audience = $values['audience'];
            }

            $existing->title      = $values['title'];
            $existing->body       = $values['body'];
            $existing->severity   = $values['severity'];
            $existing->expires_at = $values['expires_at'];
            $existing->source_url = $values['source_url'];

            if ($values['publish'] && !$wasPublished) {
                $existing->published_at = $now;
            }

            if (!$existing->save()) {
                $this->response->setStatusCode(422, 'Unprocessable Entity');

                return $this->response->setJsonContent(['error' => implode(', ', array_map('strval', $existing->getMessages()))]);
            }

            if ($wasPublished) {
                $this->eventsBus->fire('announcements:updated', $existing, $existing->toReadArray());
            } elseif ($existing->isPublished()) {
                $this->eventsBus->fire('announcements:created', $existing, $existing->toReadArray());
            }

            return $this->response->setJsonContent(['announcement' => $existing->toReadArray(), 'created' => false]);
        }

        $announcement                 = new \Announcement();
        $announcement->title          = $values['title'];
        $announcement->body           = $values['body'];
        $announcement->severity       = $values['severity'];
        $announcement->audience       = $values['audience'];
        $announcement->expires_at     = $values['expires_at'];
        $announcement->source         = $values['source'];
        $announcement->external_id    = $values['external_id'];
        $announcement->source_url     = $values['source_url'];
        $announcement->published_at   = $values['publish'] ? $now : null;
        // Never from the body — always the authenticated caller.
        $announcement->author_user_id = $this->principal['user_id'];

        if (!$announcement->save()) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => implode(', ', array_map('strval', $announcement->getMessages()))]);
        }

        // created_at/updated_at are DB-defaulted; reload so the response
        // carries the values Postgres computed, not the default expression.
        $announcement->refresh();

        if ($announcement->isPublished()) {
            $this->eventsBus->fire('announcements:created', $announcement, $announcement->toReadArray());
        }

        $this->response->setStatusCode(201, 'Created');

        return $this->response->setJsonContent(['announcement' => $announcement->toReadArray(), 'created' => true]);
    }

    /** @return string[] */
    private function visibleAudiences(): array
    {
        return Audience::visibleFor($this->hasRole(Audience::STAFF_ROLES), $this->hasRole(Audience::AGENT_ROLES));
    }
}
