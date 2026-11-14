<?php

/**
 * The case a DSO intake record becomes.
 *
 * Builds the case payload for DsoCaseService in the shape the case schema
 * accepts: `caseType` as the uuid of the case type the activity mapping
 * names, `status` as that type's initial status type, and `dsoStatus` as
 * `submitted`. It reads integriq's `dso_verzoek` after mapping and the legacy
 * vergunningaanvraag shape.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/vth-dso-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Dso;

use DateTimeInterface;
use OCA\Dossiq\Service\CaseType\CaseTypeReferenceResolver;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use RuntimeException;

/**
 * An intake record, to the case payload.
 *
 * @spec openspec/specs/vth-dso-integration/spec.md
 */
class DsoIntakeCasePayload {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeReferenceResolver $caseTypes The mapped case type reference, to its row
	 * @param CaseJournal               $journal   The case's activity record
	 */
	public function __construct(
		private readonly CaseTypeReferenceResolver $caseTypes,
		private readonly CaseJournal $journal,
	) {
	}//end __construct()

	/**
	 * The case an intake record becomes, in the shape the case schema accepts.
	 *
	 * Everything but the statutory deadline, which DsoCaseService computes
	 * from `startDate` and `procedureType` on the working-day calendar.
	 *
	 * @param string               $permitApplicationId The record's UUID
	 * @param array<string, mixed> $permitApplication   The record
	 *
	 * @return array<string, mixed> The case payload
	 *
	 * @throws \RuntimeException When the record names no case type that resolves
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function payloadFor(string $permitApplicationId, array $permitApplication): array {
		$caseType = $this->resolvedCaseType(permitApplicationId: $permitApplicationId, permitApplication: $permitApplication);
		$procedureType = $this->determineProcedureType(activiteiten: $this->activitiesOf(permitApplication: $permitApplication));
		$submitted = (string)($permitApplication['submissionDate'] ?? ($permitApplication['indieningsdatum'] ?? ''));
		$name = $this->nameOf(permitApplicationId: $permitApplicationId, permitApplication: $permitApplication);

		$case = [
			'title' => mb_substr('Omgevingsvergunning: ' . $name, 0, 255),
			'caseType' => $this->caseTypes->idOf(caseType: $caseType),
			'dsoStatus' => 'submitted',
			'procedureType' => $procedureType,
			'permitApplicationRef' => $permitApplicationId,
			'startDate' => $this->startOf(submitted: $submitted),
		]
			+ $this->receivedAtField(submitted: $submitted)
			+ $this->descriptionField(permitApplication: $permitApplication)
			+ $this->statusField(caseType: $caseType);

		return $this->journal->append(
			case: $case,
			entry: ['type' => 'dsoIntake', 'note' => 'Zaak aangemaakt vanuit DSO vergunningaanvraag ' . $permitApplicationId . '.']
		);
	}//end payloadFor()

	/**
	 * The case type the record's activity mapping resolves to.
	 *
	 * @param string               $permitApplicationId The record's UUID
	 * @param array<string, mixed> $permitApplication   The record
	 *
	 * @return array<string, mixed> The case type row
	 *
	 * @throws \RuntimeException When the record names no case type that resolves
	 */
	private function resolvedCaseType(string $permitApplicationId, array $permitApplication): array {
		$caseType = $this->caseTypeFor(permitApplication: $permitApplication);
		if ($caseType === []) {
			throw new RuntimeException(
				'No dossiq case type answers to the activity mapping of vergunningaanvraag ' . $permitApplicationId
				. ' (mappedCaseTypes: ' . implode(', ', $this->caseTypeReferences(permitApplication: $permitApplication)) . ')'
			);
		}

		return $caseType;
	}//end resolvedCaseType()

	/**
	 * The record's activities, or those of the raw request it came with.
	 *
	 * @param array<string, mixed> $permitApplication The record
	 *
	 * @return array<int|string, mixed>
	 */
	private function activitiesOf(array $permitApplication): array {
		$activiteiten = $this->listAt(record: $permitApplication, key: 'activiteiten');
		if ($activiteiten === []) {
			return $this->listAt(record: $this->listAt(record: $permitApplication, key: 'rawRequest'), key: 'activiteiten');
		}

		return $activiteiten;
	}//end activitiesOf()

	/**
	 * The day the case starts: the day the application was submitted, today
	 * when the record carries no readable one.
	 *
	 * @param string $submitted The submission moment as the record holds it
	 *
	 * @return string `Y-m-d`
	 */
	private function startOf(string $submitted): string {
		$submissionDate = substr($submitted, 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $submissionDate) !== 1) {
			return date('Y-m-d');
		}

