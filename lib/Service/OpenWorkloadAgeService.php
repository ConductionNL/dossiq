<?php

/**
 * Dossiq OpenWorkloadAgeService.
 *
 * How old the work still standing is, per status, answered now.
 *
 * `ProcessMiningController` and `BottleneckDetectionJob` compute durations over
 * CLOSED cases. That is a different number, and a teamleider asking "how old is
 * what we still have" cannot get it from finished work: a queue nobody has
 * touched in four months contributes nothing to a report about cases that ended.
 *
 * 🔴 CLOSED CASES NEVER ENTER THE NUMBER, AND THE FILTER THAT KEEPS THEM OUT IS
 * SERVER-SIDE. `isFinalStatus => 0` is a materialised column on the case, so the
 * store answers with open cases only. Filtering in the reading would have pulled
 * the whole register over and counted a page.
 *
 * There is no store and no nightly job. The number is read live, so a case that
 * changed status a minute ago is counted under its new status.
 *
 * WHY THE GROUPING IS IN THE READING RATHER THAN IN A FACET. OpenRegister's
 * facet handler offers `terms`, `range` and `date_histogram`, and none of the
 * three returns a statistical aggregate, so a mean age per status cannot come
 * back from a facet however the query is written. The part that MUST be the
 * store's, the filter, is the store's. Whoever moves this onto the HTTP
 * aggregations endpoint should know that it spells its filters `filter[x]` and
 * DROPS a bare key, unlike the objects endpoint: the same query written the
 * other way returns the whole register with a confident wrong number.
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
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * The age of the open workload, per status (REQ-TERM-069).
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
 */
class OpenWorkloadAgeService {
	use SearchesObjects;

	/**
	 * How many open cases one read takes at most.
	 *
	 * A cap rather than no cap, because an unbounded read of every open case is
	 * how a report becomes the slowest page in the app. The answer says how
	 * many rows it read, so a gemeente past the cap can see that it is.
	 *
	 * @var int
	 */
	public const MAX_ROWS = 5000;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The age of the open workload, grouped by the status it is sitting in.
	 *
	 * @param DateTimeImmutable|null $now The moment to count from (default now).
	 *
	 * @return array{generatedAt: string, openCases: int, truncated: bool,
	 *               perStatus: array<int, array{status: string, cases: int, averageDays: int,
	 *               medianDays: int, oldestDays: int}>}
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
	 */
	public function report(?DateTimeImmutable $now = null): array {
		$today = ($now ?? new DateTimeImmutable())->setTime(0, 0);
		$rows = $this->openCases();

		$ages = [];
		foreach ($rows as $row) {
			$status = $this->statusOf(row: $row);
			$age = $this->ageOf(row: $row, today: $today);
			if ($age === null) {
				continue;
			}

			$ages[$status][] = $age;
		}//end foreach

		$perStatus = [];
		foreach ($ages as $status => $days) {
			$perStatus[] = [
				'status' => (string)$status,
				'cases' => count($days),
				'averageDays' => (int)round((array_sum($days) / count($days))),
				'medianDays' => $this->median(days: $days),
				'oldestDays' => max($days),
			];
		}//end foreach

		usort(
			$perStatus,
			static fn (array $a, array $b): int => ($b['oldestDays'] <=> $a['oldestDays'])
		);

		return [
			'generatedAt' => $today->format('Y-m-d'),
			'openCases' => count($rows),
			'truncated' => (count($rows) >= self::MAX_ROWS),
			'perStatus' => $perStatus,
		];
	}//end report()

	/**
	 * The open cases, filtered by the store rather than by this reading.
	 *
	 * @return array<int, array<string, mixed>> The rows, empty when unreadable.
	 */
	private function openCases(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = (string)$this->settingsService->getConfigValue(key: 'register');
		$schema = (string)$this->settingsService->getConfigValue(key: 'case_schema');
		if ($register === '' || $schema === '') {
			$this->logger->warning(
				'Dossiq workload age: no register or case schema is configured, so the report is refused rather than run unscoped'
			);

			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['isFinalStatus' => 0, '_limit' => self::MAX_ROWS]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq workload age: the open cases could not be read',
				['error' => $e->getMessage()]
			);

			return [];
		}
	}//end openCases()

	/**
	 * The status a case is sitting in.
	 *
	 * @param array<string, mixed> $row The case row.
	 *
	 * @return string The status reference, `unknown` when the case names none.
	 */
	private function statusOf(array $row): string {
		$status = ($row['status'] ?? '');
		if (is_array($status) === true) {
			$status = ($status['name'] ?? ($status['id'] ?? ($status['@self']['id'] ?? '')));
		}

		$status = trim((string)$status);
		if ($status === '') {
			return 'unknown';
		}

		return $status;
	}//end statusOf()

	/**
	 * How many days a case has been open.
	 *
	 * Counted from the day it started, because that is the day the applicant
	 * began waiting. A case with no readable start is skipped rather than
	 * counted as nought: a zero-day case pulls every average down and says
	 * nothing true.
	 *
	 * @param array<string, mixed> $row The case row.
	 * @param DateTimeImmutable $today The day to count to.
	 *
	 * @return int|null The age in days, or null when the start cannot be read.
	 */
	private function ageOf(array $row, DateTimeImmutable $today): ?int {
		$start = trim((string)($row['startDate'] ?? ($row['registrationDate'] ?? '')));
		if ($start === '') {
			return null;
		}

		try {
			$from = (new DateTimeImmutable($start))->setTime(0, 0);
		} catch (\Throwable $e) {
			return null;
		}

		if ($from > $today) {
			return 0;
		}

		return (int)$from->diff($today)->days;
	}//end ageOf()

	/**
	 * The middle value of a set of ages.
	 *
	 * Beside the mean because one case forgotten for two years moves a mean and
	 * not a median, and a teamleider needs to see both: the mean says how bad
	 * the queue is, the median says whether it is one case or all of them.
	 *
	 * @param array<int, int> $days The ages.
	 *
	 * @return int The median, 0 for an empty set.
	 */
	private function median(array $days): int {
		$count = count($days);
		if ($count === 0) {
			return 0;
		}

		$sorted = $days;
		sort($sorted);
		$middle = (int)floor(($count / 2));

		if (($count % 2) === 1) {
			return (int)$sorted[$middle];
		}

		return (int)round(((($sorted[($middle - 1)] + $sorted[$middle]) / 2)));
	}//end median()
}//end class
