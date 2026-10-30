<?php

/**
 * Dossiq SaaS service registrar.
 *
 * Held the config-reading factory of ShillinqIntegrationService (the Shillinq
 * invoicing endpoint and API key). That service now raises shillinq's
 * in-process command and is autowired, so nothing is registered here today. The tenant token service it also built is deleted
 * (Q2, 2026-10-08); `jwt_signing_secret` stays, read by `PortalAssertionVerifier`.
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

use OCA\Dossiq\Listener\OrganisationStatusChangeListener;
use OCA\Dossiq\Listener\QuotaConsumptionListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * SaaS registrations (the tenant status listener; the Shillinq factory retired).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class SaasServiceRegistrar {
	/**
	 * Register the SaaS services the middleware chain factories from app config.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// ShillinqIntegrationService is autowired since it raises shillinq's
		// BillablePeriodClosedEvent in-process (dossiq-delivers-nothing phase 5,
		// decision 174): no endpoint, no API key, so no config-reading factory.

		// A tenant's status is OpenRegister's (tenancy-onto-openregister-organisation
		// 6.9). Its changes reach the tenant audit trail through the update event
		// OrganisationMapper dispatches, including the unsettled billing count
		// when a tenant ends.
		$context->registerEventListener(
			event: OrganisationUpdatedEvent::class,
			listener: OrganisationStatusChangeListener::class
		);

		// Creating a case or adding a tenant user consumes the tenant's quota:
		// refused before the save past a blocking limit, counted after it.
		foreach ([ObjectCreatingEvent::class, ObjectCreatedEvent::class] as $quotaEvent) {
			$context->registerEventListener(
				event: $quotaEvent,
				listener: QuotaConsumptionListener::class
			);
		}
	}//end register()
}//end class
