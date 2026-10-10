<?php

/**
 * The receipt date and the start date a new case is given by dossiq.
 *
 * Ruben's decision of 10 October 2026: the create form does not ask when a
 * request came in. dossiq knows: the term engine reads the receipt from the
 * case (`IntakeTermStart::arrivalOf()`) and starts the statutory term from it.
 * Asking a handler to type the same fact again gave two answers to one
 * question, and the term used neither of the typed ones.
 *
 * 🔴 WHAT IS ALREADY ON THE CASE IS KEPT. An integration that imports a case
 * with its real receipt date, or a channel that knows the day the message
 * arrived, has said something dossiq cannot know better. Each field is
 * written only when the case does not carry it.
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
 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\CaseTypeStore;

/**
 * The fields a new case is missing for its receipt and start dates.
 *
 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md
 */
class CaseReceiptDates {

	/**
	 * The case's start date.
	 *
	 * @var string
	 */
	public const START_DATE = 'startDate';

	/**
	 * Constructor.
	 *
	 * @param IntakeTermStart    $intake    Reads the receipt the case names.
	 * @param CaseTypeStore      $caseTypes Reads the case type's questions.
	 * @param CaseDateNormaliser $dates     Writes a day in the administered zone.
	 *
	 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md
	 */
	public function __construct(
		private readonly IntakeTermStart $intake,
		private readonly CaseTypeStore $caseTypes,
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * The fields to write onto a new case, or [] when it carries them all.
	 *
	 * `startDate` is the receipt day when the case has none. The case type's
	 * `receiptDate` question is answered with the receipt day when the type
	 * declares it and the case does not answer it; the answer is appended to
	 * the case's own `properties`, the one place a case type's answers live
	 * (friendly-case-create-form REQ-FCF-003).
	 *
	 * @param array<string, mixed> $case The case, as just created.
	 *
	 * @return array<string, mixed> The fields to write.
	 *
	 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 */
	public function changesFor(array $case): array {
		$day = $this->receiptDayOf(case: $case);
		$changes = [];

		if (trim((string)($case[self::START_DATE] ?? '')) === '') {
			$changes[self::START_DATE] = $day;
		}

		if ($this->intake->receiptAnswerOf(case: $case) !== '') {
			return $changes;
		}

		$definition = $this->receiptDefinitionOf(caseTypeId: $this->caseTypes->referenceId(value: ($case['caseType'] ?? '')));
		if ($definition === '') {
			return $changes;
		}

		$properties = $this->propertiesOf(case: $case);
		$properties[] = [
			'propertyDefinition' => $definition,
			'name' => IntakeTermStart::RECEIPT_DATE,
			'value' => $day,
		];
		$changes['properties'] = $properties;

		return $changes;
	}//end changesFor()

	/**
	 * The day the request was received, `Y-m-d`.
	 *
	 * An answer that already names a day is that day, as written: reading it
	 * as midnight and writing it back through a time zone could move it.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string The day.
	 */
	private function receiptDayOf(array $case): string {
		$answer = $this->intake->receiptAnswerOf(case: $case);
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $answer) === 1 && trim((string)($case[IntakeTermStart::RECEIVED_AT] ?? '')) === '') {
			return substr($answer, 0, 10);
		}

		$arrival = $this->intake->arrivalOf(case: $case);

		return $this->dates->formatCalendarDate(moment: $arrival);
	}//end receiptDayOf()

	/**
	 * The id of the case type's `receiptDate` question, or ''.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return string The definition id, or '' when the type asks no such question.
	 */
	private function receiptDefinitionOf(string $caseTypeId): string {
		if ($caseTypeId === '') {
			return '';
		}

		foreach ($this->caseTypes->rowsOfType(schemaKey: 'property_definition_schema', caseTypeId: $caseTypeId) as $row) {
			if (trim((string)($row['name'] ?? '')) === IntakeTermStart::RECEIPT_DATE) {
				return $this->caseTypes->rowId(row: $row);
			}
		}

		return '';
	}//end receiptDefinitionOf()

	/**
	 * The case's answers as a list, whatever shape they arrived in.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, mixed> The answers.
	 */
	private function propertiesOf(array $case): array {
		$properties = ($case['properties'] ?? []);
		if (is_string($properties) === true) {
			$properties = json_decode($properties, true);
		}

		if (is_array($properties) === false) {
			return [];
		}

		return array_values($properties);
	}//end propertiesOf()
}//end class
