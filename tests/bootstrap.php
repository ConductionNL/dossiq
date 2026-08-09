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

// Guard against a dangling vendor/nextcloud/ocp/OCP symlink. The nextcloud/ocp
// composer package normally vendors real OCP\ source files, but when its
// post-install step runs inside a live Nextcloud dev container (bind-mounted
// at /var/www/html), it instead symlinks OCP/ -> /var/www/html/lib/public as
// an optimisation. That symlink is only valid *inside that specific live
// container*. If vendor/ is later copied (e.g. rsync'd) into a bare CI/test
// container that has no /var/www/html, the symlink dangles and every OCP\
// class fails to resolve — surfacing 100+ lines deep as a misleading
// "Class ... not found" error from an unrelated stub include (see the
// 2026-07-14 dev-test-failures investigation, where this dangling symlink
// was mistaken for a code regression across PRs #202-#211 before being
// traced here). Fail fast with an actionable message instead.
$ocpVendorDir = __DIR__.'/../vendor/nextcloud/ocp/OCP';
if (is_link($ocpVendorDir) === true && file_exists($ocpVendorDir) === false) {
    fwrite(
        STDERR,
        "\nFATAL: vendor/nextcloud/ocp/OCP is a dangling symlink to "
        .(readlink($ocpVendorDir) ?: '(unknown target)')."\n"
        ."This happens when vendor/ is copied from a checkout that was "
        ."composer-installed inside a live Nextcloud dev container (which "
        ."symlinks OCP/ to that container's /var/www/html/lib/public) into "
        ."an environment without that path — e.g. rsync'ing vendor/ into a "
        ."bare php:8.3-cli test container.\n"
        ."Fix: rm -rf vendor && composer install --no-interaction "
        ."--ignore-platform-reqs (do NOT rsync vendor/ from a live-NC-mounted "
        ."checkout).\n\n"
    );
    exit(1);
}//end if

unset($ocpVendorDir);

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

// Load the OC-internal and Doctrine stubs FIRST — before the OCP pre-load and
// before any OCP autoloader is registered.
//
// These used to be required at the BOTTOM of this file, which made them useless
// to the two consumers that actually need them:
//
//   1. The multi-pass OCP classmap pre-load below filters the Nextcloud
//      classmap down to `OCP\` / `NCU\` entries only, so it never loads
//      `OC\Hooks\Emitter`. `OCP\Files\IRootFolder extends Folder, Emitter`, so
//      IRootFolder failed to declare on every one of the 10 passes and ended up
//      cached nowhere — producing "Class or interface OCP\Files\IRootFolder
//      does not exist" for every test that mocks it.
//   2. `OCP\DB\QueryBuilder\IQueryBuilder` evaluates class constants that
//      reference `Doctrine\DBAL\ParameterType` at parse time, so the Doctrine
//      placeholders must likewise already be in the class table.
//
// Every declaration in both files is class_exists()/interface_exists()-guarded,
// so loading them this early is a no-op when a real Nextcloud runtime later
// supplies the genuine classes.
require_once __DIR__.'/Unit/Stubs/DoctrineStubs.php';
require_once __DIR__.'/Unit/Stubs/OcInternalStubs.php';

// Pre-load ALL OCP\ / NCU\ classes from the real Nextcloud lib/public tree
// BEFORE lib/base.php runs. This ensures every OCP interface/class is already
// in PHP's class cache before any installed-app vendor autoloader (e.g.
// openregister, which loads its old nextcloud/ocp v31 stub from inside
// Application::register()) can supply a stale version.
//
// Background: NC 34 uses #[\Override] in OC\Settings\Manager, OC\URLGenerator,
// OC\Activity\Manager, OC\Notification\Manager, etc. These reference interface
// methods added in NC 33 (getAdminDelegatedSettings, bulkPublish,
// linkToRemote, …). Some Conduction apps ship nextcloud/ocp v31 stubs (missing
// those methods) and load their own vendor autoloader with $loader->register(true)
// during Application::register(), placing the old classmap at the FRONT of the
// SPL queue. PHP 8.4 validates #[\Override] at class-compile time and throws a
// fatal error when the interface resolved is the stale stub.
//
// The files are loaded in multiple passes (up to 10) so that inter-interface
// dependencies are resolved automatically: if pass N fails to load a file
// because its dependency is not yet loaded, pass N+1 retries after the
// dependency has been loaded by an earlier successful entry. The try/catch
// swallows only genuine non-fixable errors; once a class is cached the pass
// exits early.
// NC ships Psr\Http\Client\ClientInterface (and other PSR packages) via its
// 3rdparty/ directory, registered through 3rdparty/autoload.php. Some OCP
// interfaces extend PSR interfaces (e.g. OCP\Http\Client\IClient extends
// Psr\Http\Client\ClientInterface). Without 3rdparty registered first, those
// OCP interfaces cannot be included in the multi-pass pre-load below, which
// leaves them uncached and lets openregister's stale classmap loader supply an
// older stub version that omits recently-added methods.
if (file_exists(__DIR__.'/../../../3rdparty/autoload.php') === true) {
    require_once __DIR__.'/../../../3rdparty/autoload.php';
}

