<?php

/**
 * PHPUnit Bootstrap
 *
 * Bootstrap file for PHPUnit tests in the Procest app.
 *
 * @category Tests
 * @package  OCA\Procest\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

require_once __DIR__.'/../vendor/autoload.php';

// Register OCP and NCU namespaces from the nextcloud/ocp stub package so that
// PHPUnit can mock OCP interfaces without a full Nextcloud installation.
$loaders = spl_autoload_functions();
foreach ($loaders as $loader) {
    if (is_array($loader) && $loader[0] instanceof \Composer\Autoload\ClassLoader) {
        $loader[0]->addPsr4('OCP\\', __DIR__.'/../vendor/nextcloud/ocp/OCP/');
        $loader[0]->addPsr4('NCU\\', __DIR__.'/../vendor/nextcloud/ocp/NCU/');
        break;
    }
}

// Load a real Nextcloud server first when one is present (CI). This must happen
// BEFORE the stub files below — base.php declares the real `OC`, the Doctrine
// DBAL classes, etc., and the stubs self-skip via class_exists()/interface_exists()
// guards when those already exist. Loading the stubs first would declare a stub
// `OC` and then crash with "Cannot declare class OC" the moment base.php runs.
if (defined('OC_CONSOLE') === false) {
    if (file_exists(__DIR__.'/../../../lib/base.php') === true) {
        include_once __DIR__.'/../../../lib/base.php';
    }

    if (file_exists(__DIR__.'/../../../tests/autoload.php') === true) {
        include_once __DIR__.'/../../../tests/autoload.php';
    }
}

// Load Doctrine DBAL and OC internal stubs so that PHPUnit can mock
// OCP\IDBConnection and OCP\DB\QueryBuilder\IQueryBuilder, which reference
// Doctrine types not present in this repository's vendor directory. Every
// declaration here is guarded by class_exists()/interface_exists(), so this is
// a no-op when a real Nextcloud (loaded above) already provides the classes.
require_once __DIR__.'/Unit/Stubs/DoctrineStubs.php';

// OC\Hooks\Emitter + OC\User\NoUserException stubs — OCP\Files\IRootFolder
// extends these OC-internal types, but they are not shipped in nextcloud/ocp.
// Without these stubs PHPUnit cannot mock IRootFolder in tests.
// Must be loaded BEFORE the real Nextcloud base.php (which self-skips via
// interface_exists guards when a real Nextcloud runtime is present).
require_once __DIR__.'/Unit/Stubs/OcInternalStubs.php';

// IMcpToolProvider stub — loaded when the openregister runtime (PR #1466,
// ai-chat-companion-orchestrator) is absent. ProcestToolProvider implements
// OCA\OpenRegister\Mcp\IMcpToolProvider; the stub no-ops when the real
// interface is present (e.g. when the openregister app is installed). Must be
// in place before \OC_App::loadApp('procest') below tries to load that class.
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
    include_once __DIR__.'/Stubs/Mcp/IMcpToolProvider.php';
}

// ObjectCreatedEvent stub — loaded when the openregister runtime is absent.
// VergunningaanvraagCreatedListener uses instanceof ObjectCreatedEvent; without
// this stub PHPUnit cannot create a mock of the class in unit tests.
if (class_exists(\OCA\OpenRegister\Event\ObjectCreatedEvent::class) === false) {
    include_once __DIR__.'/Stubs/Event/ObjectCreatedEventStub.php';
}

if (defined('OC_CONSOLE') === false && class_exists('\OC_App') === true) {
    \OC_App::loadApps();
    \OC_App::loadApp('procest');
    OC_Hook::clear();
}
