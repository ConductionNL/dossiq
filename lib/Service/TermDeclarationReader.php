<?php

/**
 * Dossiq TermDeclarationReader.
 *
 * What a case type and a status type declare about their clocks, read once and
 * handed on as plain integers.
 *
 * The declarations arrive in three shapes. `processingDeadline` and
 * `extensionPeriod` are ISO 8601 durations, because ZGW writes them that way.
 * The properties this change adds are plain day counts, because a person filling
 * in a case-type form should not have to type `P42D`. And a fixed closing date
 * is a date. One reader turns all three into the numbers the services want, so
 * no service parses a duration a second time and gets it slightly different.
 *
 * It is deliberately NOT {@see Transitions\CaseTypeReader}. That one answers
 * the lifecycle question, is injected into the transition engine, and belongs to
 * `status-transition-engine`. Adding seven term properties to it would have
 * coupled the term model to every transition.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * The term declarations on a case type and on its phases.
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */
class TermDeclarationReader {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Every term declaration on one case type.
	 *
	 * A case type that cannot be read declares nothing, and a term that is
	 * declared nowhere is simply not bound. That is not a silent failure: the
	 * statutory term still comes from the TermijnDefinitie, which refuses
	 * loudly when it is missing (REQ-TERM-001-A).
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array{leadTimeDays: int, fixedEndDate: string, plannedLeadTimeDays: int,
	 *               internalTargetDays: int, maxSuspensionDays: int, extensionPeriodDays: int,
	 *               statutoryWarningDays: int, plannedWarningDays: int, chainTermDays: int,
	 *               suspensionAllowed: bool, extensionAllowed: bool}
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function forCaseType(string $caseTypeId): array {
		$row = $this->findRow(schemaKey: 'case_type_schema', id: $caseTypeId);

		return [
			'leadTimeDays' => $this->days(value: ($row['processingDeadline'] ?? null)),
			'fixedEndDate' => $this->date(value: ($row['processingDeadlineDate'] ?? null)),
			'plannedLeadTimeDays' => $this->days(value: ($row['plannedLeadTime'] ?? null)),
			'internalTargetDays' => $this->days(value: ($row['internalTargetDays'] ?? null)),
			'maxSuspensionDays' => $this->days(value: ($row['maxSuspensionDays'] ?? null)),
			'extensionPeriodDays' => $this->days(value: ($row['extensionPeriod'] ?? null)),
			'statutoryWarningDays' => $this->days(value: ($row['statutoryWarningDays'] ?? null)),
			'plannedWarningDays' => $this->days(value: ($row['plannedWarningDays'] ?? null)),
			'chainTermDays' => $this->days(value: ($row['chainTermDays'] ?? null)),
			'suspensionAllowed' => $this->flag(value: ($row['suspensionAllowed'] ?? false)),
			'extensionAllowed' => $this->flag(value: ($row['extensionAllowed'] ?? false)),
		];
	}//end forCaseType()

	/**
	 * The term declarations behind one case.
	 *
	 * A service that moves a deadline holds a term instance, and a term
	 * instance names a case rather than a case type. This walks the one hop so
	 * every such service reads the same declarations from the same place, and
	 * the reading of an ISO duration happens once.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The same shape {@see forCaseType()} answers.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
	 */
	public function forCase(string $caseId): array {
		$context = $this->caseContext(caseId: $caseId);

		return $this->forCaseType(caseTypeId: $context['caseType']);
	}//end forCase()

