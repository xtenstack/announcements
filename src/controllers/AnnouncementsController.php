<?php
declare(strict_types=1);

namespace Announcements\Controllers;

use Announcements\Api\Audience;

/**
 * Every read (index/history/view) is also filtered by audience — see
 * Api\Audience: customers see `everyone` rows only, agents also `all`,
 * admin/operator everything (25 Sep 2026).
 *
 * Reading (index/history/view) is any authenticated backend user — module
 * plan, RBAC section: "who can read the feed is everyone with any access
 * to the instance". Unpublished drafts are the one exception: they were
 * never announced, so only admin/operator see them in history/view
 * (canSeeDrafts()); everyone else gets published rows only and a 404 on
 * a draft's URL (review decision, 25 Sep 2026). Publishing
 * (create/publish/edit/delete/bulk) is admin-only, checked per-action via
 * requireAdmin() rather than ControllerBase::$allowedRoles, which would
 * gate the whole controller including the read actions (same pattern the
 * core api module's own TicketsController::closeAction() uses for a
 * narrower-than-controller role check).
 */
class AnnouncementsController extends ControllerBase
{
    private const PER_PAGE = 25;

    /**
     * The feed: published, unexpired, newest first — module plan, "UI"
     * section. A plain top-N list (not a paginated ListView query) since
     * this is the "what's current" surface; the full record lives in
     * historyAction().
     */
    public function indexAction(): void
    {
        \Announcement::detectAndFireExpirations();

        $now = date('Y-m-d H:i:s');

        [$audienceCondition, $audienceBind] = $this->audienceCondition();

        $this->view->announcements = \Announcement::find([
            'conditions' => 'published_at IS NOT NULL '
                . 'AND (expires_at IS NULL OR expires_at > :now:) AND ' . $audienceCondition,
            'bind'       => ['now' => $now] + $audienceBind,
            'order'      => 'published_at DESC',
            'limit'      => 10,
        ]);
        $this->view->isAdmin = $this->isAdmin();
    }

    /**
     * The full history: every announcement (drafts, published, expired),
     * filterable by severity/date, with search/sort/pagination — module
     * plan: "a dedicated list view... for the full history, filterable
     * by audience/severity/date" and open question 2's resolution
     * (expiry auto-hides from the feed, not from this list). Also this
     * module's CRUD list (View/Edit/Delete row buttons, top-right New, a
     * single "With selected" bulk-delete form), matching
     * TicketsController's own list-view conventions.
     */
    public function historyAction(): void
    {
        \Announcement::detectAndFireExpirations();

        $conditions = [];
        $bind       = [];

        if (!$this->canSeeDrafts()) {
            $conditions[] = 'published_at IS NOT NULL';
        }

        [$audienceCondition, $audienceBind] = $this->audienceCondition();
        $conditions[] = $audienceCondition;
        $bind         = $audienceBind;

        // Bind the normalised value, not the raw query string — the
        // lookup is case-insensitive but the column holds lowercase.
        $severity = \Announcement::normalizeSeverity((string) $this->request->getQuery('severity', 'string', '')) ?? '';

        if ($severity !== '') {
            $conditions[]     = 'severity = :severity:';
            $bind['severity'] = $severity;
        }

        // An unparseable date is dropped with a flash rather than handed to
        // Postgres, where it would surface as an uncaught PDOException (500).
        $dateFrom = trim((string) $this->request->getQuery('date_from', 'string', ''));
        $dateTo   = trim((string) $this->request->getQuery('date_to', 'string', ''));

        if ($dateFrom !== '' && !self::isValidDate($dateFrom)) {
            $this->flash->error('"From" must be a date (YYYY-MM-DD) — that filter was ignored');
            $dateFrom = '';
        }

        if ($dateTo !== '' && !self::isValidDate($dateTo)) {
            $this->flash->error('"To" must be a date (YYYY-MM-DD) — that filter was ignored');
            $dateTo = '';
        }

        if ($dateFrom !== '') {
            $conditions[]      = 'created_at >= :date_from:';
            $bind['date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== '') {
            $conditions[]    = 'created_at <= :date_to:';
            $bind['date_to'] = $dateTo . ' 23:59:59';
        }

        $list = \App_skeleton\ListView::paginate(
            $this->request,
            \Announcement::class,
            ['title', 'body'],
            ['created' => 'id', 'title' => 'title', 'severity' => 'severity', 'published' => 'published_at'],
            $conditions,
            $bind,
            self::PER_PAGE
        );

        $this->view->announcements = $list['results'];
        $this->view->isAdmin         = $this->isAdmin();
        $this->view->currentSeverity = $severity;
        $this->view->currentDateFrom = $dateFrom;
        $this->view->currentDateTo   = $dateTo;
        $this->view->listState       = $list;
        $this->view->preserveQuery   = array_merge($list['preserve'], array_filter([
            'severity'  => $severity !== '' ? $severity : null,
            'date_from' => $dateFrom !== '' ? $dateFrom : null,
            'date_to'   => $dateTo !== '' ? $dateTo : null,
        ], fn ($v) => $v !== null));
    }

    public function viewAction($id)
    {
        \Announcement::detectAndFireExpirations();

        $announcement = \Announcement::findFirstById($id);

        if (!$announcement) {
            $this->flash->error('Announcement was not found');

            return $this->dispatcher->forward(['controller' => 'announcements', 'action' => 'history']);
        }

        // A draft, or a row outside the caller's audience, is a plain 404,
        // not a 403: don't confirm that something they can't see exists.
        if ((!$announcement->isPublished() && !$this->canSeeDrafts())
            || !in_array($announcement->audience, $this->visibleAudiences(), true)) {
            return $this->dispatcher->forward(['controller' => 'index', 'action' => 'notFound']);
        }

        $this->view->announcement = $announcement;
        $this->view->isAdmin      = $this->isAdmin();
    }

    public function newAction()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $this->view->announcement = new \Announcement();
    }

