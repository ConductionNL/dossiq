<?php

/**
 * Dossiq intake requirements declaration.
 *
 * Reads the `intakeRequirements` declaration off a case type and answers the
 * one question intake asks: may this case exist yet. The declaration carries
 * two lists, and the split is the whole point. A field a handler cannot know
 * at the counter is required before the case is COMPLETE, and the case is made
 * anyway. A field the case type says must be answered before the case EXISTS
 * refuses creation, because a case nobody can reach is worse than a case that
 * was never made.
 *
 * It is a declaration reader and nothing else. It holds no OpenRegister handle
 * and makes no write, so "may this case exist" is testable without a store.
 * The enforcement on the write lives in
 * {@see \OCA\Dossiq\Listener\IntakeRequirementsListener}.
 *
 * WHY A DECLARATION AND NOT `case.required`. The channel, the communication
 * channel and the confidentiality already exist on the case schema. Adding
 * their names to `case.required` would make EVERY case type demand them,
 * including the internal ones a gemeente uses for its own work, where a
 * communication channel means nothing. Dimpact requires both at creation and
 * the clause says why it matters later: both are what the Woo and the
 * Archiefwet ask about afterwards. So the case type declares its own list.
 *
 * 🔴 THE DEFAULT IS IN THE SCHEMA, NOT IN THIS READER, AND THAT IS THE WHOLE
 * DIFFERENCE BETWEEN A NEW CASE TYPE AND AN EXISTING ONE. The spec asks for the
 * channel and the confidentiality to be required "by default for a NEW case
 * type", and `caseType.intakeRequirements.requiredBeforeCreation` carries that
 * default in the register fragment, so a case type made after this change
 * carries the two. Reading a case type that stored nothing as though it had
 * stored the two would instead make every case type on every existing instance
 * demand them the minute this shipped, and every existing creation path writes
 * neither: the start-case widget, the DSO intake, the quick actions, the mail
 * intake and the demo seed would all have started refusing. An upgrade that
 * stops a gemeente opening cases is a worse defect than the one this prevents.
 * So an absent declaration means the case type has declared nothing, and an
 * administrator moves an existing case type onto the list deliberately.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Exception\RefusedException;

/**
 * What a case type says must be answered, and when.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */
class IntakeRequirements {

	/**
	 * The case-type property holding the declaration.
	 */
	public const PROPERTY = 'intakeRequirements';

	/**
	 * The list a NEW case type is created with.
	 *
	 * The same two names the register fragment carries as the schema default
	 * for `intakeRequirements.requiredBeforeCreation`, kept here so a caller
	 * that builds a case type in PHP writes the same list the form does. This
	 * is NOT what an absent declaration is read as: see the class docblock.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_FOR_A_NEW_CASE_TYPE = ['communicationChannel', 'confidentiality'];

	/**
	 * The rule a creation refused for a missing field names.
	 */
	public const RULE_MISSING_FIELD = 'intake-requirement-not-answered';

