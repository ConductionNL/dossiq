<?php

/**
 * Dossiq TransitionMatrixBuilder.
 *
 * The transition-frequency metric family of the process-mining report: the
 * from→to matrix over the recorded `statusRecord` chain, plus rework-loop
 * detection — a transition whose target status the case had already left
 * earlier in its own history. Split out of ProcessMiningService so that
 * service keeps only the orchestration; the per-case walk and the
 * cross-case tally live here and nowhere else.
 *
 * Pure computation: every input is passed in, nothing is read from
 * OpenRegister.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
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
 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

use OCA\Dossiq\Service\Support\TranslatedText;

/**
 * Builds the from→to transition matrix and detects rework loops.
 *
 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T01
 */
class TransitionMatrixBuilder {
	/**
	 * Constructor.
	 *
	 * @param TranslatedText  $text Reads a translatable status name as text, in the reader's language.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TranslatedText $text = new TranslatedText(),
	) {
	}//end __construct()

	/**
	 * Build the from→to transition frequency matrix and detect rework
	 * loops — a transition whose target status the case had already left
	 * earlier in its own history.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $recordsByCase Chronologically sorted statusRecords, keyed by case id.
	 * @param array<string, array<string, mixed>> $statusTypeIndex StatusType rows, keyed by id.
	 *
	 * @return array{matrix: array<int, array{from: string, fromName: string, to: string,
	 *                toName: string, count: int, reworkCount: int}>, reworkPercent: float, totalCount: int}
	 *
	 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T01
	 */
	public function computeTransitionMatrix(array $recordsByCase, array $statusTypeIndex): array {
		$tally = new TransitionTally();
		foreach ($recordsByCase as $records) {
			foreach ($this->computeCaseTransitions(sortedRecords: $records) as $transition) {
				$this->countTransition(tally: $tally, transition: $transition);
			}
		}

		$reworkPercent = 0.0;
		if ($tally->totalCount !== 0) {
			$reworkPercent = round((($tally->reworkSum / $tally->totalCount) * 100), 1);
		}

		return [
			'matrix' => $this->matrixRows(matrix: $tally->matrix, labels: new StatusLabels(text: $this->text, statusTypeIndex: $statusTypeIndex)),
			'reworkPercent' => $reworkPercent,
			'totalCount' => $tally->totalCount,
		];
	}//end computeTransitionMatrix()

	/**
	 * Count one transition on its from-to row.
	 *
	 * @param TransitionTally                                   $tally      The count so far.
	 * @param array{from: string, to: string, isRework: bool} $transition One transition.
	 *
	 * @return void
	 */
	private function countTransition(TransitionTally $tally, array $transition): void {
		$key = ($transition['from'] . '::' . $transition['to']);
		if (isset($tally->matrix[$key]) === false) {
			$tally->matrix[$key] = [
				'from' => $transition['from'],
				'to' => $transition['to'],
				'count' => 0,
				'reworkCount' => 0,
			];
		}

		$tally->matrix[$key]['count']++;
		$tally->totalCount++;
		if ($transition['isRework'] === true) {
			$tally->matrix[$key]['reworkCount']++;
			$tally->reworkSum++;
		}
	}//end countTransition()

	/**
	 * The matrix rows with their status labels, most frequent first.
	 *
	 * @param array<string, array{from: string, to: string, count: int, reworkCount: int}> $matrix The counted rows.
	 * @param StatusLabels                                                                  $labels The status labels.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function matrixRows(array $matrix, StatusLabels $labels): array {
		$out = [];
		foreach ($matrix as $row) {
			$out[] = [
				'from' => $row['from'],
				'fromName' => $labels->labelOf(statusId: $row['from']),
				'to' => $row['to'],
				'toName' => $labels->labelOf(statusId: $row['to']),
				'count' => $row['count'],
				'reworkCount' => $row['reworkCount'],
			];
		}

		usort(
			$out,
			static fn (array $left, array $right): int => ($right['count'] <=> $left['count'])
		);

		return $out;
	}//end matrixRows()

	/**
	 * Walk one case's chronologically sorted statusRecords into
	 * from→to transition pairs, flagging any transition that revisits a
	 * status the case had already left earlier (a rework loop).
	 *
	 * @param array<int, array<string, mixed>> $sortedRecords Chronologically sorted statusRecords for one case.
	 *
	 * @return array<int, array{from: string, to: string, isRework: bool}>
	 *
	 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T01
	 */
	public function computeCaseTransitions(array $sortedRecords): array {
		$count = count($sortedRecords);
		if ($count < 2) {
			return [];
		}

		$visited = $this->firstVisited(sortedRecords: $sortedRecords);

		return $this->transitionsFrom(sortedRecords: $sortedRecords, count: $count, visited: $visited);
	}//end computeCaseTransitions()

	/**
	 * The statuses visited before the first transition: the case's first status.
	 *
	 * @param array<int, array<string, mixed>> $sortedRecords Chronologically sorted statusRecords for one case.
	 *
	 * @return array<string, bool>
	 */
	private function firstVisited(array $sortedRecords): array {
		$visited = [];
		$first = (string)($sortedRecords[0]['statusType'] ?? '');
		if ($first !== '') {
			$visited[$first] = true;
		}

		return $visited;
	}//end firstVisited()

	/**
	 * Pair each status with the one before it, flagging a return to a visited status.
	 *
	 * @param array<int, array<string, mixed>> $sortedRecords Chronologically sorted statusRecords for one case.
	 * @param int                              $count         How many records there are.
	 * @param array<string, bool>              $visited       The statuses visited so far.
	 *
	 * @return array<int, array{from: string, to: string, isRework: bool}>
	 */
	private function transitionsFrom(array $sortedRecords, int $count, array $visited): array {
		$transitions = [];
		for ($i = 1; $i < $count; $i++) {
			$from = (string)($sortedRecords[$i - 1]['statusType'] ?? '');
			$to = (string)($sortedRecords[$i]['statusType'] ?? '');
			if ($from === '' || $to === '') {
				continue;
			}

			$isRework = isset($visited[$to]);
			$transitions[] = ['from' => $from, 'to' => $to, 'isRework' => $isRework];
			$visited[$to] = true;
		}

		return $transitions;
	}//end transitionsFrom()
}//end class
