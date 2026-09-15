<?php

/**
 * How long this case has been where it is, held on the case.
 *
 * `DwellTimeAnalyzer` already computes dwell per status visit and charts the
 * median, the p90 and the mean. That number reaches a manager and never
 * reaches a handler, because it is not on the case: there is nothing to sort a
 * work list by and nothing to filter on. The same measurement has to be a
 * FIELD for the second use, so it is written here as the status changes and
 * the analyzer reads it back rather than computing a second set.
 *
 * A dwell breach is not a term breach. A case can be nine weeks in one status
 * and comfortably inside a beslistermijn that runs for six months, and that
 * case is exactly the one this change exists to find. So the breach is its own
 * flag with its own filter, and nothing here touches `deadline`, `endDate` or
 * any term state.
 *
 * ⚠️ THE COUNT AND THE BREACH ARE ON TWO CALENDARS, AND THAT IS NAMED RATHER
 * THAN HIDDEN. The BREACH is the engine's: {@see StatusDwellTimer} arms it in
 * the engine's own `businessDays` unit over the calendar the organisation
 * administers, and that is the authority. The COUNT held on the case is
 * dossiq's {@see \OCA\Dossiq\Service\WorkingDayCalculator}, because the engine
 * exposes projection (`SlaCalculator::add`) and no count between two dates. On
 * an organisation whose calendar differs from the Dutch national one the two
 * can disagree by a day. Closing that needs a count operation in openregister
 * `working-calendar-admin`, which is not specified there yet.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Status
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
 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Status;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\WorkingDayCalculator;

/**
 * Writes and reads the dwell numbers the case carries.
 *
 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
 */
class StatusDwellService {

