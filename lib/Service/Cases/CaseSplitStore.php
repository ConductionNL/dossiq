<?php

/**
 * Dossiq case split store.
 *
 * Where a split reads and writes: the register and the schema, the uuid a row
 * carries under either of its two keys, and the case itself with the refusal
 * an unreadable one earns.
 *
 * Split out of {@see CaseSplitExecutor}, which was over its complexity
 * ceiling. What is left there is what a split DOES; what moved here is where
 * it looks.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Custody\DossierRegister;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Transitions\CaseMoveLedger;
use RuntimeException;
use Throwable;

/**
 * Where a case split reads and writes.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitStore {

	use SearchesObjects;

	/**
	 * The register and the configured schemas, read and refused the one shared way.
	 *
	 * @var DossierRegister
	 */
	private readonly DossierRegister $dossierRegister;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param CaseMoveLedger  $ledger          The status engine's write step, for the second case's status.
	 */
	public function __construct(
		SettingsService $settingsService,
		private readonly CaseMoveLedger $ledger,
	) {
		$this->dossierRegister = new DossierRegister(settingsService: $settingsService);
	}//end __construct()

	/**
	 * Move a stored case into a status through the status engine's write step, and record it.
	 *
	 * The case is saved as the acting user, so their rights on the case type
	 * apply. Transition guards are not consulted: the move carries the status
	 * a case already held over to the case split from it.
	 *
	 * @param array<string, mixed>  $case The stored case.
	 * @param array<string, string> $move `toStatus`, `label` and `actor`.
	 *
	 * @return array<string, mixed> The saved case.
	 *
	 * @throws RuntimeException When the case cannot be saved or the move cannot be recorded.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function enterStatus(array $case, array $move): array {
		$caseId = $this->uuidOf(row: $case);
		$closed = $this->ledger->close(case: $case, caseId: $caseId, toStatus: $move['toStatus'], resultTypeId: null);
		[$saved] = $this->ledger->save(case: $closed, toStatus: $move['toStatus']);
		$this->ledger->record(
			caseId: $caseId,
			move: $move + [
				'fromStatus' => (string)($case['status'] ?? ''),
				'comment' => null,
				'evaluatedGuards' => [],
				'noWorkflowTemplate' => true,
			],
		);

		return $saved;
	}//end enterStatus()

	/**
	 * Refuse, before the second case is opened, a status it could not be moved into.
	 *
	 * A closing status needs a result when the case type declares results, and
	 * a split has none to give. The engine's own close step answers that
	 * without writing anything, because no result is named.
	 *
	 * @param array<string, mixed> $source The case being split.
	 *
	 * @return void
	 *
	 * @throws RefusedException `result-type-required` when that status closes a case with a result,
	 *         or `split-unwritable` when the status could not be checked. Nothing is opened either way.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function refuseUnreachableStatus(array $source): void {
		$status = trim((string)($source['status'] ?? ''));
		if ($status === '') {
			return;
		}

		try {
			$this->ledger->close(
				case: ['caseType' => (string)($source['caseType'] ?? '')],
				caseId: '',
				toStatus: $status,
				resultTypeId: null,
			);
		} catch (RefusedException $refused) {
			throw new RefusedException(
				rule: $refused->getRule(),
				sentence: 'This case is closed, and its case type closes a case only with a result, so it cannot be split.'
					. ' Nothing was opened or moved.',
				status: $refused->getStatus(),
				previous: $refused,
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: CaseSplitExecutor::UNWRITABLE,
				sentence: 'We could not check the status of this case, so it was not split. Nothing was opened or moved.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}
	}//end refuseUnreachableStatus()

	/**
	 * Remove a second case that could not take over the status of the first, or else mark it.
	 *
	 * @param array<string, mixed> $newCase The second case as stored.
	 *
	 * @return string What the handler is told: what was left behind, and that nothing was moved.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function undoSecondCase(array $newCase): string {
		$caseId = $this->uuidOf(row: $newCase);
		if ($this->discardCase(caseId: $caseId) === true) {
			return 'The second case could not take over the status of the first, so it was removed again. Nothing was moved.';
		}

		$number = trim((string)($newCase['identifier'] ?? $caseId));
		if ($this->markFailedSplit(case: $newCase) === true) {
			return 'The second case ' . $number . ' could not take over the status of the first and could not be removed.'
				. ' It is titled as a failed split; remove it. Nothing was moved.';
		}

		return 'The second case ' . $number . ' could not take over the status of the first,'
			. ' and could not be removed or marked. Remove it by hand. Nothing was moved.';
	}//end undoSecondCase()

	/**
	 * Remove a case a failed split opened, with any status record written for it.
	 *
	 * Runs with the handler's own rights. Returns false rather than throwing,
	 * so the caller can say what is left behind.
	 *
	 * @param string $caseId The case to remove.
	 *
	 * @return bool True when the case is gone.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	private function discardCase(string $caseId): bool {
		try {
			[$objectService, $register] = $this->context();
			$recordSchema = $this->schema(key: 'status_record_schema');
			$records = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $recordSchema,
				filters: ['case' => $caseId],
			);
			foreach ($records as $record) {
				$objectService->deleteObject(uuid: $this->uuidOf(row: $record), register: $register, schema: $recordSchema);
			}

			return ($objectService->deleteObject(uuid: $caseId, register: $register, schema: $this->schema(key: 'case_schema')) !== false);
		} catch (Throwable) {
			return false;
		}
	}//end discardCase()

	/**
	 * Title a case a failed split opened and could not remove, so a handler finds it.
	 *
	 * @param array<string, mixed> $case The case as stored.
	 *
	 * @return bool True when the title was written.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	private function markFailedSplit(array $case): bool {
		$case['title'] = 'Failed split: ' . (string)($case['title'] ?? '');
		try {
			[$objectService, $register] = $this->context();
			$this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_schema'),
				object: $case,
				uuid: $this->uuidOf(row: $case),
			);

			return true;
		} catch (Throwable) {
			return false;
		}
	}//end markFailedSplit()

	/**
	 * The stored case, or a refusal.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RefusedException When it cannot be read.
	 */
	public function requireCase(string $caseId): array {
		try {
			[$objectService, $register] = $this->context();
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_schema'),
				id: $caseId,
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: CaseSplitExecutor::CASE_UNREADABLE,
				sentence: 'We could not read that case, so it was not split.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if ($case === null) {
			throw new RefusedException(
				rule: CaseSplitExecutor::CASE_UNREADABLE,
				sentence: 'We could not read that case, so it was not split.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $case;
	}//end requireCase()

	/**
	 * The uuid of a stored row, however the register spelled it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or an empty string.
	 */
	public function uuidOf(array $row): string {
		return trim((string)($row['id'] ?? ($row['uuid'] ?? '')));
	}//end uuidOf()

	/**
	 * The object service and the register, or an exception.
	 *
	 * @return array{0: object, 1: string} The service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is absent or unconfigured.
	 */
	public function context(): array {
		return $this->dossierRegister->context();
	}//end context()

	/**
	 * A configured schema, or an exception naming the key.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema id or slug.
	 *
	 * @throws RuntimeException When the key is unset.
	 */
	public function schema(string $key): string {
		return $this->dossierRegister->schema(key: $key);
	}//end schema()
}//end class
