<?php
declare(strict_types=1);

namespace Announcements;

use Phalcon\Di\DiInterface;
use Phalcon\Autoload\Loader;
use Phalcon\Mvc\Router;
use Phalcon\Mvc\View;
use Phalcon\Mvc\View\Engine\Php as PhpEngine;
use Phalcon\Mvc\ModuleDefinitionInterface;

/**
 * Plugin-tier module (see docs/MODULE-SPEC.md) — registered the same way
 * an application-tier module is (fixed 2026-08-12, per MODULE-SPEC's
 * "Current behavior" note), so it gets a generic /announcements/... route
 * namespace for the admin AnnouncementsController for free. The read-only
 * JSON API needs a URL shaped like the core `api` module's own
 * (/api/announcements), which the generic shape can't produce — see
 * registerRoutes() below, same extension point the ai-ssa-chat/phone/
 * email plugins already use for their own /api/... surfaces.
 */
class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?DiInterface $di = null)
    {
        $loader = new Loader();

        $loader->setNamespaces([
            'Announcements\Controllers'     => __DIR__ . '/controllers/',
            'Announcements\Controllers\Api' => __DIR__ . '/controllers/Api/',
            // Also reachable through this package's composer psr-4 entry
            // ("Announcements\\": "src/"); registered here too so the
            // web dispatch path doesn't depend on how the plugin was
            // installed. src/tasks/ is composer-only on purpose: the
            // cron task lives in App_skeleton\Modules\Cli\Tasks, which
            // only CronRunner/the CLI ever resolve.
            'Announcements\Api'             => __DIR__ . '/Api/',
            'Announcements\Feeds'           => __DIR__ . '/Feeds/',
        ]);

        // Announcement is a bare/global model class — matching
        // requirements-module's Requirement/Changelog convention and the
        // sibling ai-ssa-* plugins' own Config models, can't be matched
        // by setNamespaces().
        $loader->setDirectories([
            __DIR__ . '/models/',
        ]);

        $loader->register();
    }

    public function registerServices(DiInterface $di)
    {
        $di['view'] = function () {
            $view = new View();
            $view->setViewsDir(__DIR__ . '/../views/');

            $view->registerEngines([
                '.volt'  => 'voltShared',
                '.phtml' => PhpEngine::class,
            ]);

            return $view;
        };
    }

    public function registerRoutes(Router $router)
    {
        // This module's one static JS file (see AssetsController) — an
        // explicit route because the generic /announcements/:controller/
        // :action shape can't express a ".js" action name. Registered
        // after the generic routes, so it wins the match.
        $router->add('/announcements/assets/{name:[a-z0-9.-]+\\.js}', [
            'namespace'  => 'Announcements\Controllers',
            'module'     => 'announcements',
            'controller' => 'assets',
            'action'     => 'file',
        ])->via(['GET']);

        $router->add('/api/announcements', [
            'namespace'  => 'Announcements\Controllers\Api',
            'module'     => 'announcements',
            'controller' => 'announcements',
            'action'     => 'index',
        ])->via(['GET']);

        // Write side of the same /api/announcements surface (admin only
        // — see Api\AnnouncementsController::createAction)
        // and the single-row read. Same pattern, different verbs: the
        // router only matches a route whose via() includes the request
        // method, so the GET list above and this POST coexist on one path.
        $router->add('/api/announcements', [
            'namespace'  => 'Announcements\Controllers\Api',
            'module'     => 'announcements',
            'controller' => 'announcements',
            'action'     => 'create',
        ])->via(['POST']);

        $router->add('/api/announcements/{id:[0-9]+}', [
            'namespace'  => 'Announcements\Controllers\Api',
            'module'     => 'announcements',
            'controller' => 'announcements',
            'action'     => 'view',
        ])->via(['GET']);
    }
}
