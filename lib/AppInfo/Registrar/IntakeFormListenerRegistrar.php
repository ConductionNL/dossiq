<?php

/**
 * Dossiq intake form listener registrar.
 *
 * The listeners that keep a form valid against the case it opens: the
 * check of a case type's own Forms binding, the re-check of the forms other
 * apps bound to a case type, and dossiq's answer when OpenRegister asks
 * which forms write into the case schema (decisions 179 and 181).
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
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\CaseTypeBoundFormsListener;
use OCA\Dossiq\Listener\IntakeFormBindingListener;
use OCA\Dossiq\Listener\IntakeFormDependentsListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the listeners that keep the forms that open a case valid.
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
 */
class IntakeFormListenerRegistrar {

	/**
	 * The event OpenRegister asks the owning apps for their forms with, by name.
	 *
	 * The same event `BoundFormsCheck::DEPENDENTS_EVENT` names; a registration
	 * test holds the two together.
	 *
	 * @var string
	 */
	public const DEPENDENTS_EVENT = 'OCA\\OpenRegister\\Event\\FormDestinationDependentsEvent';

	/**
	 * Register the form listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function register(IRegistrationContext $context): void {
		// A case type's intake form is judged against the case schema when the
		// case type is saved, before it is stored: a binding that would open a
		// case the schema refuses is refused here (decision 181).
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: IntakeFormBindingListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: IntakeFormBindingListener::class
		);

		// The published forms of other apps that open a case of this type are
		// judged on the same saves: refused before the save where the owning
		// app asks for that, unpublished after it otherwise.
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class, ObjectCreatedEvent::class, ObjectUpdatedEvent::class] as $caseTypeSave) {
			$context->registerEventListener(
				event: $caseTypeSave,
				listener: CaseTypeBoundFormsListener::class
			);
		}

		// OpenRegister asks which forms write into a schema before it changes;
		// dossiq answers for its own Forms bindings on the case schema.
		$context->registerEventListener(
			event: self::DEPENDENTS_EVENT,
			listener: IntakeFormDependentsListener::class
		);
	}//end register()
}//end class
