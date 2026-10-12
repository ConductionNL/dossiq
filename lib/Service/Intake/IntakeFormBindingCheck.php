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
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param IntakeFormBinding $binding         Reads the binding as a mapping.
	 * @param LoggerInterface   $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IntakeFormBinding $binding,
		private readonly LoggerInterface $logger,
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

		$found = $validator->validate(mapping: $this->binding->mappingFor(caseType: $caseType), schema: $schema);
		if (is_array($found) === false) {
			return [];
		}

		return array_values(array_filter($found, 'is_array'));
	}//end findingsFor()

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
