<?php

/**
 * Dossiq ending landing.
 *
 * Where finishing or aborting a case lands it: the terminal status, the
 * closing result, the end date and the phases it never reached.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Dossiq\Service\Lifecycle;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;

/**
 * Works out where an ending lands the case; the guards on the result still run on an early close.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class EndingLanding {

	/**
	 * Constructor.
	 *
	 * @param CaseResultWriter $results  Resolves the closing result.
	 * @param StatusTypeLookup $statuses The case type's statuses, with their flags.
	 * @param StatusRows       $rows     Reads status rows: their ids, which are final, and their process order.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly CaseResultWriter $results,
		private readonly StatusTypeLookup $statuses,
		private readonly StatusRows $rows,
	) {
	}//end __construct()

	/**
	 * Where the case lands: its terminal status, its result, its end date and the phases it skips.
	 *
	 * The result is resolved BEFORE anything is written. `resolveClosingResult`
	 * refuses a close with no result on a case type that offers some, and a
	 * refusal after the status had moved would leave a case closed with no
	 * outcome, which is the state nobody can read afterwards.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 * @param array<string, string> $request The act's caseId, reason, resultTypeId and toStatus.
	 *
	 * @return array{from: string, status: string, result: ?string, endDate: string, skippedPhases: list<string>} The landing.
	 *
	 * @throws RefusedException When the case type has no terminal status, or no result can be named.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function resolve(array $case, array $request): array {
		$caseTypeId = (string)($case['caseType'] ?? '');
		$fromStatus = (string)($case['status'] ?? '');
		$target = $this->terminalStatus(caseTypeId: $caseTypeId, named: $request['toStatus']);

		$chosenResult = null;
		if ($request['resultTypeId'] !== '') {
			$chosenResult = $request['resultTypeId'];
		}

		$resultId = $this->results->resolveClosingResult(
			caseId: $request['caseId'],
			caseTypeId: $caseTypeId,
			resultTypeId: $chosenResult,
		);

		$landing = [
			'from' => $fromStatus,
			'status' => $target,
			'result' => $resultId,
			'endDate' => (new DateTimeImmutable())->format('Y-m-d'),
		];
		$landing['skippedPhases'] = $this->skippedPhases(caseTypeId: $caseTypeId, landing: $landing);

		return $landing;
	}//end resolve()

	/**
	 * The terminal status the act lands the case on.
	 *
	 * A named status is honoured when it belongs to the case type and is
	 * terminal. An unnamed one resolves to the case type's lowest-ordered
	 * terminal status, which is the one the process was drawn to end on.
	 *
	 * @param string $caseTypeId The case type UUID.
	 * @param string $named The status the caller asked for, possibly empty.
	 *
	 * @return string The statusType UUID to land on.
	 *
	 * @throws RefusedException When the case type declares no terminal status.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function terminalStatus(string $caseTypeId, string $named): string {
		return $this->rows->namedTerminal(terminal: $this->terminalRows(caseTypeId: $caseTypeId), named: $named);
	}//end terminalStatus()

	/**
	 * The case type's terminal status rows, in the order the case type lists them.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return list<array<string, mixed>> The terminal rows.
	 *
	 * @throws RefusedException When the case type declares no terminal status.
	 *
	 * @spec exclude one step of terminalStatus(), which carries the requirement
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function terminalRows(string $caseTypeId): array {
		$terminal = array_values(array_filter(
			$this->statuses->rowsOf(caseTypeId: $caseTypeId),
			fn (array $row): bool => $this->rows->isFinal(row: $row),
		));
		if ($terminal === []) {
			throw new RefusedException(
				rule: 'no-terminal-status',
				sentence: 'This case type has no status to end on.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		return $terminal;
	}//end terminalRows()

	/**
	 * The phases the case never reached, by name.
	 *
	 * Ordered statuses between the one the case is leaving and the terminal one
	 * it lands on. A case closed from its last phase skips nothing and records
	 * nothing, which is why an ordinary close does not gain a field.
	 *
	 * @param string               $caseTypeId The case type UUID.
	 * @param array<string, mixed> $landing    The status the case is leaving as `from`, and the terminal
	 *                                         status it lands on as `status`.
	 *
	 * @return list<string> The skipped phase names, in process order.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function skippedPhases(string $caseTypeId, array $landing): array {
		return $this->rows->skippedNames(
			rows: $this->statuses->rowsOf(caseTypeId: $caseTypeId),
			fromStatus: (string)$landing['from'],
			toStatus: (string)$landing['status']
		);
	}//end skippedPhases()
}//end class
