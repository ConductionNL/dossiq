<?php

/**
 * Judges a case type's intake form binding against the case schema.
 *
 * A form knows its destination and must be valid against that destination's
 * schema when the binding is made, not on the first submission (decisions 179
 * and 181). The judging is OpenRegister's: its form destination validator
 * returns findings for a mapping and a schema. This class hands it the mapping
 * of {@see IntakeFormBinding} and the live case schema, and returns what it
 * found.
 *
 * OpenRegister is reached by class name. Without the validator the binding is
 * not judged and the reason is logged; the case type is saved as before.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The findings on a case type's intake form binding.
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
 */
class IntakeFormBindingCheck {

	/**
	 * OpenRegister's validator of a form mapping against its destination schema.
	 *
	 * @var string
	 */
	public const VALIDATOR = 'OCA\\OpenRegister\\Service\\Form\\FormDestinationValidator';

	/**
	 * OpenRegister's schema mapper.
	 *
	 * @var string
	 */
	public const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * The rule a refused binding names.
	 *
	 * @var string
	 */
	public const RULE = 'intake-form-binding-invalid';

	/**
	 * The finding code for a property that must be filled and that the form does not fill.
	 *
	 * The same code OpenRegister's validator gives a required schema property
	 * without a source, so a reader meets one vocabulary.
	 *
	 * @var string
	 */
	public const REQUIRED_UNMAPPED = 'required-unmapped';

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param IntakeFormBinding  $binding         Reads the binding as a mapping.
	 * @param LoggerInterface    $logger          Logger.
	 * @param IntakeRequirements $requirements    What a case type asks for before a case exists.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IntakeFormBinding $binding,
		private readonly LoggerInterface $logger,
		private readonly IntakeRequirements $requirements = new IntakeRequirements(),
	) {
	}//end __construct()

	/**
	 * Every problem with the case type's intake form binding.
	 *
	 * @param array<string, mixed> $caseType The case type as it is about to be saved.
	 *
	 * @return array<int, array<string, mixed>> The findings: `property`, `code`, `message` and, for an answer, `field`. Empty when valid or unbound.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function findingsFor(array $caseType): array {
		if ($this->binding->formRef(caseType: $caseType) === '') {
			return [];
		}

		$validator = $this->settingsService->getOpenRegisterClass(self::VALIDATOR);
		if ($validator === null || method_exists($validator, 'validate') === false) {
			$this->logger->warning(
				'Dossiq intake: OpenRegister has no form destination validator, so the intake form of a case '
					. 'type was saved without being checked against the case schema'
			);

			return [];
		}

		$schema = $this->caseSchema();
		if ($schema === null) {
			$this->logger->warning(
				'Dossiq intake: the case schema could not be read, so the intake form of a case type was '
					. 'saved without being checked against it'
			);

			return [];
		}

		$mapping = $this->binding->mappingFor(caseType: $caseType);
		$found = $validator->validate(mapping: $mapping, schema: $schema);
		if (is_array($found) === false) {
			$found = [];
		}

		return array_merge(
			array_values(array_filter($found, 'is_array')),
			$this->unmetRequirements(caseType: $caseType, mapping: $mapping)
		);
	}//end findingsFor()

	/**
	 * What a published case type asks for before a case exists and the form does not fill.
	 *
	 * The case schema does not know this list: it is the case type's own
	 * `intakeRequirements.requiredBeforeCreation`, enforced on every case
	 * create. A form that leaves one of them out opens no case at all, so the
	 * binding is refused when the case type is published with it, or saved
	 * while published. A draft case type is still being put together and is
	 * not held to it.
	 *
	 * @param array<string, mixed>                                                         $caseType The case type as it is about to be saved.
	 * @param array{fields: array<int, array<string, mixed>>, fixed: array<string, mixed>} $mapping  The binding's mapping.
	 *
	 * @return array<int, array{property: string, code: string, message: string}> One finding per unfilled property.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function unmetRequirements(array $caseType, array $mapping): array {
		if (($caseType['isDraft'] ?? false) === true) {
			return [];
		}

		$sourced = $this->binding->sourced(mapping: $mapping);
		$findings = [];
		foreach ($this->requirements->declarationFor(caseType: $caseType)['requiredBeforeCreation'] as $property) {
			if (in_array($property, $sourced, true) === true) {
				continue;
			}

			$findings[] = [
				'property' => $property,
				'code' => self::REQUIRED_UNMAPPED,
				'message' => sprintf(
					'This case type asks for "%s" before a case exists, and no answer or fixed value of the intake form fills it.',
					$property
				),
			];
		}

		return $findings;
	}//end unmetRequirements()

	/**
	 * The sentence a refused save answers with.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 *
	 * @return string The sentence.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function sentenceFor(array $findings): string {
		$messages = [];
		foreach ($findings as $finding) {
			$message = trim((string)($finding['message'] ?? ''));
			if ($message === '') {
				$message = trim((string)($finding['property'] ?? '') . ': ' . (string)($finding['code'] ?? ''));
			}

			$messages[] = $message;
		}

		return 'The intake form of this case type would open a case the case schema refuses. '
			. implode(' ', $messages);
	}//end sentenceFor()

	/**
	 * The live case schema, as OpenRegister holds it.
	 *
	 * @return object|null The schema, or null when it cannot be read.
	 */
	private function caseSchema(): ?object {
		$schemaId = $this->settingsService->getConfigValue('case_schema');
		$mapper = $this->settingsService->getOpenRegisterClass(self::SCHEMA_MAPPER);
		if ($schemaId === '' || $mapper === null || method_exists($mapper, 'find') === false) {
			return null;
		}

		$schema = null;
		try {
			$schema = $mapper->find(id: $schemaId, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq intake: could not read the case schema for the intake form check',
				['exception' => $e->getMessage()]
			);
		}

		if (is_object($schema) === false) {
			return null;
		}

		return $schema;
	}//end caseSchema()
}//end class