	/**
	 * Constructor.
	 *
	 * @param WorkingDayCalculator $calendar The working-day arithmetic.
	 * @param CaseDateNormaliser   $dates    The one date read path.
	 */
	public function __construct(
		private readonly WorkingDayCalculator $calendar,
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * Close the book on the status the case is leaving and open it on the next.
	 *
	 * Called with the case payload ABOUT TO BE SAVED, in the same save as the
	 * status itself, for the reason the closing result is: two saves is two
	 * chances for one of them not to happen.
	 *
	 * @param array<string, mixed>   $case     The case payload about to be saved.
	 * @param string                 $toStatus The status the case is entering.
	 * @param DateTimeImmutable|null $now      The moment of the move; defaults to now.
	 *
	 * @return array<string, mixed> The payload, with the dwell fields settled.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
	 */
	public function applyStatusChange(array $case, string $toStatus, ?DateTimeImmutable $now = null): array {
		$moment = ($now ?? new DateTimeImmutable());
		$leaving = (string)($case['status'] ?? '');
		$spent = $this->elapsedWorkingDays(case: $case, now: $moment);

		if ($leaving !== '' && $leaving !== $toStatus) {
			$case['statusDwellTotals'] = $this->addTo(
				totals: ($case['statusDwellTotals'] ?? []),
				statusTypeId: $leaving,
				workingDays: $spent,
			);
		}

		$case['currentStatusEnteredAt'] = $moment->format('c');
		$case['currentStatusDwellDays'] = 0;
		$case['statusDwellBreached'] = false;

		return $case;
	}//end applyStatusChange()

	/**
	 * The working days the case has spent in the status it is in now.
	 *
	 * Falls back to the case's `startDate` when nothing has recorded an entry
	 * moment yet, which is every case that predates this change. A case whose
	 * entry moment is unknowable answers 0 rather than a guess: a made-up dwell
	 * sorts a work list wrongly and nobody can tell that it did.
	 *
	 * @param array<string, mixed>   $case The case.
	 * @param DateTimeImmutable|null $now  The moment to count to; defaults to now.
	 *
	 * @return int Working days, never negative.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
	 */
	public function elapsedWorkingDays(array $case, ?DateTimeImmutable $now = null): int {
		$entered = $this->enteredAt(case: $case);
		if ($entered === null) {
			return 0;
		}

		$moment = ($now ?? new DateTimeImmutable());
		if ($moment < $entered) {
			return 0;
		}

		return $this->calendar->countWorkingDays(
			start: $entered->modify('+1 day'),
			end: $moment,
		);
	}//end elapsedWorkingDays()

	/**
	 * Whether the case has been in its status longer than the status allows.
	 *
	 * @param array<string, mixed>   $case       The case.
	 * @param int|null               $maximum    The status's declared maximum, or null for none.
	 * @param DateTimeImmutable|null $now        The moment to judge at; defaults to now.
	 *
	 * @return bool False whenever the status declares no maximum.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function isBreached(array $case, ?int $maximum, ?DateTimeImmutable $now = null): bool {
		if ($maximum === null || $maximum < 1) {
			return false;
		}

		return $this->elapsedWorkingDays(case: $case, now: $now) > $maximum;
	}//end isBreached()

	/**
	 * The numbers a reader wants about the case's current status.
	 *
	 * @param array<string, mixed>   $case    The case.
	 * @param int|null               $maximum The status's declared maximum, or null.
	 * @param DateTimeImmutable|null $now     The moment to read at; defaults to now.
	 *
	 * @return array{days: int, maximum: int|null, breached: bool, enteredAt: string}
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
	 */
	public function snapshot(array $case, ?int $maximum, ?DateTimeImmutable $now = null): array {
		$entered = $this->enteredAt(case: $case);

		return [
			'days' => $this->elapsedWorkingDays(case: $case, now: $now),
			'maximum' => $maximum,
			'breached' => $this->isBreached(case: $case, maximum: $maximum, now: $now),
			'enteredAt' => ($entered === null ? '' : $entered->format('c')),
		];
	}//end snapshot()

	/**
	 * The per-status totals the case carries, as a map.
	 *
	 * The CURRENT status's running time is folded in, so a reader never has to
	 * remember that the stored totals stop at the last transition. That is the
	 * disagreement between the process mining page and the case this change is
	 * closing: both read this method.
	 *
	 * @param array<string, mixed>   $case The case.
	 * @param DateTimeImmutable|null $now  The moment to count to; defaults to now.
	 *
	 * @return array<string, int> Working days, keyed by statusType id.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
	 */
	public function totalsFor(array $case, ?DateTimeImmutable $now = null): array {
		$totals = [];
		$stored = ($case['statusDwellTotals'] ?? []);
		if (is_array($stored) === true) {
			foreach ($stored as $entry) {
				if (is_array($entry) === false) {
					continue;
				}

				$statusTypeId = (string)($entry['statusType'] ?? '');
				if ($statusTypeId === '') {
					continue;
				}

				$totals[$statusTypeId] = (($totals[$statusTypeId] ?? 0) + (int)($entry['workingDays'] ?? 0));
			}
		}

		$current = (string)($case['status'] ?? '');
		if ($current !== '') {
			$totals[$current] = (($totals[$current] ?? 0) + $this->elapsedWorkingDays(case: $case, now: $now));
		}

		return $totals;
	}//end totalsFor()

	/**
	 * When the case entered the status it is in now.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return DateTimeImmutable|null The moment, or null when nothing recorded one.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
	 */
	private function enteredAt(array $case): ?DateTimeImmutable {
		$recorded = $this->dates->tryParse(value: ($case['currentStatusEnteredAt'] ?? null));
		if ($recorded !== null) {
			return $recorded;
		}

		return $this->dates->tryParse(value: ($case['startDate'] ?? null));
	}//end enteredAt()

	/**
	 * Add working days to one status's entry in the totals list.
	 *
	 * @param mixed  $totals       The stored list, in whatever shape it arrived.
	 * @param string $statusTypeId The status the time was spent in.
	 * @param int    $workingDays  The days to add.
	 *
	 * @return array<int, array{statusType: string, workingDays: int}>
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
	 */
	private function addTo(mixed $totals, string $statusTypeId, int $workingDays): array {
		$rebuilt = [];
		$seen = false;

		if (is_array($totals) === true) {
			foreach ($totals as $entry) {
				if (is_array($entry) === false) {
					continue;
				}

				$id = (string)($entry['statusType'] ?? '');
				if ($id === '') {
					continue;
				}

				$days = (int)($entry['workingDays'] ?? 0);
				if ($id === $statusTypeId) {
					$days += $workingDays;
					$seen = true;
				}

				$rebuilt[] = ['statusType' => $id, 'workingDays' => $days];
			}
		}

		if ($seen === false) {
			$rebuilt[] = ['statusType' => $statusTypeId, 'workingDays' => $workingDays];
		}

		return $rebuilt;
	}//end addTo()
}//end class
