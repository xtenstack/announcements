<?php
declare(strict_types=1);

namespace Announcements\Controllers;

use Phalcon\Mvc\Controller;

/**
 * JSON surface auth — mirrors app/modules/api/controllers/ControllerBase.
 * php's session-or-API-key principal resolution, same copy the sibling
 * ai-ssa-phone/ai-ssa-email plugins already carry for their own /api/...
 * surfaces. Any authenticated principal may read here — including the
 * `agent` role (module plan: "staff, and agents, should be able to
 * programmatically view announcements") — so, unlike those two siblings,
 * this base does NOT default to admin-only; Api\AnnouncementsController
 * leaves $allowedRoles null and gates its one write action per-action
 * via requireRoles() instead (same shape as the backend controller's
 * requireAdmin()).
 */
class ApiControllerBase extends Controller
{
    protected ?array $allowedRoles = null;

    protected ?array $principal = null;

    protected function onConstruct()
    {
        $this->response->setContentType('application/json', 'UTF-8');

        if ($this->auth->isLoggedIn()) {
            $auth             = $this->session->get('auth');
            $this->principal  = ['type' => 'user', 'user_id' => $auth['id'], 'role_id' => $auth['role_id'], 'role_ids' => \App_skeleton\Auth::roleIdsFrom($auth)];
        } else {
            $token            = $this->apiKeyAuth->tokenFromRequest($this->request);
            $this->principal  = $token ? $this->apiKeyAuth->resolve($token) : null;
        }

        if (!$this->principal) {
            $this->response->setStatusCode(401, 'Unauthorized');
            $this->response->setJsonContent(['error' => 'Not authenticated']);
            $this->response->send();
            exit;
        }

        if ($this->allowedRoles !== null && !$this->currentPrincipal->hasAnyRole($this->allowedRoles)) {
            $this->response->setStatusCode(403, 'Forbidden');
            $this->response->setJsonContent(['error' => 'Forbidden']);
            $this->response->send();
            exit;
        }
    }

    /**
     * Per-action role gate by role *name* (roles are seeded rows, ids
     * differ per instance). Returns false with a 403 JSON response
     * already built — the calling action returns $this->response and the
     * framework sends it; no send()/exit needed from inside an action.
     *
     * @param string[] $roleNames
     */
    protected function requireRoles(array $roleNames): bool
    {
        if ($this->currentPrincipal->hasAnyRole(\Roles::idsByNames($roleNames))) {
            return true;
        }

        $this->response->setStatusCode(403, 'Forbidden');
        $this->response->setJsonContent(['error' => 'Forbidden: requires role ' . implode(' or ', $roleNames)]);

        return false;
    }

    protected function hasRole(array $roleNames): bool
    {
        return $this->currentPrincipal->hasAnyRole(\Roles::idsByNames($roleNames));
    }

    /**
     * JSON request body as an assoc array, or [] for an empty/unparseable
     * body — same helper the core api module's ControllerBase carries.
     */
    protected function getJsonBody(): array
    {
        $raw = $this->request->getRawBody();

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
