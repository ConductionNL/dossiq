<?php

/**
 * How a case type's intake form writes into the case.
 *
 * A case type names one form in `intakeFormRef` and says in
 * `intakeFormMapping` which answer fills which case property. This class turns
 * the two into the mapping OpenRegister's form destination validator and form
 * submit both read: `fields` (one entry per answer) and `fixed` (values every
 * case of this form gets).
 *
 * THE CHECK AND THE SUBMIT READ THE SAME MAPPING. The mapping judged when the
 * case type is saved and the mapping a submission is written with come from
 * {@see mappingFor()}, so a binding that passed the check cannot write
 * something else afterwards.
 *
 * A binding with no declared answers keeps what it did before the mapping
 * existed: the first answer names the case and every answer is listed in the
 * description. Both are fixed values, computed per submission.
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

/**
 * Reads a case type's intake form binding as a form mapping.
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
 */
class IntakeFormBinding {

	/**
	 * The case type property that names the bound form.
	 *
	 * @var string
	 */
	public const FORM_REF = 'intakeFormRef';

	/**
	 * The case type property that holds the declared mapping.
	 *
	 * @var string
	 */
	public const MAPPING = 'intakeFormMapping';

	/**
	 * The intake channel a case opened by a bound form records.
	 *
	 * @var string
	 */
	public const CHANNEL = 'forms';

	/**
	 * The title of a case whose submission names nothing.
	 *
	 * @var string
	 */
	public const UNTITLED = 'Aanvraag via formulier';

	/**
	 * The id standing in for a case type that has none yet, in a check before its first save.
	 *
	 * @var string
	 */
	private const UNSAVED_CASE_TYPE = '00000000-0000-4000-8000-000000000000';

	/**
	 * The form a case type is bound to.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return string The form reference, or the empty string when none is bound.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function formRef(array $caseType): string {
		$ref = ($caseType[self::FORM_REF] ?? '');
		if (is_string($ref) === false) {
			return '';
		}

		return trim($ref);
	}//end formRef()

	/**
	 * The answers the case type declares, as mapping entries.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return array<int, array<string, mixed>> The entries that name both a question and a case property.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function fields(array $caseType): array {
		$declared = ($caseType[self::MAPPING] ?? null);
		if (is_array($declared) === false || is_array($declared['fields'] ?? null) === false) {
			return [];
		}

		$fields = [];
		foreach ($declared['fields'] as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$name = trim((string)($field['field'] ?? ''));
			$property = trim((string)($field['property'] ?? ''));
			if ($name === '' || $property === '') {
				continue;
			}

			$fields[] = array_merge($field, ['field' => $name, 'property' => $property]);
		}

		return $fields;
	}//end fields()

	/**
	 * The mapping a submission of the bound form is written with.
	 *
	 * Without answers it is the mapping as it is judged when the case type is
	 * saved. With answers, a binding that declares no fields gets its title
	 * and description from them.
	 *
	 * @param array<string, mixed>      $caseType The case type.
	 * @param array<string, mixed>|null $answers  The submitted answers, question to value; null for the check.
	 *
	 * @return array{fields: array<int, array<string, mixed>>, fixed: array<string, mixed>} The mapping.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function mappingFor(array $caseType, ?array $answers = null): array {
		$fields = $this->fields(caseType: $caseType);

		$fixed = $this->fixedFor(caseType: $caseType);

		$mapped = array_column($fields, 'property');
		if (in_array('title', $mapped, true) === false && isset($fixed['title']) === false) {
			$fixed['title'] = $this->titleFor(answers: ($answers ?? []));
		}

		$undescribed = (in_array('description', $mapped, true) === false && isset($fixed['description']) === false);
		if ($fields === [] && $undescribed === true && $answers !== null) {
			$fixed['description'] = $this->descriptionFor(answers: $answers);
		}

		return ['fields' => $fields, 'fixed' => $fixed];
	}//end mappingFor()

	/**
	 * The values every case of this form gets: the declared ones, then what the binding decides.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return array<string, mixed> Case property to value.
	 */
	private function fixedFor(array $caseType): array {
		$fixed = [];
		$declared = ($caseType[self::MAPPING] ?? null);
		if (is_array($declared) === true && is_array($declared['fixed'] ?? null) === true) {
			$fixed = $declared['fixed'];
		}

		// What the binding itself decides is written last, so a declared fixed
		// value cannot send the case to another type or channel.
		$fixed['caseType'] = $this->caseTypeId(caseType: $caseType);
		$fixed['intakeChannel'] = self::CHANNEL;

		$initial = trim((string)($caseType['initialStatus'] ?? ''));
		if ($initial !== '') {
			$fixed['status'] = $initial;
		}

		return $fixed;
	}//end fixedFor()

	/**
	 * The case properties a mapping fills.
	 *
	 * @param array{fields: array<int, array<string, mixed>>, fixed: array<string, mixed>} $mapping The mapping.
	 *
	 * @return array<int, string> The property names.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function sourced(array $mapping): array {
		return array_values(
			array_unique(
				array_merge(
					array_map('strval', array_column($mapping['fields'], 'property')),
					array_map('strval', array_keys($mapping['fixed']))
				)
			)
		);
	}//end sourced()

	/**
	 * The case type's id, or a stand-in before its first save.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return string The id.
	 */
	private function caseTypeId(array $caseType): string {
		$self = ($caseType['@self'] ?? []);
		$candidates = [($caseType['id'] ?? ''), ($caseType['uuid'] ?? '')];
		if (is_array($self) === true) {
			$candidates[] = ($self['id'] ?? '');
			$candidates[] = ($self['uuid'] ?? '');
		}

		foreach ($candidates as $candidate) {
			if (is_scalar($candidate) === true && trim((string)$candidate) !== '') {
				return trim((string)$candidate);
			}
		}

		return self::UNSAVED_CASE_TYPE;
	}//end caseTypeId()

	/**
	 * What the case is called: the first answer that says something.
	 *
	 * @param array<string, mixed> $answers The submitted answers.
	 *
	 * @return string The title, within the schema's 255 characters.
	 */
	private function titleFor(array $answers): string {
		foreach ($answers as $value) {
			if (is_string($value) === false) {
				continue;
			}

			$value = trim($value);
			if ($value !== '') {
				return mb_substr($value, 0, 255);
			}
		}

		return self::UNTITLED;
	}//end titleFor()

	/**
	 * Every answer, question first.
	 *
	 * @param array<string, mixed> $answers The submitted answers.
	 *
	 * @return string The description.
	 */
	private function descriptionFor(array $answers): string {
		$lines = [];
		foreach ($answers as $question => $value) {
			if (is_array($value) === true) {
				$value = implode(', ', array_map('strval', $value));
			}

			$lines[] = sprintf('%s: %s', (string)$question, trim((string)$value));
		}

		return implode("\n", $lines);
	}//end descriptionFor()
}//end class
