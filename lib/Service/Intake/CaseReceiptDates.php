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
 * @spec openspec/specs/burger-notifications/spec.md
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\CaseTypeStore;
use Throwable;

/**
 * The fields a new case is missing for its receipt and start dates.
 *
 * @spec openspec/specs/burger-notifications/spec.md
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
	 * @spec openspec/specs/burger-notifications/spec.md
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
	 * `startDate` is written when the case has none. It is the receipt day,
	 * unless the term starts on a later day than the request came in: then it
	 * is the day the term starts (decision 181, Q11). The case's `deadline` is
	 * `startDate` plus the processing term, so a request sent on Sunday counts
	 * from the Monday its confirmation names, not from the Sunday before it.
	 * The case type's `receiptDate` question is answered with the receipt day
	 * when the type declares it and the case does not answer it; the answer is
	 * appended to the case's own `properties`, the one place a case type's
	 * answers live (friendly-case-create-form REQ-FCF-003).
	 *
	 * @param array<string, mixed> $case         The case, as just created.
	 * @param string               $termStartsAt The moment the case's term starts, or '' when no calendar answered.
	 *
	 * @return array<string, mixed> The fields to write.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function changesFor(array $case, string $termStartsAt = ''): array {
		$day = $this->receiptDayOf(case: $case);
		$changes = [];

		if (trim((string)($case[self::START_DATE] ?? '')) === '') {
			$changes[self::START_DATE] = $this->startDayOf(receiptDay: $day, termStartsAt: $termStartsAt);
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
	 * The start date dossiq gives a case, whatever start date it carries.
	 *
	 * For the save that sends a draft. The start date a draft carries is the
	 * day it was begun, filled in by the schema's own calculation, not a date
	 * anyone gave; the case starts from the moment it was sent.
	 *
	 * @param array<string, mixed> $case         The case, naming its receipt.
	 * @param string               $termStartsAt The moment the case's term starts, or '' when no calendar answered.
	 *
	 * @return string The start day, `Y-m-d`.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function startDateFor(array $case, string $termStartsAt): string {
		return $this->startDayOf(receiptDay: $this->receiptDayOf(case: $case), termStartsAt: $termStartsAt);
	}//end startDateFor()

	/**
	 * The day a case starts on: the receipt day, or the later day its term starts.
	 *
	 * Only a LATER day moves it. A term that starts on the receipt day, or a
	 * term start that does not read as a moment, leaves the receipt day as it
	 * was written, so a day an integration supplied is not sent through a
	 * time zone and back.
	 *
	 * @param string $receiptDay   The receipt day, `Y-m-d`.
	 * @param string $termStartsAt The moment the term starts, or ''.
	 *
	 * @return string The start day, `Y-m-d`.
	 */
	private function startDayOf(string $receiptDay, string $termStartsAt): string {
		if (trim($termStartsAt) === '') {
			return $receiptDay;
		}

		try {
			$termDay = $this->dates->formatCalendarDate(moment: new DateTimeImmutable($termStartsAt));
		} catch (Throwable $e) {
			return $receiptDay;
		}

		if (strcmp($termDay, $receiptDay) > 0) {
			return $termDay;
		}

		return $receiptDay;
	}//end startDayOf()

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