$ncLibPublicDir = realpath(__DIR__.'/../../../lib/public');
if ($ncLibPublicDir !== false && is_dir($ncLibPublicDir) === true) {
    $ncClassmapFile = __DIR__.'/../../../lib/composer/composer/autoload_classmap.php';
    if (file_exists($ncClassmapFile) === true) {
        /** @var array<string,string> $ncFullClassmap */
        $ncFullClassmap = require $ncClassmapFile;

        // Filter to OCP\ and NCU\ entries only.
        $ocpClassmap = array_filter(
            $ncFullClassmap,
            static function (string $class): bool {
                return strncmp($class, 'OCP\\', 4) === 0 || strncmp($class, 'NCU\\', 4) === 0;
            },
            ARRAY_FILTER_USE_KEY
        );

        // Multi-pass load: retry on dependency errors until stable.
        $pending = $ocpClassmap;
        for ($pass = 0; $pass < 10 && count($pending) > 0; $pass++) {
            $stillPending = [];
            foreach ($pending as $class => $file) {
                if (class_exists($class, false) === true
                    || interface_exists($class, false) === true
                    || trait_exists($class, false) === true
                    || (function_exists('enum_exists') === true && enum_exists($class, false) === true)
                ) {
                    // Already cached by an earlier pass or autoloader.
                    continue;
                }

                if (file_exists($file) === false) {
                    continue;
                }

                // Use plain `include` (not `include_once`) so that if the
                // first pass fails mid-file (because a dependency was not yet
                // loaded), a subsequent pass can retry the same file. PHP's
                // `include_once` marks the file as "included" even after a
                // partial-failure, preventing any later retry.
                try {
                    @include $file;
                } catch (\Throwable $e) {
                    // Dependency not yet loaded — defer to next pass.
                    $stillPending[$class] = $file;
                }
            }//end foreach

            $pending = $stillPending;
        }//end for

        unset($ocpClassmap, $ncFullClassmap, $pending, $stillPending);
    }//end if
}//end if

// Load a real Nextcloud server when one is present (CI/dev container). This
// must happen BEFORE the stub OCP PSR-4 registration below so that NC's
// classmap autoloader takes priority for any remaining OCP classes.
if (defined('OC_CONSOLE') === false) {
    if (file_exists(__DIR__.'/../../../lib/base.php') === true) {
        include_once __DIR__.'/../../../lib/base.php';
    }

    if (file_exists(__DIR__.'/../../../tests/autoload.php') === true) {
        include_once __DIR__.'/../../../tests/autoload.php';
    }
}

// Register OCP and NCU namespaces from the nextcloud/ocp stub package so that
// PHPUnit can mock OCP interfaces without a full Nextcloud installation.
// When a real Nextcloud is present (base.php was loaded above), the NC classmap
// loader already owns the OCP\ namespace, so we skip the stub registration to
// avoid overriding real OCP classes with an older stub version.
//
// This must test whether Nextcloud ACTUALLY BOOTSTRAPPED, not whether its
// `lib/base.php` merely exists on disk. In the standard `apps-extra/` checkout
// layout that file always exists, so the previous `file_exists()` check
// unconditionally suppressed the fallback registration — including in the
// common case where base.php was never loaded at all because phpunit.xml
// defines OC_CONSOLE (see the guard above), leaving the OCP\ namespace with no
// autoloader whatsoever. `\OC_App` is declared by base.php and by nothing else,
// so its presence is a true "NC runtime is live" signal.
$ncBaseLoaded = class_exists('\OC_App', false);
if ($ncBaseLoaded === false) {
    $loaders = spl_autoload_functions();
    foreach ($loaders as $loader) {
        if (is_array($loader) && $loader[0] instanceof \Composer\Autoload\ClassLoader) {
            $loader[0]->addPsr4('OCP\\', __DIR__.'/../vendor/nextcloud/ocp/OCP/');
            $loader[0]->addPsr4('NCU\\', __DIR__.'/../vendor/nextcloud/ocp/NCU/');
            break;
        }
    }
}

// (DoctrineStubs.php and OcInternalStubs.php are loaded near the top of this
// file — they must precede the OCP pre-load, not follow it. See the comment
// above the pre-load block.)

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

// bag-location-save-validation: pre-persist OpenRegister event stubs —
// loaded when the openregister runtime is absent so
// LocationBagValidationListenerTest can exercise handle() against real
// stopPropagation()/setErrors() semantics. Self-skip when openregister is
// installed (real classes present).
if (class_exists('\\OCA\\OpenRegister\\Event\\ObjectCreatingEvent') === false) {
    include_once __DIR__.'/Stubs/Event/ObjectCreatingEventStub.php';
}

if (class_exists('\\OCA\\OpenRegister\\Event\\ObjectUpdatingEvent') === false) {
    include_once __DIR__.'/Stubs/Event/ObjectUpdatingEventStub.php';
}

// bezwaar-decision: the post-persist counterpart, so
// BezwaarDecisionListenerTest can exercise the guard's real decision through
// handle() — including the probe's call shape, which is what silently broke.
if (class_exists('\\OCA\\OpenRegister\\Event\\ObjectUpdatedEvent') === false) {
    include_once __DIR__.'/Stubs/Event/ObjectUpdatedEventStub.php';
}

// REQ-SUB-007 bewijsstuk immutability: the pre-persist delete counterpart, so
// BewijsstukImmutabilityListenerTest can exercise the reject path on delete.
if (class_exists('\\OCA\\OpenRegister\\Event\\ObjectDeletingEvent') === false) {
    include_once __DIR__.'/Stubs/Event/ObjectDeletingEventStub.php';
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
