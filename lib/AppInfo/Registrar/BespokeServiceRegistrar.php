<?php

/**
 * Dossiq bespoke-plumbing registrar.
 *
 * Re-asserts the six dossiq-specific plumbing classes that the AppHost engine
 * has just aliased to its generics. Split out of Application so the explicit
 * factories — and the concrete Settings / Dashboard class references they need
 * — stay together in one place.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Controller\DashboardController;
use OCA\Dossiq\Controller\SettingsController;
use OCA\Dossiq\Repair\InitializeSettings;
use OCA\Dossiq\Sections\SettingsSection;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Re-registers the dossiq-bespoke plumbing the AppHost engine aliased to generics.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class BespokeServiceRegistrar {
	/**
	 * Re-register the dossiq-bespoke plumbing classes.
	 *
	 * A concrete-to-self alias (registerServiceAlias(X, X)) infinitely recurses
	 * on NC's container (the alias resolves itself), so each bespoke class is
	 * re-registered with an explicit factory that constructs the REAL dossiq
	 * class — overriding the Bootstrap generic factory for the same key.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			DashboardController::class,
			static function (ContainerInterface $c): DashboardController {
				return new DashboardController(
					request: $c->get('OCP\\IRequest')
				);
			}
		);
		$context->registerService(
			SettingsController::class,
			static function (ContainerInterface $c): SettingsController {
				return new SettingsController(
					request: $c->get('OCP\\IRequest'),
					container: $c,
					appManager: $c->get('OCP\\App\\IAppManager'),
					settingsService: $c->get(SettingsService::class),
					groupManager: $c->get('OCP\\IGroupManager'),
					userSession: $c->get('OCP\\IUserSession'),
					l10n: $c->get('OCP\\IL10N')
				);
			}
		);
		$context->registerService(
			SettingsService::class,
			static function (ContainerInterface $c): SettingsService {
				return new SettingsService(
					appConfig: $c->get('OCP\\IAppConfig'),
					appManager: $c->get('OCP\\App\\IAppManager'),
					container: $c,
					logger: $c->get('Psr\\Log\\LoggerInterface')
				);
			}
		);
		$context->registerService(
			InitializeSettings::class,
			static function (ContainerInterface $c): InitializeSettings {
				return new InitializeSettings(
					settingsService: $c->get(SettingsService::class),
					logger: $c->get('Psr\\Log\\LoggerInterface')
				);
			}
		);
		$context->registerService(
			AdminSettings::class,
			static function (ContainerInterface $c): AdminSettings {
				return new AdminSettings(
					appManager: $c->get('OCP\\App\\IAppManager'),
					initialState: $c->get('OCP\\AppFramework\\Services\\IInitialState'),
					settingsService: $c->get('OCA\\Dossiq\\Service\\SettingsService')
				);
			}
		);
		$context->registerService(
			SettingsSection::class,
			static function (ContainerInterface $c): SettingsSection {
				return new SettingsSection(
					l: $c->get('OCP\\IL10N'),
					urlGenerator: $c->get('OCP\\IURLGenerator')
				);
			}
		);
	}//end register()
}//end class
