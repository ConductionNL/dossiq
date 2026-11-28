<?php

/**
 * Dossiq component contract registrar.
 *
 * The composite over the registrars that bind each component's Kernel
 * contracts to its implementation: contribution, integration, notification,
 * portal, review and Woo. Each binds its own; this one runs them in the order
 * Application::register() ran them.
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Contribution\ContributionContractRegistrar;
use OCA\Dossiq\Integration\IntegrationContractRegistrar;
use OCA\Dossiq\Notification\NotificationContractRegistrar;
use OCA\Dossiq\Portal\PortalContractRegistrar;
use OCA\Dossiq\Review\ReviewContractRegistrar;
use OCA\Dossiq\Woo\WooContractRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Runs every component's contract registrar.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ComponentContractRegistrar {
	/**
	 * Bind each component's contracts.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function register(IRegistrationContext $context): void {
		(new ContributionContractRegistrar())->register(context: $context);
		(new IntegrationContractRegistrar())->register(context: $context);
		(new NotificationContractRegistrar())->register(context: $context);
		(new PortalContractRegistrar())->register(context: $context);
		(new ReviewContractRegistrar())->register(context: $context);
		(new WooContractRegistrar())->register(context: $context);
	}//end register()
}//end class