    public function createAction()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'announcements', 'action' => 'history']);
        }

        if (!$this->flashInputErrors()) {
            $this->view->announcement = $this->fillFromRequest(new \Announcement());

            return $this->dispatcher->forward(['controller' => 'announcements', 'action' => 'new']);
        }

        $announcement = $this->fillFromRequest(new \Announcement());
        $announcement->author_user_id = $this->session->get('auth')['id'];

        if (!$announcement->save()) {
            foreach ($announcement->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            $this->view->announcement = $announcement;

            return $this->dispatcher->forward(['controller' => 'announcements', 'action' => 'new']);
        }

        $this->flash->success('Announcement saved as a draft — press Publish on this page when ready.');

        return $this->response->redirect($this->url->get('announcements/announcements/view/' . $announcement->id));
    }

    public function editAction($id)
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $announcement = \Announcement::findFirstById($id);

        if (!$announcement) {
            $this->flash->error('Announcement was not found');

            return $this->response->redirect($this->url->get('announcements/announcements/history'));
        }

        $this->view->announcement = $announcement;
    }

    public function updateAction($id)
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $announcement = \Announcement::findFirstById($id);

        if (!$announcement) {
            $this->flash->error('Announcement was not found');

            return $this->response->redirect($this->url->get('announcements/announcements/history'));
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'announcements', 'action' => 'edit', 'params' => [$id]]);
        }

        if (!$this->flashInputErrors()) {
            $this->view->announcement = $announcement;

            return $this->dispatcher->forward(['controller' => 'announcements', 'action' => 'edit', 'params' => [$id]]);
        }

        $wasPublished = $announcement->isPublished();
        $this->fillFromRequest($announcement);

        if (!$announcement->save()) {
            foreach ($announcement->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            $this->view->announcement = $announcement;

            return $this->dispatcher->forward(['controller' => 'announcements', 'action' => 'edit', 'params' => [$id]]);
        }

        // Only a previously-published announcement can meaningfully be
        // "updated" from the consuming side's point of view — editing a
        // still-draft one has nothing to notify, since announcements:created
        // hasn't fired for it yet either.
        if ($wasPublished) {
            $this->fireEvent('announcements:updated', $announcement);
        }

        $this->flash->success('Announcement updated');

        return $this->response->redirect($this->url->get('announcements/announcements/view/' . $announcement->id));
    }

    /**
     * Admin-only publish step (module plan Phasing: "create/publish flow
     * (admin-only)") — separate from createAction() so a draft can be
     * reviewed before it goes out. Sets published_at to now and fires
     * announcements:created. No-ops (with a flash notice, not an error)
     * if already published, so a double-click/resubmit can't fire the
     * event twice.
     */
    public function publishAction($id)
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $announcement = \Announcement::findFirstById($id);

        if (!$announcement) {
            $this->flash->error('Announcement was not found');

            return $this->response->redirect($this->url->get('announcements/announcements/history'));
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'announcements', 'action' => 'view', 'params' => [$id]]);
        }

        if ($announcement->isPublished()) {
            $this->flash->notice('Announcement is already published');

            return $this->response->redirect($this->url->get('announcements/announcements/view/' . $announcement->id));
        }

        $announcement->published_at = date('Y-m-d H:i:s');

        if (!$announcement->save()) {
            foreach ($announcement->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->response->redirect($this->url->get('announcements/announcements/view/' . $announcement->id));
        }

        $this->fireEvent('announcements:created', $announcement);

        $this->flash->success('Announcement published');

        return $this->response->redirect($this->url->get('announcements/announcements/view/' . $announcement->id));
    }

    public function deleteAction($id)
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $announcement = \Announcement::findFirstById($id);

        if (!$announcement) {
            $this->flash->error('Announcement was not found');

            return $this->response->redirect($this->url->get('announcements/announcements/history'));
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'announcements', 'action' => 'view', 'params' => [$id]]);
        }

        if (!$announcement->softDelete()) {
            foreach ($announcement->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->response->redirect($this->url->get('announcements/announcements/view/' . $announcement->id));
        }

        $this->flash->success('Announcement deleted');

        return $this->dispatcher->forward(['controller' => 'announcements', 'action' => 'history']);
    }

    /** "With selected" bulk delete only — matches the scope the spec asks for, not a general bulk-update. */
    public function bulkAction()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'announcements', 'action' => 'history']);
        }

        $ids = array_filter(array_map('intval', (array) $this->request->getPost('announcement_ids', null, [])));

        if (!$ids) {
            $this->flash->error('No announcements were selected');

            return $this->dispatcher->forward(['controller' => 'announcements', 'action' => 'history']);
        }

        $announcements = \Announcement::find([
            'conditions' => 'id IN ({ids:array})',
            'bind'       => ['ids' => $ids],
        ]);

        $count = 0;

        foreach ($announcements as $announcement) {
            if ($announcement->softDelete()) {
                $count++;
            }
        }

        $this->flash->success($count . ' announcement(s) deleted');

        return $this->dispatcher->forward(['controller' => 'announcements', 'action' => 'history']);
    }

    private function fillFromRequest(\Announcement $announcement): \Announcement
    {
        $announcement->title    = trim((string) $this->request->getPost('title'));
        $announcement->body     = trim((string) $this->request->getPost('body'));
        $announcement->audience = Audience::normalize((string) $this->request->getPost('audience')) ?? Audience::ALL;
        $announcement->severity = \Announcement::normalizeSeverity((string) $this->request->getPost('severity')) ?? 'info';

        // The form posts a bare date; "expires on the 30th" means it is
        // still current for the whole of the 30th, so store end-of-day.
        // Callers validate via flashInputErrors() first — an invalid value
        // here is treated as "no expiry" for the re-rendered form only.
        $expiresAt = trim((string) $this->request->getPost('expires_at'));
        $announcement->expires_at = ($expiresAt !== '' && self::isValidDate($expiresAt)) ? $expiresAt . ' 23:59:59' : null;

        // A previously-fired expiry that gets pushed out again (or
        // cleared) via an edit is eligible to fire again — clear the
        // idempotency marker so detectAndFireExpirations() doesn't treat
        // the new expires_at as already-notified. Only when expires_at
        // actually changed: an unrelated edit (retitling an already-
        // expired notice) must not make announcements:expired refire.
        // keepSnapshots(true) on the model is what makes hasChanged()
        // valid here; a brand-new record has no snapshot and its marker
        // is null anyway.
        if ($announcement->hasSnapshotData() && $announcement->hasChanged('expires_at')) {
            $announcement->expired_notified_at = null;
        }

        return $announcement;
    }

    /**
     * Validates the posted form and flashes every problem. Returns true
     * when the input is acceptable. Kept out of fillFromRequest() so the
     * same rules apply to create and update, and so an invalid date never
     * reaches the database (where it would be an uncaught PDOException).
     */
    private function flashInputErrors(): bool
    {
        $ok = true;

        if (trim((string) $this->request->getPost('title')) === '' || trim((string) $this->request->getPost('body')) === '') {
            $this->flash->error('Title and body are required');
            $ok = false;
        }

        $expiresAt = trim((string) $this->request->getPost('expires_at'));

        if ($expiresAt !== '' && !self::isValidDate($expiresAt)) {
            $this->flash->error('Expires must be a valid date (YYYY-MM-DD)');
            $ok = false;
        }

        return $ok;
    }

    /** Strict Y-m-d: rejects 2026-02-30 as well as free-form garbage. */
    private static function isValidDate(string $value): bool
    {
        $parsed = \DateTime::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    /** @return string[] */
    private function visibleAudiences(): array
    {
        return Audience::visibleFor(
            $this->currentPrincipal->hasAnyRole(\Roles::idsByNames(Audience::STAFF_ROLES)),
            $this->currentPrincipal->hasAnyRole(\Roles::idsByNames(Audience::AGENT_ROLES))
        );
    }

    /**
     * `audience IN (...)` with one named placeholder per value, so it works
     * unchanged in both find() and ListView::paginate().
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function audienceCondition(): array
    {
        $bind = [];

        foreach (array_values($this->visibleAudiences()) as $i => $audience) {
            $bind['audience' . $i] = $audience;
        }

        return ['audience IN (:' . implode(':, :', array_keys($bind)) . ':)', $bind];
    }

    private function isAdmin(): bool
    {
        return $this->currentPrincipal->hasAnyRole(\Roles::idsByNames(['admin']));
    }

    /** Drafts are staff-internal: admin and operator only (see class docblock). */
    private function canSeeDrafts(): bool
    {
        return $this->currentPrincipal->hasAnyRole(\Roles::idsByNames(['admin', 'operator']));
    }

    /**
     * @return bool false if the caller isn't an admin — the response is
     *         already built as a 403 (view disabled, matching
     *         IndexController::notFoundAction()'s own style) and the
     *         framework sends it normally once the calling action
     *         returns; no manual send()/exit needed since, unlike
     *         ControllerBase::onConstruct()'s role gate, this runs from
     *         inside an already-dispatched action, not a constructor.
     */
    private function requireAdmin(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $this->view->disable();
        $this->response->setStatusCode(403, 'Forbidden');
        $this->response->setContent('<h1>403 Forbidden</h1><p>Publishing announcements is admin-only.</p>');

        return false;
    }

    /**
     * Fires on the shared eventsBus (app/config/services.php) — the
     * event bus this module publishes on, per docs/MODULE-SPEC.md's
     * "Event bus" section: "Attach listeners in
     * Module::registerSharedServices($di)... This is the only sanctioned
     * channel for one module to react to another module's state
     * changes." $eventsBus is a shared DI service, available from any
     * controller without this module registering it itself.
     */
    private function fireEvent(string $eventName, \Announcement $announcement): void
    {
        $this->eventsBus->fire($eventName, $announcement, $announcement->toReadArray());
    }
}
