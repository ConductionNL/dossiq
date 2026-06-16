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

// Polyfill easter_date() when the PHP `calendar` extension is not loaded
// (it is absent from the slim PHP-CLI image used in the dev container).
// Production Nextcloud images ship the calendar extension, so this guard is a
// no-op there. The algorithm is the standard Gauss/Meeus computation returning
// a Unix timestamp for noon (matching the extension's CAL_EASTER_DEFAULT).
if (function_exists('easter_date') === false) {
    /**
     * Compute the Unix timestamp of Easter Sunday for a Gregorian year.
     *
     * @param int|null $year The year (defaults to the current year).
     *
     * @return int Unix timestamp (UTC noon) of Easter Sunday.
     */
    function easter_date(?int $year=null): int
    {
        $year = ($year ?? (int) date('Y'));

        $a = ($year % 19);
        $b = intdiv($year, 100);
        $c = ($year % 100);
        $d = intdiv($b, 4);
        $e = ($b % 4);
        $f = intdiv(($b + 8), 25);
        $g = intdiv((($b - $f) + 1), 3);
        $h = (((19 * $a) + $b - $d - $g + 15) % 30);
        $i = intdiv($c, 4);
        $k = ($c % 4);
        $l = ((32 + (2 * $e) + (2 * $i) - $h - $k) % 7);
        $m = intdiv(($a + (11 * $h) + (22 * $l)), 451);

        $month = intdiv(($h + $l - (7 * $m) + 114), 31);
        $day   = ((($h + $l - (7 * $m) + 114) % 31) + 1);

        return gmmktime(0, 0, 0, $month, $day, $year);
    }//end easter_date()
}//end if

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

// Shared in-memory ObjectService fake. Lives in tests/Unit/Fixtures/ so
// every termijnbewaking + archief-edepot unit test file can resolve
// `FakeTermijnStore` even when run standalone (previously it sat at the
// bottom of TermijnServiceTest.php and only loaded if PHPUnit happened
// to require that file first).
require_once __DIR__.'/Unit/Fixtures/FakeTermijnStore.php';

// OCP\Http\Client interface stubs — the vendored nextcloud/ocp does not ship
// the OCP\Http\Client namespace, so services depending on IClientService
// (PublicationService, MandaatValidationService) cannot be mocked without these.
// Guarded by interface_exists() so they no-op under a real Nextcloud runtime.
require_once __DIR__ . '/Stubs/HttpClientStubs.php';

// IMcpToolProvider stub — loaded when the openregister runtime (PR #1466,
// ai-chat-companion-orchestrator) is absent. ProcestToolProvider implements
// OCA\OpenRegister\Mcp\IMcpToolProvider; the stub no-ops when the real
// interface is present (e.g. when the openregister app is installed). Must be
// in place before \OC_App::loadApp('procest') below tries to load that class.
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
    include_once __DIR__.'/Stubs/Mcp/IMcpToolProvider.php';
}

// Decidesk decision-event stubs — loaded when the decidesk app is absent so the
// procest delegation services + DecisionConcludedListener can be unit-tested
// against the decidesk event contract. The real classes ship in decidesk
// (OCA\Decidesk\Event\*); these stubs no-op when the real classes are present.
if (class_exists('\\OCA\\Decidesk\\Event\\DecisionRequestedEvent') === false) {
    include_once __DIR__.'/Stubs/Decidesk/Event/DecisionRequestedEvent.php';
}

if (class_exists('\\OCA\\Decidesk\\Event\\DecisionConcludedEvent') === false) {
    include_once __DIR__.'/Stubs/Decidesk/Event/DecisionConcludedEvent.php';
}

// OpenRegister AppHost stubs (ADR-040) — loaded when the openregister runtime
// is absent so Application::register() (Bootstrap::register) and procest's
// DashboardController (extends GenericDashboardController) resolve in bare CI
// containers + standalone static analysis. The stubs self-skip when the real
// classes are present (openregister installed).
if (class_exists('\\OCA\\OpenRegister\\AppHost\\Bootstrap') === false) {
    include_once __DIR__.'/Stubs/AppHost/Bootstrap.php';
}

if (class_exists('\\OCA\\OpenRegister\\AppHost\\Controller\\GenericDashboardController') === false) {
    include_once __DIR__.'/Stubs/AppHost/Controller/GenericDashboardController.php';
}

if (defined('OC_CONSOLE') === false && class_exists('\OC_App') === true) {
    \OC_App::loadApps();
    \OC_App::loadApp('procest');
    OC_Hook::clear();
}
