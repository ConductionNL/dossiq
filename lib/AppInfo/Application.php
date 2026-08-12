<?php

/**
 * Procest Application
 *
 * Main application class for the Procest case management app.
 *
 * This class is deliberately thin. Every actual registration lives in a
 * dedicated registrar under `lib/AppInfo/Registrar/`, so the class references
 * each subsystem needs (listeners, adapters, middleware, widgets) sit with that
 * subsystem instead of accumulating on the bootstrap class. Application only
 * knows the three phases: bind services, wire listeners, boot.
 *
 * @category AppInfo
 * @package  OCA\Procest\AppInfo
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\AppInfo;

use OCA\Procest\AppInfo\Registrar\BootRegistrar;
use OCA\Procest\AppInfo\Registrar\ListenerRegistrar;
use OCA\Procest\AppInfo\Registrar\ServiceRegistrar;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Main application class for the Procest case management app.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'procest';

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
