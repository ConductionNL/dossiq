<?php

/**
 * Dossiq intake validation listener registrar.
 *
 * Registers the listeners that check a case before it is stored and log
 * what it processes once it is: the BAG location check, the intake
 * requirements a case type declares, and the special-category processing
 * log. Split out of
 * {@see \OCA\Dossiq\Listener\Registrar\IntakeListenerRegistrar}, which
 * keeps the intake, grouping, form, mail and receipt-date registrations and
 * calls this one.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\Listener\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Registrar;

use OCA\Dossiq\Listener\IntakeRequirementsListener;
use OCA\Dossiq\Listener\LocationBagValidationListener;
use OCA\Dossiq\Listener\SpecialCategoryProcessingLogListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The listeners that check a case at intake.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class IntakeValidationListenerRegistrar {

	/**
	 * Register the BAG check, the intake requirements and the processing log.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function register(IRegistrationContext $context): void {
		$this->registerBagValidationListeners(context: $context);
		$this->registerIntakeRequirementsListeners(context: $context);
	}//end register()

	/**
	 * Register the pre-persist BAG location validation.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function registerBagValidationListeners(IRegistrationContext $context): void {
		// Bag-location-save-validation: pre-persist location source=bag
		// enforcement (closes bag-register-adapter tasks.md item 4.1).
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: LocationBagValidationListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: LocationBagValidationListener::class
		);
	}//end registerBagValidationListeners()

	/**
	 * Register the intake requirements check on the creating and updating events.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function registerIntakeRequirementsListeners(IRegistrationContext $context): void {
		// Intake-triage-and-refusal: a case type declares what must be answered
		// before a case exists, and who may hold it. Enforced here rather than
		// in the create form, because the form is one of five ways a case is
		// written and the other four would walk past it.
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: IntakeRequirementsListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: IntakeRequirementsListener::class
		);

		// The special categories a case processes go to the processing log
		// once they are saved (social-domain-consent-and-safeguards 3.1).
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: SpecialCategoryProcessingLogListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: SpecialCategoryProcessingLogListener::class
		);
	}//end registerIntakeRequirementsListeners()
}//end class
