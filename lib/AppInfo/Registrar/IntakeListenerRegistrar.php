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

use OCA\Dossiq\Listener\WooStartFromListener;
use OCA\Dossiq\Listener\IntakeRequirementsListener;
use OCA\Dossiq\Listener\IntakeTermStartListener;
use OCA\Dossiq\Listener\LocationBagValidationListener;
use OCA\Dossiq\Listener\ReportGroupingOnCreateListener;
use OCA\Dossiq\Listener\VergunningaanvraagCreatedListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
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
	 * The priority the receipt and start dates are set at, before the case is stored.
	 *
	 * Above OpenRegister's CalculationOnSaveListener (default priority 0), so
	 * the materialised `startDate` calculation finds the day dossiq set and
	 * does not fall back to its own `now`, which it formats in UTC.
	 *
	 * @var int
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	public const RECEIPT_DATES_PRIORITY = 100;

	/**
	 * The event OpenRegister sends when a draft is sent, by name.
	 *
	 * The same event `ReceiptMoment::ACTIVATED_EVENT` names; a registration
	 * test holds the two together.
	 *
	 * @var string
	 */
	public const DRAFT_SENT_EVENT = 'OCA\\OpenRegister\\Event\\ObjectActivatedEvent';

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
		// A new Woo request that names an earlier one starts from its
		// configuration (woo-request-corpus-collection REQ-WRC-005).
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: WooStartFromListener::class
		);

		// A new case is placed once in hermiq's grouping of similar reports
		// (ai-features-on-the-case, REQ-AIC-04). The job reads whether the case
		// type declares the feature. The acknowledgement of receipt is queued
		// on its own and never reads the group: a group does not reduce the
		// confirmations owed.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: ReportGroupingOnCreateListener::class
		);
		// A draft is not a report yet; it is grouped when it is sent.
		$context->registerEventListener(
			event: self::DRAFT_SENT_EVENT,
			listener: ReportGroupingOnCreateListener::class
		);
		// Integriq writes the activity mapping onto its dso_verzoek in an
		// update, so the case is made on that write.
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
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

		// The forms that open a case: a case type's own Forms binding, the
		// forms of other apps bound to a case type, and dossiq's answer when
		// OpenRegister asks which forms write into the case schema.
		(new IntakeFormListenerRegistrar())->register(context: $context);

		// The receipt and start dates are SET BEFORE THE CASE IS STORED, above
		// OpenRegister's materialised calculations, which run on the creating
		// event at the default priority. The `startDate` calculation falls back
		// to `now` in UTC; set first, the day is the one in the administered
		// zone and the calculation keeps it. The created-event registration in
		// WorkflowListenerRegistrar stays as the fallback for the bulk save.
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: IntakeTermStartListener::class,
			priority: self::RECEIPT_DATES_PRIORITY
		);

		// A case saved as a draft is not received yet (decision 180). The save
		// that moves it out of `draft` is its receipt, so the same listener
		// sets the stamp and the dates in that save, again above the
		// calculations: the deadline is then counted from the start date
		// written here, and the submit answer carries both.
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: IntakeTermStartListener::class,
			priority: self::RECEIPT_DATES_PRIORITY
		);
	}//end register()
}//end class
