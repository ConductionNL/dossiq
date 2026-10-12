<?php

/**
 * Tells OpenRegister which Nextcloud Forms forms open a case, when the case schema is about to change.
 *
 * OpenRegister asks every app for its published forms that write into a schema. dossiq answers for the case schema with one form per published case type that has a Forms form bound to it, so a case schema change re-checks those bindings too.
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

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Intake\BoundFormsCheck;
use OCA\Dossiq\Service\Intake\IntakeFormBinding;
use OCA\Dossiq\Service\SettingsService;
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
	 * @param SettingsService   $settingsService The configured case schema.
	 * @param BoundFormsCheck   $check           Lists the bound case types and takes a binding off.
	 * @param IntakeFormBinding $binding         Reads a binding as a mapping.
	 * @param LoggerInterface   $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly BoundFormsCheck $check,
		private readonly IntakeFormBinding $binding,
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
		$formClass = BoundFormsCheck::FORM_DEPENDENT;
		if (is_a($event, BoundFormsCheck::DEPENDENTS_EVENT) === false || class_exists($formClass) === false) {
			return;
		}

		try {
			if ($this->isCaseSchema(schema: $event->getSchema()) === false) {
				return;
			}

			foreach ($this->check->boundCaseTypes() as $caseType) {
				$names = $this->check->namesOf(caseType: $caseType);
				if ($names === []) {
					continue;
				}

				$caseTypeId = $names[0];
				$event->addForm(
					new $formClass(
						id: $caseTypeId,
						app: Application::APP_ID,
						title: $this->titleOf(caseType: $caseType),
						author: $this->ownerOf(caseType: $caseType),
						mapping: $this->binding->mappingFor(caseType: $caseType),
						unpublish: function () use ($caseTypeId): void {
							$this->check->unbind(caseTypeId: $caseTypeId);
						},
					)
				);
			}
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
			if (method_exists($schema, $getter) === true && (string)$schema->$getter() === $expected) {
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