	/**
	 * The declaration this case type carries, with the default filled in.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array{requiredBeforeCreation: array<int, string>, requiredBeforeComplete: array<int, string>}
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function declarationFor(array $caseType): array {
		$declared = ($caseType[self::PROPERTY] ?? null);
		if (is_array($declared) === false) {
			$declared = [];
		}

		$beforeCreation = [];
		if (array_key_exists('requiredBeforeCreation', $declared) === true
			&& is_array($declared['requiredBeforeCreation']) === true
		) {
			$beforeCreation = $declared['requiredBeforeCreation'];
		}

		$beforeComplete = [];
		if (array_key_exists('requiredBeforeComplete', $declared) === true
			&& is_array($declared['requiredBeforeComplete']) === true
		) {
			$beforeComplete = $declared['requiredBeforeComplete'];
		}

		return [
			'requiredBeforeCreation' => $this->fieldList(value: $beforeCreation),
			'requiredBeforeComplete' => $this->fieldList(value: $beforeComplete),
		];
	}//end declarationFor()

	/**
	 * The fields on the before-creation list this case has not answered.
	 *
	 * @param array<string, mixed> $case     The case as it would be written.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, string> The unanswered field names, in declared order.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function missingBeforeCreation(array $case, array $caseType): array {
		$declaration = $this->declarationFor(caseType: $caseType);

		return $this->unanswered(case: $case, fields: $declaration['requiredBeforeCreation']);
	}//end missingBeforeCreation()

	/**
	 * The fields on the before-complete list this case has not answered.
	 *
	 * @param array<string, mixed> $case     The case.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, string> The unanswered field names, in declared order.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function missingBeforeComplete(array $case, array $caseType): array {
		$declaration = $this->declarationFor(caseType: $caseType);

		return $this->unanswered(case: $case, fields: $declaration['requiredBeforeComplete']);
	}//end missingBeforeComplete()

	/**
	 * Whether this case has answered everything it needs for completeness.
	 *
	 * A case missing a before-complete field exists and reads incomplete. That
	 * is the phone intake `lifecycle-acts-on-the-case` already describes, and
	 * it is deliberately not a refusal.
	 *
	 * @param array<string, mixed> $case     The case.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return boolean True when nothing on the before-complete list is missing.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function isComplete(array $case, array $caseType): bool {
		return ($this->missingBeforeComplete(case: $case, caseType: $caseType) === []);
	}//end isComplete()

	/**
	 * Refuse this creation when a before-creation field is unanswered.
	 *
	 * The refusal names the FIELD, not the count. "Two fields are missing" is
	 * a sentence nobody can act on; "the communication channel is missing" is
	 * one a handler fixes in the form in front of them.
	 *
	 * @param array<string, mixed> $case     The case as it would be written.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return void
	 *
	 * @throws RefusedException When a before-creation field is unanswered.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function assertCreatable(array $case, array $caseType): void {
		$missing = $this->missingBeforeCreation(case: $case, caseType: $caseType);
		if ($missing === []) {
			return;
		}

		throw new RefusedException(
			rule: self::RULE_MISSING_FIELD,
			sentence: $this->sentenceFor(missing: $missing),
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end assertCreatable()

	/**
	 * The sentence a refusal for unanswered fields carries.
	 *
	 * @param array<int, string> $missing The unanswered field names.
	 *
	 * @return string One sentence naming the fields.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function sentenceFor(array $missing): string {
		if ($missing === []) {
			return 'Every field this case type asks for before the case exists has been answered.';
		}

		return 'This case type asks for ' . implode(', ', $missing)
			. ' before the case exists, and the case does not carry it yet.';
	}//end sentenceFor()

	/**
	 * The named fields that carry no value on this case.
	 *
	 * An empty string, an empty array and a missing key are all unanswered.
	 * A `false` and a `0` are answers, which is why this is not `empty()`.
	 *
	 * @param array<string, mixed> $case   The case.
	 * @param array<int, string>   $fields The field names to check.
	 *
	 * @return array<int, string> The unanswered names, in the order given.
	 */
	private function unanswered(array $case, array $fields): array {
		$missing = [];
		foreach ($fields as $field) {
			if ($this->isAnswered(value: ($case[$field] ?? null)) === false) {
				$missing[] = $field;
			}
		}

		return $missing;
	}//end unanswered()

	/**
	 * Whether one value counts as an answer.
	 *
	 * @param mixed $value The value on the case.
	 *
	 * @return boolean True when the field carries an answer.
	 */
	private function isAnswered(mixed $value): bool {
		if ($value === null) {
			return false;
		}

		if (is_string($value) === true) {
			return (trim($value) !== '');
		}

		if (is_array($value) === true) {
			return ($value !== []);
		}

		return true;
	}//end isAnswered()

	/**
	 * One declared list, cleaned of blanks and duplicates.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return array<int, string> The field names.
	 */
	private function fieldList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$fields = [];
		foreach ($value as $entry) {
			if (is_string($entry) === false) {
				continue;
			}

			$name = trim($entry);
			if ($name === '' || in_array($name, $fields, true) === true) {
				continue;
			}

			$fields[] = $name;
		}

		return $fields;
	}//end fieldList()
}//end class
