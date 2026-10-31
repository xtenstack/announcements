<?php
declare(strict_types=1);

namespace Announcements\Controllers;

use Phalcon\Mvc\Controller;

/**
 * HTML/admin controller base — mirrors app/modules/backend/controllers/
 * ControllerBase.php's auth/CSRF/role gate, same copy every sibling
 * ai-ssa-* plugin already carries (this module is a separate Phalcon
 * plugin-tier module — its own routing namespace under /announcements/...,
 * see docs/MODULE-SPEC.md — so it can't extend the backend module's class
 * directly). $allowedRoles stays null here: reading the feed/history is
 * for any authenticated backend user (module plan, RBAC section) —
 * publish/create/edit/delete are gated per-action in
 * AnnouncementsController instead, not for the whole controller.
 */
class ControllerBase extends Controller
{
    protected ?array $allowedRoles = null;

    protected function onConstruct()
    {
        $this->preventCaching();
        $this->enforceCsrf();

        if (!$this->auth->isLoggedIn()) {
            $this->response->redirect($this->url->get('backend/session'))->send();
            exit;
        }

        if ($this->allowedRoles !== null) {
            if (!$this->currentPrincipal->hasAnyRole($this->allowedRoles)) {
                $this->response->setStatusCode(403, 'Forbidden');
                $this->response->setContent('<h1>403 Forbidden</h1><p>You do not have access to this page.</p>');
                $this->response->send();
                exit;
            }
        }
    }

    private function preventCaching(): void
    {
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Pragma', 'no-cache');
    }

    private function enforceCsrf(): void
    {
        if (!$this->request->isPost()) {
            return;
        }

        if ($this->security->getSessionToken() && $this->security->checkToken(null, null, false)) {
            return;
        }

        $this->flash->error('Your session expired or the form was resubmitted — please try again.');

        $referer = $this->request->getHTTPReferer();
        $this->response->redirect($referer ?: $this->url->get('backend'))->send();
        exit;
    }
}