		return $submissionDate;
	}//end startOf()

	/**
	 * The name the case is titled after: the mapped title, the record's own,
	 * or its UUID.
	 *
	 * @param string               $permitApplicationId The record's UUID
	 * @param array<string, mixed> $permitApplication   The record
	 *
	 * @return string
	 */
	private function nameOf(string $permitApplicationId, array $permitApplication): string {
		$name = trim((string)($permitApplication['mappedTitle'] ?? ''));
		if ($name === '') {
			$name = trim((string)($permitApplication['title'] ?? ''));
		}

		if ($name === '') {
			return $permitApplicationId;
		}

		return $name;
	}//end nameOf()

	/**
	 * The description, when the activity mapping wrote a summary.
	 *
	 * @param array<string, mixed> $permitApplication The record
	 *
	 * @return array<string, string>
	 */
	private function descriptionField(array $permitApplication): array {
		$summary = trim((string)($permitApplication['mappedSummary'] ?? ''));
		if ($summary === '') {
			return [];
		}

		return ['description' => $summary];
	}//end descriptionField()

	/**
	 * The case type's initial status, when it declares one.
	 *
	 * @param array<string, mixed> $caseType The case type row
	 *
	 * @return array<string, string>
	 */
	private function statusField(array $caseType): array {
		$initial = $this->caseTypes->initialStatusOf(caseType: $caseType);
		if ($initial === '') {
			return [];
		}

		return ['status' => $initial];
	}//end statusField()

	/**
	 * The first case type the record's activity mapping names that resolves.
	 *
	 * @param array<string, mixed> $permitApplication The record
	 *
	 * @return array<string, mixed> The case type row, or an empty array
	 */
	private function caseTypeFor(array $permitApplication): array {
		foreach ($this->caseTypeReferences(permitApplication: $permitApplication) as $reference) {
			$caseType = $this->caseTypes->resolve(reference: $reference);
			if ($caseType !== []) {
				return $caseType;
			}
		}

		return [];
	}//end caseTypeFor()

	/**
	 * The case type references a record carries.
	 *
	 * The activity mapping in integriq writes `mappedCaseTypes` (a ZGW zaaktype URL
	 * or a catalogue identifier per entry). A legacy record may name one in
	 * `caseType`.
	 *
	 * @param array<string, mixed> $permitApplication The record
	 *
	 * @return list<string> The references, in the order the record gives them
	 */
	private function caseTypeReferences(array $permitApplication): array {
		$references = [];
		foreach ($this->listAt(record: $permitApplication, key: 'mappedCaseTypes') as $reference) {
			if (is_array($reference) === true) {
				$reference = ($reference['reference'] ?? '');
			}

			if (is_string($reference) === true && trim($reference) !== '') {
				$references[] = trim($reference);
			}
		}

		$legacy = $permitApplication['caseType'] ?? '';
		if (is_string($legacy) === true && trim($legacy) !== '') {
			$references[] = trim($legacy);
		}

		return $references;
	}//end caseTypeReferences()

	/**
	 * The array a record holds under a key, or an empty array.
	 *
	 * @param array<string, mixed> $record The record
	 * @param string               $key    The key
	 *
	 * @return array<array-key, mixed> The value when it is an array
	 */
	private function listAt(array $record, string $key): array {
		$value = ($record[$key] ?? []);
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end listAt()

	/**
	 * Determine the procedure type from the activiteiten list.
	 *
	 * Returns 'uitgebreide' when any activiteit has regelkwalificatie set to
	 * 'uitgebreide' or when there are more than 3 activiteiten; 'reguliere'
	 * otherwise.
	 *
	 * @param array<int,mixed> $activiteiten The activiteiten array
	 *
	 * @return string 'reguliere' or 'uitgebreide'
	 */
	private function determineProcedureType(array $activiteiten): string {
		if (count($activiteiten) > 3) {
			return 'uitgebreide';
		}

		foreach ($activiteiten as $activity) {
			if (is_array($activity) === false) {
				continue;
			}

			$kwalificatie = (string)($activity['regelkwalificatie'] ?? '');
			if ($kwalificatie === 'uitgebreide') {
				return 'uitgebreide';
			}
		}

		return 'reguliere';
	}//end determineProcedureType()

	/**
	 * The `receivedAt` field the submission gives its case, or none.
	 *
	 * The submission moment, when the record carries one with a time, is when
	 * the request was received: the term starts there, not at the moment the
	 * case row is written (REQ-TERM-043). A bare date says only the day, and
	 * the start date already carries that.
	 *
	 * @param string $submitted The record's `submissionDate` or `indieningsdatum`.
	 *
	 * @return array<string, string> `['receivedAt' => moment]`, or [].
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-an-intake-channel-passes-on-when-the-request-arrived-req-term-043
	 */
	private function receivedAtField(string $submitted): array {
		$submitted = trim($submitted);
		if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $submitted) !== 1) {
			return [];
		}

		// A value date_create() cannot read answers false, where the
		// constructor would throw: an unreadable stamp claims no moment.
		$moment = date_create_immutable($submitted);
		if ($moment === false) {
			return [];
		}

		return ['receivedAt' => $moment->format(DateTimeInterface::ATOM)];
	}//end receivedAtField()
}//end class
