<?php

/**
 * Dossiq Application
 *
 * Main application class for the Dossiq case management app.
 *
 * This class is deliberately thin. Every actual registration lives in a
 * dedicated registrar under `lib/AppInfo/Registrar/`, so the class references
 * each subsystem needs (listeners, adapters, middleware, widgets) sit with that
 * subsystem instead of accumulating on the bootstrap class. Application only
 * knows the three phases: bind services, wire listeners, boot.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Dossiq\AppInfo\Registrar\BootRegistrar;
use OCA\Dossiq\AppInfo\Registrar\ListenerRegistrar;
use OCA\Dossiq\AppInfo\Registrar\ServiceRegistrar;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Main application class for the Dossiq case management app.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class Application extends App implements IBootstrap {
	/*
	 * NOTE: this moved from 'procest' to 'dossiq' with <id> in appinfo/info.xml.
	 * Nextcloud namespaces oc_appconfig and oc_preferences by app id, so the two
	 * Repair\Migrate* steps registered in info.xml carry the stored values
	 * across. The OpenRegister register SLUG deliberately did NOT move and is
	 * still 'procest' — see the note in appinfo/info.xml.
	 */
	public const APP_ID = 'dossiq';

	/**
	 * Constructor for the Application class.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register event listeners and services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {

		// ADR-084: services type-hint OpenRegister's PUBLISHED interface, never its
		// concrete class, so this app's unit tests can mock a type they are able to
		// load. Nextcloud autowires concrete classes across apps but not interfaces,
		// so the binding has to be stated — and the composition root is where this
		// app says how it is wired.
		//
		// An ALIAS, not a factory: it resolves when something actually asks for the
		// interface, so an instance without OpenRegister fails at the route that
		// needed the data rather than at registration. Both names are strings and
		// neither triggers an autoload, which is what keeps ADR-083 rule 3's promise
		// that the start screen still boots.
		$context->registerServiceAlias(
			ObjectServiceInterface::class,
			'OCA\OpenRegister\Service\ObjectService'
		);

		// Storage seam for the Dutch-to-English value migration. The repair step
		// depends on the interface so its own logic can be exercised against a
		// fake; only this binding knows the database.
		$context->registerServiceAlias(
			\OCA\Dossiq\Repair\ValueMigrationPort::class,
			\OCA\Dossiq\Repair\DbValueMigrationPort::class
		);
		(new ServiceRegistrar())->register(context: $context);
		(new ListenerRegistrar())->register(context: $context);
	}//end register()

	/**
	 * Boot the application.
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function boot(IBootContext $context): void {
		$container = $context->getServerContainer();

		(new BootRegistrar())->boot(
			dispatcher: $container->get(IEventDispatcher::class),
			server: $container
		);
	}//end boot()
}//end class
