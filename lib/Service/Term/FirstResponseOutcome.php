<?php

/**
 * Whether the first response was on time, and by how much it was not.
 *
 * "We answer within five working days" is the promise every service desk
 * makes, and dossiq kept it for complaints alone: `ComplaintService` computed
 * an acknowledgement deadline from a private constant, no other case type had
 * one, and nothing measured whether it was met.
 *
 * 🔴 THE OVERRUN IS A NUMBER, NOT A FLAG. Late and on time is a pass rate, and
 * a service manager improving a process needs to know whether we miss by a day
 * or by three weeks: those are different problems with different fixes. So the
 * size is stored and the rate is derived from it, rather than the other way
 * round.
 *
 * A met term is recorded too. Storing only the misses makes the denominator a
 * second query with different filters, and the two then disagree.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Term
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
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use OCA\Dossiq\Service\WorkingDayCalculator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records and reports the first-response term.
 *
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#requirement-a-case-type-declares-a-first-response-term-and-the-overrun-is-stored-req-tcf-01
 */
class FirstResponseOutcome {
	use SearchesObjects;

	/**
	 * The three values the case can carry.
	 */
	public const PENDING = 'pending';

	/**
	 * The term was met.
	 */
	public const MET = 'met';

	/**
	 * The term was missed, and the overrun says by how much.
	 */
	public const MISSED = 'missed';

	/**
	 * Constructor.
	 *
	 * @param SettingsService      $settingsService Register and schema ids, and the object service.
	 * @param WorkingDayCalculator $workingDays     Counts the overrun in the term's own mode.
	 * @param LoggerInterface      $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WorkingDayCalculator $workingDays,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record the outcome of a case's first response.
	 *
	 * @param string            $caseId   The case.
	 * @param DateTimeImmutable $due      When the first response was promised.
	 * @param DateTimeImmutable $sent     When it actually went out.
	 * @param string            $countingMode Whether the term counts working or calendar days,
	 *                                        as {@see WorkingDayRoll::MODE_WORKING_DAYS}.
	 *
	 * @return array{status: string, overrunDays: int} What was recorded.
	 *
	 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#requirement-a-case-type-declares-a-first-response-term-and-the-overrun-is-stored-req-tcf-01
	 */
	public function record(
		string $caseId,
		DateTimeImmutable $due,
		DateTimeImmutable $sent,
		string $countingMode = WorkingDayRoll::MODE_WORKING_DAYS,
	): array {
		$overrun = 0;
		$status = self::MET;

		if ($sent > $due) {
			$status = self::MISSED;
			$overrun = $this->overrunBetween(due: $due, sent: $sent, countingMode: $countingMode);
		}

		$this->write(
			caseId: $caseId,
			changes: [
				'firstResponseStatus' => $status,
				'firstResponseOverrunDays' => $overrun,
				'firstResponseAt' => $sent->format('Y-m-d\TH:i:sP'),
			]
		);

		return ['status' => $status, 'overrunDays' => $overrun];
	}//end record()

	/**
	 * How many first responses were missed, and by how much on average.
	 *
	 * The average is computed over the STORED overruns rather than recomputed
	 * from dates, because the dates behind a year-old case may have moved and
	 * the number recorded at the time is the one that was true.
	 *
	 * @param array<string, mixed> $filters Case filters, such as a case type or an organisation.
	 *
	 * @return array{missed: int, met: int, averageOverrunDays: float} The report.
	 *
	 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#requirement-a-case-type-declares-a-first-response-term-and-the-overrun-is-stored-req-tcf-01
	 */
	public function report(array $filters = []): array {
		$missed = 0;
		$met = 0;
		$total = 0;

		foreach ($this->casesWithAnOutcome(filters: $filters) as $case) {
			$status = (string)($case['firstResponseStatus'] ?? '');
			if ($status === self::MET) {
				$met++;
				continue;
			}

			if ($status !== self::MISSED) {
				continue;
			}

			$missed++;
			$total += max(0, (int)($case['firstResponseOverrunDays'] ?? 0));
		}

		$average = 0.0;
		if ($missed !== 0) {
			$average = round(($total / $missed), 2);
		}

		return [
			'missed' => $missed,
			'met' => $met,
			'averageOverrunDays' => $average,
		];
	}//end report()

	/**
	 * The cases that carry an outcome at all.
	 *
	 * @param array<string, mixed> $filters The caller's filters.
	 *
	 * @return array<int, array<string, mixed>> The cases.
	 */
	private function casesWithAnOutcome(array $filters): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: $filters
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the first-response report could not be read: ' . $e->getMessage());

			return [];
		}
	}//end casesWithAnOutcome()

	/**
	 * Write the outcome onto the case.
	 *
	 * @param string               $caseId  The case.
	 * @param array<string, mixed> $changes The fields to write.
	 *
	 * @return void
	 */
	private function write(string $caseId, array $changes): void {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return;
		}

		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
				changes: $changes
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the first-response outcome of "' . $caseId . '" was not recorded: ' . $e->getMessage()
			);
		}
	}//end write()

	/**
	 * How far past the promise the response went, in the term's own mode.
	 *
	 * Working days are counted the way the rest of dossiq counts an Awb term:
	 * weekends and Dutch holidays do not count against us, because they do not
	 * count for us either.
	 *
	 * @param DateTimeImmutable $due     When it was promised.
	 * @param DateTimeImmutable $sent    When it went out.
	 * @param string            $countingMode Whether the term counts working or calendar days.
	 *
	 * @return int The overrun.
	 */
	private function overrunBetween(DateTimeImmutable $due, DateTimeImmutable $sent, string $countingMode): int {
		if ($countingMode !== WorkingDayRoll::MODE_WORKING_DAYS) {
			return (int)$due->diff($sent)->days;
		}

		// Counted from the day AFTER the promise. `countWorkingDays()` counts
		// an inclusive range, and the day we promised by is not a day we were
		// late: a response due Wednesday and sent the following Monday is
		// three working days late, not four.
		return $this->workingDays->countWorkingDays(start: $due->modify('+1 day'), end: $sent);
	}//end overrunBetween()

}//end class