	/**
	 * The case type and the phase one case is in.
	 *
	 * Both arrive as a uuid on the case, or as an expanded object when the
	 * store inlined the reference. One reader coerces both shapes, so every
	 * caller does not write the same three lines and one of them does not
	 * forget the inlined case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{caseType: string, status: string} The two references, empty when unreadable.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function caseContext(string $caseId): array {
		$case = $this->findRow(schemaKey: 'case_schema', id: $caseId);

		return [
			'caseType' => $this->reference(value: ($case['caseType'] ?? '')),
			'status' => $this->reference(value: ($case['status'] ?? '')),
		];
	}//end caseContext()

	/**
	 * One reference, whether it arrived as a uuid or as an expanded object.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string The uuid, empty when there is none.
	 */
	private function reference(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['@self']['id'] ?? ''));
		}

		return trim((string)$value);
	}//end reference()

	/**
	 * What one phase declares about its own clock.
	 *
	 * @param string $statusTypeId StatusType UUID.
	 *
	 * @return array{termDays: int, share: float, order: int, name: string}
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function forStatusType(string $statusTypeId): array {
		$row = $this->findRow(schemaKey: 'status_type_schema', id: $statusTypeId);

		return $this->phaseOf(row: $row);
	}//end forStatusType()

	/**
	 * The phases of a case type, in the order they run.
	 *
	 * Asked of the CHILD, because `statusType.caseType` is where the relation
	 * lives; a case type carries no list of its statuses.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, array{id: string, termDays: int, share: float, order: int, name: string}>
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function phasesOf(string $caseTypeId): array {
		$rows = $this->statusRowsOf(caseTypeId: $caseTypeId);

		$phases = [];
		foreach ($rows as $row) {
			$phase = $this->phaseOf(row: $row);
			$phase['id'] = (string)($row['id'] ?? ($row['uuid'] ?? ''));
			$phases[] = $phase;
		}//end foreach

		usort(
			$phases,
			static fn (array $a, array $b): int => ($a['order'] <=> $b['order'])
		);

		return $phases;
	}//end phasesOf()

	/**
	 * The status rows of one case type, unsorted.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, array<string, mixed>> The rows, empty when unreadable.
	 */
	private function statusRowsOf(string $caseTypeId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null || $caseTypeId === '') {
			return [];
		}

		$register = (string)$this->settingsService->getConfigValue(key: 'register');
		$schema = (string)$this->settingsService->getConfigValue(key: 'status_type_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['caseType' => $caseTypeId]
			);
		} catch (\Throwable $e) {
			// "This type declares no phases" and "we could not ask" are opposite
			// answers, and the first one silently gives every phase zero days.
			throw new RefusedException(
				rule: 'term-declarations-unreadable',
				sentence: 'The phases of this case type could not be read, so its terms were not resolved.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}//end try
	}//end statusRowsOf()

	/**
	 * One status row as a phase declaration.
	 *
	 * @param array<string, mixed> $row The statusType row.
	 *
	 * @return array{termDays: int, share: float, order: int, name: string}
	 */
	private function phaseOf(array $row): array {
		return [
			'termDays' => $this->days(value: ($row['phaseTermDays'] ?? null)),
			'share' => (float)($row['phaseTermShare'] ?? 0),
			'order' => (int)($row['order'] ?? 0),
			'name' => (string)($row['name'] ?? ''),
		];
	}//end phaseOf()

	/**
	 * A declared length in days, whatever shape it was written in.
	 *
	 * Accepts a plain integer, a numeric string, and the ISO 8601 day, week and
	 * month durations ZGW writes (`P56D`, `P8W`, `P2M`). A month is 30 days
	 * here, which is what `standardDurationDays` already assumes and is the
	 * only reading that keeps one number in one place. Anything else reads as
	 * zero, which means "not declared" everywhere this is used.
	 *
	 * @param mixed $value The raw declaration.
	 *
	 * @return int The length in days, 0 when nothing is declared.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function days(mixed $value): int {
		if (is_int($value) === true) {
			return max(0, $value);
		}

		if (is_float($value) === true) {
			return max(0, (int)$value);
		}

		$text = trim((string)($value ?? ''));
		if ($text === '') {
			return 0;
		}

		if (ctype_digit($text) === true) {
			return (int)$text;
		}

		$matched = preg_match('/^P(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?$/', strtoupper($text), $parts);
		if ($matched !== 1) {
			return 0;
		}

		$months = (int)($parts[1] ?? 0);
		$weeks = (int)($parts[2] ?? 0);
		$days = (int)($parts[3] ?? 0);

		return (($months * 30) + ($weeks * 7) + $days);
	}//end days()

	/**
	 * A declared calendar date, or the empty string.
	 *
	 * @param mixed $value The raw declaration.
	 *
	 * @return string `Y-m-d`, empty when nothing usable is declared.
	 */
	private function date(mixed $value): string {
		$text = trim((string)($value ?? ''));
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text) !== 1) {
			return '';
		}

		return substr($text, 0, 10);
	}//end date()

	/**
	 * Coerce a JSON-shaped boolean.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return bool True only for the values that mean true.
	 */
	private function flag(mixed $value): bool {
		return in_array($value, [true, 1, '1', 'true'], true);
	}//end flag()

	/**
	 * Read one row of a configured schema by id.
	 *
	 * @param string $schemaKey The app-config key naming the schema.
	 * @param string $id The row's UUID.
	 *
	 * @return array<string, mixed> The row, empty when unresolvable.
	 */
	private function findRow(string $schemaKey, string $id): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null || $id === '') {
			return [];
		}

		$register = (string)$this->settingsService->getConfigValue(key: 'register');
		$schema = (string)$this->settingsService->getConfigValue(key: $schemaKey);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$row = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $id
			);
		} catch (\Throwable $e) {
			// A row that is not there comes back as null from the finder, which
			// is a read miss and an ordinary answer. Reaching this catch means
			// the store could not be asked, and answering "declares nothing"
			// to that would let an unreadable case type allow every suspension.
			throw new RefusedException(
				rule: 'term-declarations-unreadable',
				sentence: 'This case type could not be read, so its terms were not resolved.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}//end try

		return ($row ?? []);
	}//end findRow()
}//end class
