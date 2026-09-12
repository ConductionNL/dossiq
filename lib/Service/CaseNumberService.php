<?php

/**
 * Dossiq case-number service.
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Gives a case its number when OpenRegister has not.
 *
 * THE NUMBER IS DECLARED, NOT COMPUTED — AND THAT IS WHY THIS EXISTS.
 * `case.identifier` carries an `x-openregister-calculations` entry using the
 * `sequence` operator, so on an OpenRegister that ships that operator this
 * service never writes anything: it reads the case back, finds the number
 * already there, and returns. On an OpenRegister that predates it, the same
 * declaration evaluates to nothing and every case is filed without a number —
 * which is the state the change set out to end, and which no gate can see,
 * because a schema declaration naming an operator the engine does not know
 * fails at evaluation time, in the register, not at import.
 *
 * So this is a BACKFILL, not a second numbering scheme. It fills only an empty
 * `identifier` and it produces exactly the shape the calculation produces,
 * `YYYY-NNNN` off the case's start year. When the two ever run against the
 * same case, the calculation wins by arriving first.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseNumberService {

	use SearchesObjects;

	/**
	 * How wide the sequence half of a case number is.
	 *
	 * Four digits, matching `pad: 4` in the schema's calculation. A year that
	 * runs past 9999 cases keeps counting rather than wrapping — a number that
	 * repeats is worse than a number that is five digits long.
	 */
	private const PAD = 4;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register/schema configuration and the ObjectService.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The year a case's number belongs to.
	 *
	 * The calculation reads `year(startDate)`, so this must too. A case filed
	 * without a start date is numbered in the current year, which is the same
	 * year OpenRegister's own `startDate` calculation would have given it
	 * (`coalesce(startDate, now())`).
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return int The four-digit year.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function yearOf(array $case): int {
		$startDate = trim((string)($case['startDate'] ?? ''));
		if ($startDate !== '') {
			$parsed = date_create($startDate);
			if ($parsed !== false) {
				return (int)$parsed->format('Y');
			}
		}

		return (int)date('Y');
	}//end yearOf()

	/**
	 * The next free number for a year, given the numbers already handed out.
	 *
	 * Deliberately MAX + 1 rather than COUNT + 1. A count is one deletion away
	 * from handing a live case a number another case already holds, and a case
	 * number that repeats is worse than a gap in the run. Only identifiers of
	 * this year's shape are counted, so a case carrying a legacy identifier
	 * (`BZW-2025-17`) neither raises the sequence nor is disturbed by it.
	 *
	 * @param int           $year        The year to number in.
	 * @param array<string> $identifiers Identifiers already in use.
	 *
	 * @return string The number, e.g. `2026-0042`.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function nextNumber(int $year, array $identifiers): string {
		$prefix = (string)$year . '-';
		$highest = 0;

		foreach ($identifiers as $identifier) {
			$identifier = trim((string)$identifier);
			if (str_starts_with($identifier, $prefix) === false) {
				continue;
			}

			$sequence = substr($identifier, strlen($prefix));
			if (ctype_digit($sequence) === false) {
				continue;
			}

			$highest = max($highest, (int)$sequence);
		}

		return $prefix . str_pad((string)($highest + 1), self::PAD, '0', STR_PAD_LEFT);
	}//end nextNumber()

	/**
	 * Give a case its number, unless it already has one.
	 *
	 * @param array<string, mixed> $case The case as OpenRegister just stored it.
	 *
	 * @return string The number the case now holds, or an empty string when
	 *                nothing was written (it already had one, or the register
	 *                could not be read).
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function assign(array $case): string {
		if (trim((string)($case['identifier'] ?? '')) !== '') {
			return '';
		}

		$caseId = trim((string)($case['id'] ?? ($case['uuid'] ?? '')));
		if ($caseId === '') {
			return '';
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return '';
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $schema === '') {
			return '';
		}

		$year = $this->yearOf(case: $case);

		try {
			$number = $this->nextNumber(
				year: $year,
				identifiers: $this->identifiersInUse(
					objectService: $objectService,
					register: $register,
					schema: $schema,
				)
			);

			$case['identifier'] = $number;
			$this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $case,
				uuid: $caseId,
			);
		} catch (Throwable $e) {
			// A case without a number is readable, editable and closable. It is
			// worth a warning and not worth losing the case over, so the
			// failure is loud and the creation stands.
			$this->logger->warning(
				'Dossiq: could not give case ' . $caseId . ' a number: ' . $e->getMessage(),
				['case' => $caseId, 'year' => $year]
			);

			return '';
		}//end try

		return $number;
	}//end assign()

	/**
	 * Every identifier the register currently holds.
	 *
	 * Deliberately UNFILTERED by year. Filtering on a `startDate` range would
	 * be narrower, but a range filter OpenRegister does not honour answers with
	 * the whole table rather than an error — and {@see self::nextNumber()} reads
	 * only the identifiers of the year it was asked about, so a filter that
	 * silently does nothing costs a slower read and still yields the right
	 * number. A filter that silently does the WRONG thing would yield a
	 * duplicate.
	 *
	 * @param object     $objectService The OpenRegister ObjectService.
	 * @param int|string $register      Register id or slug.
	 * @param int|string $schema        Schema id or slug.
	 *
	 * @return array<string> The identifiers in use.
	 */
	private function identifiersInUse(
		object $objectService,
		int|string $register,
		int|string $schema,
	): array {
		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['_limit' => 10000]
		);

		$identifiers = [];
		foreach ($rows as $row) {
			$identifier = trim((string)($row['identifier'] ?? ''));
			if ($identifier !== '') {
				$identifiers[] = $identifier;
			}
		}

		return $identifiers;
	}//end identifiersInUse()
}//end class
