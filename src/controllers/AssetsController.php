<?php
declare(strict_types=1);

namespace Announcements\Controllers;

/**
 * Serves this module's one static JS asset (assets/announcements.js) —
 * the same file-served-by-an-action pattern ai-ssa-chat uses for its
 * widget.js, because app_skeleton has no way for a Composer-installed
 * module to publish files under the host's /assets/ document root, and
 * the backend CSP (script-src 'self') forbids the inline alternative.
 *
 * Deliberately NOT extending ControllerBase: a static file needs no
 * session redirect, no CSRF check and no no-store cache headers, and a
 * plain JS file exposes nothing. Reachable only via the explicit route
 * in Module::registerRoutes() (the generic /announcements/:controller/
 * :action shape can't carry the ".js" action name).
 */
class AssetsController extends \Phalcon\Mvc\Controller
{
    private const ASSETS_DIR = __DIR__ . '/../../assets/';

    private const ALLOWED = ['announcements.js'];

    protected function onConstruct()
    {
        $this->view->disable();
    }

    public function fileAction()
    {
        // Named route placeholder ({name:...} in Module::registerRoutes()).
        $name = (string) $this->dispatcher->getParam('name', null, '');

        if (!in_array($name, self::ALLOWED, true) || !is_file(self::ASSETS_DIR . $name)) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setContent('Not found');
        }

        $this->response->setContentType('application/javascript', 'UTF-8');
        $this->response->setHeader('Cache-Control', 'public, max-age=300');

        return $this->response->setContent((string) file_get_contents(self::ASSETS_DIR . $name));
    }
}
