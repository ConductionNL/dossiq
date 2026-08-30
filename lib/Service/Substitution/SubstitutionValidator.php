<?php

/**
 * Dossiq SubstitutionValidator.
 *
 * Input validation and conflict detection for vervanging/waarneming
 * (handler absence/substitution) records. Split out of SubstitutionService so
 * that service keeps only the create/resolve orchestration: the rules for what
 * makes a substitution acceptable — identity, enum membership, a well-formed
 * closed period, scope refs, and the "no two overlapping full-scope
 * substitutions for one absentee" invariant — live here and nowhere else.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Substitution
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
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Substitution;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * Validates substitution input and rejects conflicting full-scope overlaps.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class SubstitutionValidator {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings/config + ObjectService bridge.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Validate every create() argument and resolve the substitution period.
	 *
	 * @param string $absentee Handler being covered (user id), pre-trimmed.
	 * @param string $substitute Waarnemer (user id), pre-trimmed.
	 * @param string $startDate Inclusive start (Y-m-d).
	 * @param string $endDate Inclusive end (Y-m-d), required.
	 * @param string $scope One of all|caseTypes|cases.
	 * @param array<int, string> $scopeRefs caseType/case UUIDs when narrowed.
	 * @param string $reason One of verlof|ziekte|anders.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} The resolved [start, end] period.
	 *
	 * @throws InvalidArgumentException On any validation failure.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function validateCreate(
		string $absentee,
		string $substitute,
		string $startDate,
		string $endDate,
		string $scope,
		array $scopeRefs,
		string $reason,
	): array {
		$this->assertIdentities(absentee: $absentee, substitute: $substitute);
		$this->assertEnums(scope: $scope, reason: $reason);

		$period = $this->resolvePeriod(startDate: $startDate, endDate: $endDate);

		$this->assertScopeRefs(scope: $scope, scopeRefs: $scopeRefs);

		return $period;
	}//end validateCreate()

	/**
	 * Reject a missing or self-referential handler pair.
	 *
	 * @param string $absentee Handler being covered (user id).
	 * @param string $substitute Waarnemer (user id).
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When either id is empty or they are equal.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	private function assertIdentities(string $absentee, string $substitute): void {
		if ($absentee === '' || $substitute === '') {
			throw new InvalidArgumentException('Both absentee and substitute are required');
		}

		if ($absentee === $substitute) {
			throw new InvalidArgumentException('A handler cannot be their own waarnemer (self-substitution is not allowed)');
		}
	}//end assertIdentities()

	/**
	 * Reject an unknown scope or reason.
	 *
	 * @param string $scope One of all|caseTypes|cases.
	 * @param string $reason One of verlof|ziekte|anders.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When either value is outside its enum.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	private function assertEnums(string $scope, string $reason): void {
		if (in_array($scope, ['all', 'caseTypes', 'cases'], true) === false) {
			throw new InvalidArgumentException('Invalid scope; expected all, caseTypes or cases');
		}

		if (in_array($reason, ['verlof', 'ziekte', 'anders'], true) === false) {
			throw new InvalidArgumentException('Invalid reason; expected verlof, ziekte or anders');
		}
	}//end assertEnums()

	/**
	 * Parse and order the substitution period. Open-ended absences are rejected.
	 *
	 * @param string $startDate Inclusive start (Y-m-d).
	 * @param string $endDate Inclusive end (Y-m-d).
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} The resolved [start, end] period.
	 *
	 * @throws InvalidArgumentException When either date is unparseable or the range is inverted.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	private function resolvePeriod(string $startDate, string $endDate): array {
		$start = $this->parseDate(value: $startDate);
		$end = $this->parseDate(value: $endDate);
		if ($start === null) {
			throw new InvalidArgumentException('A valid startDate (YYYY-MM-DD) is required');
		}

		if ($end === null) {
			throw new InvalidArgumentException(
				'A valid endDate (YYYY-MM-DD) is required; open-ended absences must be re-issued or converted to a bulk reassignment'
			);
		}

		if ($end < $start) {
			throw new InvalidArgumentException('endDate must not be before startDate');
		}

		return [$start, $end];
	}//end resolvePeriod()

	/**
	 * Require scope refs whenever the scope is narrowed.
	 *
	 * @param string $scope One of all|caseTypes|cases.
	 * @param array<int, string> $scopeRefs caseType/case UUIDs when narrowed.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a narrowed scope carries no refs.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	private function assertScopeRefs(string $scope, array $scopeRefs): void {
		if (($scope === 'caseTypes' || $scope === 'cases') && count($scopeRefs) === 0) {
			throw new InvalidArgumentException('scopeRefs is required when scope is caseTypes or cases');
		}
	}//end assertScopeRefs()

	/**
	 * Reject a new substitution that overlaps an existing active full-scope one.
	 *
	 * Only `all`+`all` overlaps for the same absentee and period are rejected;
	 * disjoint scopes are allowed to coexist.
	 *
	 * @param string $absentee The covered handler.
	 * @param string $scope The new substitution scope.
	 * @param DateTimeImmutable $start New start.
	 * @param DateTimeImmutable $end New end.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a conflicting full-scope substitution exists.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function assertNoOverlappingFullScope(string $absentee, string $scope, DateTimeImmutable $start, DateTimeImmutable $end): void {
		if ($scope !== 'all') {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('substitution_schema');
		if ($objectService === null) {
			return;
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['absentee' => $absentee]
		);

		foreach ($rows as $row) {
			if ($this->rowOverlapsFullScope(row: $row, start: $start, end: $end) === false) {
				continue;
			}

			$conflictId = (string)($row['id'] ?? ($row['uuid'] ?? '?'));
			throw new InvalidArgumentException(
				'An active full-scope substitution already covers this handler for an '
				. 'overlapping period (conflicting substitution: ' . $conflictId . ')'
			);
		}
	}//end assertNoOverlappingFullScope()

	/**
	 * Whether one existing row is an active full-scope substitution whose period
	 * overlaps the candidate period.
	 *
	 * @param array<string, mixed> $row An existing substitution row.
	 * @param DateTimeImmutable $start Candidate start.
	 * @param DateTimeImmutable $end Candidate end.
	 *
	 * @return bool True when the row conflicts with the candidate period.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	private function rowOverlapsFullScope(array $row, DateTimeImmutable $start, DateTimeImmutable $end): bool {
		if ((string)($row['status'] ?? '') !== 'active') {
			return false;
		}

		if ((string)($row['scope'] ?? '') !== 'all') {
			return false;
		}

		$existingStart = $this->parseDate(value: (string)($row['startDate'] ?? ''));
		$existingEnd = $this->parseDate(value: (string)($row['endDate'] ?? ''));
		if ($existingStart === null || $existingEnd === null) {
			return false;
		}

		// Overlap when neither range is entirely before the other.
		return ($start <= $existingEnd && $existingStart <= $end);
	}//end rowOverlapsFullScope()

	/**
	 * Parse a YYYY-MM-DD date string into an immutable date (midnight).
	 *
	 * @param string $value The date string.
	 *
	 * @return DateTimeImmutable|null The parsed date, or null when unparseable.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function parseDate(string $value): ?DateTimeImmutable {
		// An empty/garbage value simply fails the pattern, so no separate
		// empty check is needed. checkdate() then rejects out-of-range
		// components, which is what makes the constructor call below safe
		// (it can no longer throw) — a plain `new` keeps this free of a
		// static factory call.
		if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', trim($value), $parts) !== 1) {
			return null;
		}

		if (checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false) {
			return null;
		}

		return new DateTimeImmutable($parts[0] . ' 00:00:00');
	}//end parseDate()
}//end class
