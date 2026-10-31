<?php

/**
 * Whether a case is ready to be archived, item by item, read off its state.
 *
 * The archive dialog draws "Klaar om te archiveren" from this answer. Every
 * item is a fact about the case as it is stored: whether it ended, whether a
 * result is on it, how many decisions it carries, how many of its documents
 * are final and how many of its tasks are still open. Nothing is assumed: a
 * task list the engine could not read is reported as unknown, never as zero.
 *
 * Only `ended` decides. {@see CaseEndingActs::archive()} refuses an open case
 * and nothing else, so the other items inform the archivist and block nothing;
 * a dialog that refused on them would be a second, stricter archive rule.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCP\IUserSession;
use Throwable;

/**
 * The archive checklist of one case.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
class CaseArchiveReadiness {

	use SearchesObjects;

	/**
	 * The task states from which a task never moves again (OpenRegister's Task::TERMINAL_STATES).
	 *
	 * @var array<int, string>
	 */
	public const TERMINAL_TASK_STATES = ['completed', 'terminated', 'disabled'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The register and the document schemas.
	 * @param EngineTaskInbox $tasks           The tasks on the case.
	 * @param IUserSession    $userSession     The reader, for the task engine.
	 * @param CaseEndingActs  $endings         Says whether the case has ended, as the archive act asks.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly EngineTaskInbox $tasks,
		private readonly IUserSession $userSession,
		private readonly CaseEndingActs $endings,
	) {
	}//end __construct()

	/**
	 * The checklist of a loaded case.
	 *
	 * @param string               $caseId The case UUID.
	 * @param array<string, mixed> $case   The loaded case.
	 *
	 * @return array<int, array{id: string, met: bool|null, count?: int, total?: int, detail?: string}>
	 *         One item per fact; `met` is null when the fact could not be read.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function checklist(string $caseId, array $case): array {
		$decisions = $case['decisions'] ?? [];
		$decisionCount = 0;
		if (is_array($decisions) === true) {
			$decisionCount = count($decisions);
		}

		return [
			['id' => 'ended', 'met' => $this->endings->isEnded(case: $case)],
			[
				'id' => 'result',
				'met' => trim((string)($case['result'] ?? '')) !== '',
				'detail' => (string)($case['resultPublicLabel'] ?? ''),
			],
			['id' => 'decision', 'met' => $decisionCount > 0, 'count' => $decisionCount],
			$this->documents(caseId: $caseId),
			$this->openTasks(caseId: $caseId),
		];
	}//end checklist()

	/**
	 * How many of the case's documents are final: one read of the case's
	 * document links and one of those documents, rather than a read per
	 * document.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The item.
	 */
	private function documents(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');
		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
		if ($objectService === null || $register === '' || $joinSchema === '' || $infoSchema === '') {
			return ['id' => 'documents', 'met' => null];
		}

		try {
			$joins = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $joinSchema,
				filters: ['case' => $caseId, '_limit' => 500],
			);
			$ids = array_values(array_unique(array_filter(array_map(static fn (array $join): string => (string)($join['informatieobject'] ?? ''), $joins))));
			$documents = [];
			if ($ids !== []) {
				$documents = $this->searchObjectsAsArrays(
					objectService: $objectService,
					register: $register,
					schema: $infoSchema,
					filters: ['_ids' => $ids, '_limit' => count($ids)],
				);
			}
		} catch (Throwable) {
			return ['id' => 'documents', 'met' => null];
		}

		$final = count(
			array_filter(
				$documents,
				static fn (array $document): bool => in_array((string)($document['status'] ?? ''), ['final', 'archived'], true) === true
			)
		);

		return ['id' => 'documents', 'met' => $final === count($ids), 'count' => $final, 'total' => count($ids)];
	}//end documents()

	/**
	 * How many of the case's tasks are still open, or unknown when the engine did not answer.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The item.
	 */
	private function openTasks(string $caseId): array {
		$actor = (string)($this->userSession->getUser()?->getUID() ?? '');
		$tasks = $this->tasks->forCase(caseId: $caseId, actor: $actor);
		if ($this->tasks->lastError() !== '') {
			return ['id' => 'openTasks', 'met' => null];
		}

		$open = count(
			array_filter(
				$tasks,
				static fn (array $task): bool => in_array((string)($task['status'] ?? ''), self::TERMINAL_TASK_STATES, true) === false
			)
		);

		return ['id' => 'openTasks', 'met' => $open === 0, 'count' => $open];
	}//end openTasks()
}//end class
