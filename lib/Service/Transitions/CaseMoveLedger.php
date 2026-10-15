<?php

/**
 * What a move writes, and what earlier moves wrote
 *
 * The write side of the status-transition engine: the case as it is stored,
 * the conflict check before a save, the closing result, the one save of a
 * move, its status record, its side effects and the history of the case. The
 * engine decides; every read of the store and every write goes through here.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Exception\RefusedException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The store, the closing result and the side effects of a move, behind one class.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class CaseMoveLedger {
	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore $store OpenRegister persistence for the engine.
	 * @param CaseResultWriter $resultWriter Closing-result reader and writer.
	 * @param SideEffectDispatcher $dispatcher Side-effect dispatcher.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly CaseStatusStore $store,
		private readonly CaseResultWriter $resultWriter,
		private readonly SideEffectDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The case as stored, or null when it is not there.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function load(string $caseId): ?array {
		return $this->store->loadCase(caseId: $caseId);
	}//end load()

	/**
	 * Re-load the case immediately before writing and refuse when another change landed in the meantime.
	 *
	 * @param string $caseId The case uuid.
	 * @param int $readVersion The `@self.version` captured at read time.
	 * @param string $currentId The status observed at read time.
	 *
	 * @return array<string, mixed> The freshly loaded case.
	 *
	 * @throws RuntimeException When the case is gone.
	 * @throws RefusedException When its version or its status changed.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function reloadUnchanged(string $caseId, int $readVersion, string $currentId): array {
		$caseAtSave = $this->store->loadCase(caseId: $caseId);
		if ($caseAtSave === null) {
			throw new RuntimeException('case_not_found');
		}

		$versionAtSave = (int)(($caseAtSave['@self']['version'] ?? ($caseAtSave['version'] ?? 0)));
		$statusAtSave = (string)($caseAtSave['status'] ?? '');
		if ($versionAtSave !== $readVersion || $statusAtSave !== $currentId) {
			throw new RefusedException(
				rule: 'transition-conflict',
				sentence: 'Another change reached this case first. Reload it and try again.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		return $caseAtSave;
	}//end reloadUnchanged()

	/**
	 * Write the result a closing move records onto the case payload. Not saved here.
	 *
	 * Returns the case unchanged when the target status is not final. When it
	 * is, the end date is settled, and with a result the archival future too,
	 * so all of it lands in the one save of the move.
	 *
	 * @param array<string, mixed> $case The case payload about to be saved.
	 * @param string $caseId The case uuid.
	 * @param string $toStatus The status being entered.
	 * @param string|null $resultTypeId The chosen result type, or null.
	 *
	 * @return array<string, mixed> The payload, with `endDate`, `result` and the archival fields when closing.
	 *
	 * @throws RuntimeException When a closing move carries no result type.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function close(array $case, string $caseId, string $toStatus, ?string $resultTypeId): array {
		if ($this->resultWriter->isFinalStatus(statusTypeId: $toStatus) === false) {
			return $case;
		}

		$endDate = substr((string)($case['endDate'] ?? ''), 0, 10);
		if ($endDate === '') {
			$endDate = date('Y-m-d');
		}

		$case['endDate'] = $endDate;

		$resultId = $this->resultWriter->resolveClosingResult(
			caseId: $caseId,
			caseTypeId: (string)($case['caseType'] ?? ''),
			resultTypeId: $resultTypeId,
		);
		if ($resultId === null) {
			return $case;
		}

		$case['result'] = $resultId;

		return array_merge(
			$case,
			$this->resultWriter->archivalFuture(case: $case, resultTypeId: (string)$resultTypeId, endDate: $endDate)
		);
	}//end close()

	/**
	 * Save the case in the status it enters, once.
	 *
	 * With a read version the save carries it as `@self.version`, so the store
	 * refuses a write another move got in front of.
	 *
	 * @param array<string, mixed> $case The settled case payload.
	 * @param string $toStatus The status being entered.
	 * @param int|null $readVersion The version captured at read time, or null to save without one.
	 *
	 * @return array{0: array<string, mixed>, 1: int} The saved case and its new version.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function save(array $case, string $toStatus, ?int $readVersion = null): array {
		$case['status'] = $toStatus;
		if ($readVersion !== null) {
			$self = ($case['@self'] ?? null);
			if (is_array($self) === false) {
				$self = [];
			}

			$self['version'] = $readVersion;
			$case['@self'] = $self;
		}

		$saved = $this->store->saveCase(case: $case);

		return [$saved, (int)(($saved['@self']['version'] ?? ($saved['version'] ?? 0)))];
	}//end save()

	/**
	 * Refuse a status that is not one of the case type's.
	 *
	 * @param string $caseTypeId The case type.
	 * @param string $statusTypeId The status.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the status belongs to another case type.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function assertStatusOfCaseType(string $caseTypeId, string $statusTypeId): void {
		$this->store->assertStatusBelongsToCaseType(caseTypeId: $caseTypeId, statusTypeId: $statusTypeId);
	}//end assertStatusOfCaseType()

	/**
	 * Write the status record of a move.
	 *
	 * @param string $caseId The case uuid.
	 * @param array<string, mixed> $move `fromStatus`, `toStatus`, `label`, `comment`, `actor`, `evaluatedGuards`, `noWorkflowTemplate`.
	 *
	 * @return array<string, mixed> The status record.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function record(string $caseId, array $move): array {
		return $this->store->writeStatusRecord(
			caseId: $caseId,
			toStatus: (string)$move['toStatus'],
			fromStatus: (string)$move['fromStatus'],
			label: (string)$move['label'],
			comment: $move['comment'],
			evaluatedGuards: $move['evaluatedGuards'],
			noWorkflowTemplate: $move['noWorkflowTemplate'],
			actor: (string)$move['actor'],
		);
	}//end record()

	/**
	 * Run the actions of a move, in order, with the move and its record as their context.
	 *
	 * @param array<int, array<string, mixed>> $actions The actions to run.
	 * @param array<string, mixed> $case The saved case.
	 * @param array<string, mixed> $move The move, as given to {@see self::record()}.
	 * @param array<string, mixed> $record The status record of the move.
	 *
	 * @return array<int, array<string, mixed>> One result row per action.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function dispatch(array $actions, array $case, array $move, array $record): array {
		return $this->dispatcher->dispatch(
			actions: $actions,
			case: $case,
			transitionContext: [
				'fromStatus' => (string)$move['fromStatus'],
				'toStatus' => (string)$move['toStatus'],
				'transitionLabel' => (string)$move['label'],
				'userId' => (string)$move['actor'],
				'statusRecordUuid' => (string)($record['id'] ?? ''),
			]
		);
	}//end dispatch()

	/**
	 * Write the results of the dispatched actions back onto the status record.
	 *
	 * A record without an id is left as it is. A write that fails is logged at
	 * error and the record is answered as it stood: the move has happened.
	 *
	 * @param array<string, mixed> $record The status record.
	 * @param array<int, array<string, mixed>> $dispatched The result rows.
	 *
	 * @return array<string, mixed> The record, updated when it could be.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function writeBack(array $record, array $dispatched): array {
		$statusRecordId = (string)($record['id'] ?? '');
		if ($statusRecordId === '') {
			return $record;
		}

		$record['dispatchedActions'] = $dispatched;
		try {
			return $this->store->updateStatusRecord(record: $record);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatusTransitionService: dispatchedActions persist failed',
				['exception' => $e->getMessage(), 'statusRecord' => $statusRecordId],
			);
		}

		return $record;
	}//end writeBack()

	/**
	 * The status records of a case, unsorted, or null when they cannot be read.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<int, array<string, mixed>>|null
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function history(string $caseId): ?array {
		return $this->store->findStatusRecords(caseId: $caseId);
	}//end history()
}//end class
