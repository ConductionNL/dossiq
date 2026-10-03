<?php

/**
 * Dossiq intake listener registrar.
 *
 * The DSO Omgevingsloket intake and BAG location-validation listeners,
 * extracted from {@see ObjectListenerRegistrar} so each registrar stays
 * subsystem-scoped (and under the coupling ceiling the quality gates
 * enforce).
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

use OCA\Dossiq\Listener\IntakeRequirementsListener;
use OCA\Dossiq\Listener\LocationBagValidationListener;
use OCA\Dossiq\Listener\VergunningaanvraagCreatedListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the DSO intake and BAG location-validation listeners.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class IntakeListenerRegistrar {
	/**
	 * Register the DSO intake and BAG location-validation listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// DSO Omgevingsloket: create a Dossiq zaak when a vergunningaanvraag is
		// written by OpenRegister.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: VergunningaanvraagCreatedListener::class
		);

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
	}//end register()
}//end class
