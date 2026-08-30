<?php

/**
 * Dossiq OpenRegister autoload prelude
 *
 * Puts OpenRegister's PSR-4 prefix on the autoloader so this app can reference
 * `OCA\OpenRegister\AppHost\…` from its own `Application::register()`.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo;

/**
 * Registers OpenRegister's autoload prefix before AppHost is referenced.
 *
 * ## Why this is needed (ADR-040)
 *
 * `OC_App::getEnabledApps()` does `sort($apps)`, and
 * `Coordinator::registerApps()` walks THAT sorted list calling
 * `OC_App::registerAutoloading($appId, $path)` and then `$app->register()` for
 * one app at a time. So every app's `register()` runs BEFORE the PSR-4 prefix
 * of every alphabetically-LATER app exists.
 *
 * `dossiq` sorts AFTER `openregister`, so the prefix happens to be on the
 * autoloader by the time `AppHostRegistrar::register()` runs today. That is the
 * alphabet, not a design property: the `class_exists()` guard in the registrar
 * cannot tell "OpenRegister is not installed" apart from "OpenRegister has not
 * registered its prefix yet", and both answer FALSE. Under the second, dossiq
 * would silently skip the whole AppHost engine — health, metrics, preferences,
 * deep links, the SPA page/catch-all, the dashboard widgets and the MCP
 * provider — on a perfectly healthy instance, with nothing in the UI to say so.
 *
 * Registering the prefix ourselves removes the dependency on ordering.
 * `OC_App::registerAutoloading()` is idempotent, so on the current ordering
 * this call is free.
 *
 * Lives in its own class rather than inline in the registrar so the degraded-
 * path contract — "this NEVER throws, whatever the instance looks like" — is
 * reachable from a unit test without a Nextcloud DI container.
 *
 * @spec openspec/specs/apphost-autoload-prelude/spec.md
 */
final class OpenRegisterAutoloader {
	/**
	 * Register OpenRegister's PSR-4 prefix on the composer autoloader.
	 *
	 * MUST be called before any `OCA\OpenRegister\…` reference in
	 * `Application::register()`, including a `class_exists()` probe — the probe
	 * answers FALSE, not "not yet loaded", and a FALSE is indistinguishable
	 * from OpenRegister being absent.
	 *
	 * `OC_App::registerAutoloading()` touches only the autoloader and is
	 * idempotent: it early-returns on an `$alreadyRegistered` key, so calling
	 * this more than once is free.
	 *
	 * Deliberately NOT `IAppManager::loadApp('openregister')`: that marks
	 * OpenRegister loaded and calls `Coordinator::bootApp()`, booting it before
	 * its own `register()` has run.
	 *
	 * @param string|null $appId App id to register the autoloader for.
	 *                           Production callers pass nothing and get
	 *                           'openregister'. It exists so the degraded
	 *                           path below — the branch that must NEVER
	 *                           rethrow — is reachable from a test with an id
	 *                           that cannot resolve; without it that branch
	 *                           is dead on any instance where OpenRegister IS
	 *                           installed, which is every instance this app
	 *                           is tested on.
	 *
	 * @return void This never reports success or failure. The caller's own
	 *              `class_exists()` guard is the authoritative signal; a
	 *              return value here would only duplicate it, and would add a
	 *              `return true`/`return false` pair of which exactly one is
	 *              dead in any given run.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OC_App is Nextcloud's legacy
	 * bootstrap class. There is no OCP interface for registering another app's
	 * autoloader, and this runs at the composition root where no container is
	 * available to resolve an adapter from.
	 *
	 * @spec openspec/specs/apphost-autoload-prelude/spec.md
	 */
	public static function register(?string $appId = null): void {
		try {
			// The app id is written as a literal at the call site rather than
			// defaulted in the signature, so it is visible where it is used —
			// to a reader, and to hydra gate-64, which reads
			// registerAutoloading()'s arguments. No return value: the caller's
			// class_exists() guard is the authoritative signal.
			$path = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppPath($appId ?? 'openregister');
			\OC_App::registerAutoloading($appId ?? 'openregister', $path);
		} catch (\Throwable) {
			// OpenRegister absent, disabled, or the server container is not up
			// (unit tests). The caller's class_exists() guard then skips the
			// AppHost plumbing exactly as it did before. Never rethrow: an
			// exception escaping here would abort the caller's entire
			// register(), which is the exact defect this prelude prevents.
		}
	}//end register()
}//end class
