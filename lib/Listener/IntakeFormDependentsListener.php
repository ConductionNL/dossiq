<?php

/**
 * Tells OpenRegister which Nextcloud Forms forms open a case, when the case schema is about to change.
 *
 * OpenRegister asks every app for its published forms that write into a
 * schema. dossiq answers for the case schema with one form per published case
 * type that has a Forms form bound to it, so a case schema change re-checks
 * those bindings too.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\BoundFormsCheckInterface;
use OCA\Dossiq\Kernel\Contracts\IntakeFormBindingInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Intake\IntakeFormBindings;
use OCA\OpenRegister\Event\FormDestinationDependentsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Announce dossiq's own intake form bindings as forms that write into the case schema.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
 */
class IntakeFormDependentsListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface   $settingsService The configured case schema.
	 * @param BoundFormsCheckInterface    $check           Names a case type.
	 * @param IntakeFormBindings $bindings        Lists the bound case types and takes a binding off.
	 * @param IntakeFormBindingInterface $binding         Reads a binding as a mapping.
	 * @param LoggerInterface   $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly BoundFormsCheckInterface $check,
		private readonly IntakeFormBindings $bindings,
		private readonly IntakeFormBindingInterface $binding,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Add one form per published case type with a bound Forms form, when the schema is the case schema.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function handle(Event $event): void {
		$formClass = BoundFormsCheckInterface::FORM_DEPENDENT;
		if (($event instanceof FormDestinationDependentsEvent) === false || class_exists($formClass) === false) {
			return;
		}

		try {
			$this->announceForms(event: $event, formClass: $formClass);
		} catch (Throwable $e) {
			// A schema save must not fail on this answer. The price is that
			// the save is judged without dossiq's forms, so say so loudly.
			$this->logger->error(
				'Dossiq intake: could not list the intake forms that write into the case schema about to change',
				['reason' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Announce every bound case type's form when the schema about to change is the case schema.
	 *
	 * @param FormDestinationDependentsEvent $event     The engine's question.
	 * @param string                         $formClass The engine's form dependent class.
	 *
	 * @return void
	 */
	private function announceForms(FormDestinationDependentsEvent $event, string $formClass): void {
		if ($this->isCaseSchema(schema: $event->getSchema()) === false) {
			return;
		}

		foreach ($this->bindings->boundCaseTypes() as $caseType) {
			$form = $this->formOf(caseType: $caseType, formClass: $formClass);
			if ($form !== null) {
				$event->addForm($form);
			}
		}
	}//end announceForms()

	/**
	 * The form dependent a bound case type announces, or null when it names no form.
	 *
	 * @param array<string, mixed> $caseType  The bound case type.
	 * @param string               $formClass The engine's form dependent class.
	 *
	 * @return object|null
	 */
	private function formOf(array $caseType, string $formClass): ?object {
		$names = $this->check->namesOf(caseType: $caseType);
		if ($names === []) {
			return null;
		}

		$caseTypeId = $names[0];
		return new $formClass(
			id: $caseTypeId,
			app: AppIdentity::APP_ID,
			title: $this->titleOf(caseType: $caseType),
			author: $this->ownerOf(caseType: $caseType),
			mapping: $this->binding->mappingFor(caseType: $caseType),
			unpublish: function () use ($caseTypeId): void {
				$this->bindings->unbind(caseTypeId: $caseTypeId);
			},
		);
	}//end formOf()

	/**
	 * Whether the proposed schema is the case schema.
	 *
	 * @param object $schema The proposed schema.
	 *
	 * @return bool True when its id, uuid or slug is the configured case schema.
	 */
	private function isCaseSchema(object $schema): bool {
		$expected = $this->settingsService->getConfigValue('case_schema');
		if ($expected === '') {
			return false;
		}

		foreach (['getId', 'getUuid', 'getSlug'] as $getter) {
			if (is_callable([$schema, $getter]) === true && (string)$schema->$getter() === $expected) {
				return true;
			}
		}

		return false;
	}//end isCaseSchema()

	/**
	 * The name an administrator knows the form by: the case type's.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return string The title.
	 */
	private function titleOf(array $caseType): string {
		foreach (['title', 'name', 'identifier'] as $key) {
			$value = ($caseType[$key] ?? '');
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return '';
	}//end titleOf()

	/**
	 * The user to tell when the binding is taken off: the case type's owner, when it has one.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return string|null The user id.
	 */
	private function ownerOf(array $caseType): ?string {
		$self = ($caseType['@self'] ?? []);
		if (is_array($self) === true && is_string($self['owner'] ?? null) === true && $self['owner'] !== '') {
			return $self['owner'];
		}

		return null;
	}//end ownerOf()
}//end class
