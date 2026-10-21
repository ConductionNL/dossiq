<?php

/**
 * Dossiq case type intake listener registrar.
 *
 * One listener on the four events a case is received through: before it is
 * stored (created, or a draft being sent) it is shaped by its case type's
 * `portalIntake`, and once it exists (created, or a draft sent) it is linked
 * to the resident's dossier (case-intake-per-case-type, decision a1).
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
 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\CaseTypeIntakeListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the case type intake listener.
 *
 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
 */
class CaseTypeIntakeListenerRegistrar {
	/**
	 * Register the listener on the receipt events.
	 *
	 * Registered after IntakeListenerRegistrar, so a case its requirements
	 * refuse is never shaped.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
	 */
	public function register(IRegistrationContext $context): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class, ObjectCreatedEvent::class, IntakeListenerRegistrar::DRAFT_SENT_EVENT] as $event) {
			$context->registerEventListener(event: $event, listener: CaseTypeIntakeListener::class);
		}
	}//end register()
}//end class
