<?php
declare(strict_types=1);

/**
 * This plugin ships its own PHPUnit suite — module.json/ModuleManager
 * have no concept of "a module's tests", so there's no automatic wiring
 * into app_skeleton's own tests/Unit + tests/Feature testsuites
 * (phpunit.xml). It's run standalone instead, from the app_skeleton
 * root, once installed via composer.local.json
 * (docs/INTERNAL-MODULES.md's path-repo mechanism, pointed at this repo
 * instead of the private ../internal one — see this plugin's own
 * README/PR description for the exact composer.local.json shape):
 *
 *   vendor/bin/phpunit -c vendor/xtendeploy/announcements/phpunit.xml
 *
 * BASE_PATH climbs four levels from this file's real, composer-installed
 * location (vendor/xtendeploy/announcements/tests/ — "symlink": false
 * per INTERNAL-MODULES.md, so this is a real copy, not a symlink back
 * into a sibling checkout) back to the app_skeleton root, then reuses
 * that repo's own Feature/HttpClient.php rather than vendoring a second
 * copy — same "real HTTP against the real running stack" philosophy as
 * every other Feature test here (see HttpClient's own docblock for why:
 * ControllerBase's role/CSRF checks call `exit;` on an early-return
 * response, which would kill an in-process PHPUnit run).
 */
define('BASE_PATH', dirname(__DIR__, 4));
define('APP_PATH', BASE_PATH . '/app');

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/tests/Feature/HttpClient.php';

/**
 * The fixture helpers below (draftAnnouncement() et al.) build
 * \Announcement rows directly, same "fixtures via the model, behavior
 * under test via real HTTP" split every other Feature test here uses
 * (e.g. ApiTicketsCloseControllerTest::newTicket()). Announcement is a
 * bare/global model class discovered by Announcements\Module::
 * registerAutoloaders() only when Phalcon actually dispatches a request
 * into this module — never the case for a CLI-booted PHPUnit process, so
 * it's required directly here instead, the same way this app's own core
 * bare models (Users, Roles, ...) need app/config/loader.php's
 * setDirectories() call, which runs a few lines below.
 */
$di = new \Phalcon\Di\FactoryDefault();
require APP_PATH . '/config/services.php';
require APP_PATH . '/config/loader.php';
\Phalcon\Di\Di::setDefault($di);

require __DIR__ . '/../src/models/Announcement.php';
