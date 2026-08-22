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
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

/**
 * Builds the from→to transition matrix and detects rework loops.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
class TransitionMatrixBuilder {
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
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function computeTransitionMatrix(array $recordsByCase, array $statusTypeIndex): array {
		$matrix = [];
		$totalCount = 0;
		$reworkSum = 0;

		foreach ($recordsByCase as $records) {
			$transitions = $this->computeCaseTransitions(sortedRecords: $records);
			foreach ($transitions as $transition) {
				$key = ($transition['from'] . '::' . $transition['to']);
				if (isset($matrix[$key]) === false) {
					$matrix[$key] = [
						'from' => $transition['from'],
						'to' => $transition['to'],
						'count' => 0,
						'reworkCount' => 0,
					];
				}

				$matrix[$key]['count']++;
				$totalCount++;
				if ($transition['isRework'] === true) {
					$matrix[$key]['reworkCount']++;
					$reworkSum++;
				}
			}
		}//end foreach

		$out = [];
		foreach ($matrix as $row) {
			$out[] = [
				'from' => $row['from'],
				'fromName' => $this->statusLabel(statusId: $row['from'], statusTypeIndex: $statusTypeIndex),
				'to' => $row['to'],
				'toName' => $this->statusLabel(statusId: $row['to'], statusTypeIndex: $statusTypeIndex),
				'count' => $row['count'],
				'reworkCount' => $row['reworkCount'],
			];
		}

		usort(
			$out,
			static fn (array $left, array $right): int => ($right['count'] <=> $left['count'])
		);

		$reworkPercent = 0.0;
		if ($totalCount !== 0) {
			$reworkPercent = round((($reworkSum / $totalCount) * 100), 1);
		}

		return [
			'matrix' => $out,
			'reworkPercent' => $reworkPercent,
			'totalCount' => $totalCount,
		];
	}//end computeTransitionMatrix()

	/**
	 * Walk one case's chronologically sorted statusRecords into
	 * from→to transition pairs, flagging any transition that revisits a
	 * status the case had already left earlier (a rework loop).
	 *
	 * @param array<int, array<string, mixed>> $sortedRecords Chronologically sorted statusRecords for one case.
	 *
	 * @return array<int, array{from: string, to: string, isRework: bool}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function computeCaseTransitions(array $sortedRecords): array {
		$count = count($sortedRecords);
		if ($count < 2) {
			return [];
		}

		$visited = [];
		$first = (string)($sortedRecords[0]['statusType'] ?? '');
		if ($first !== '') {
			$visited[$first] = true;
		}

		$transitions = [];
		for ($i = 1; $i < $count; $i++) {
			$from = (string)($sortedRecords[$i - 1]['statusType'] ?? '');
			$to = (string)($sortedRecords[$i]['statusType'] ?? '');
			if ($from === '' || $to === '') {
				continue;
			}

			$isRework = isset($visited[$to]);
			$transitions[] = [
				'from' => $from,
				'to' => $to,
				'isRework' => $isRework,
			];
			$visited[$to] = true;
		}

		return $transitions;
	}//end computeCaseTransitions()

	/**
	 * Resolve a statusType id to its human-readable label.
	 *
	 * @param string $statusId StatusType UUID.
	 * @param array<string, array<string, mixed>> $statusTypeIndex StatusType rows, keyed by id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	private function statusLabel(string $statusId, array $statusTypeIndex): string {
		if (isset($statusTypeIndex[$statusId]) === false) {
			return $statusId;
		}

		$entry = $statusTypeIndex[$statusId];
		$label = ($entry['name'] ?? ($entry['title'] ?? ''));
		if (is_string($label) === true && $label !== '') {
			return $label;
		}

		return $statusId;
	}//end statusLabel()
}//end class
