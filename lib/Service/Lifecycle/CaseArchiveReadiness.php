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

use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\IUserSession;
use Throwable;

/**
 * The archive checklist of one case.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
class CaseArchiveReadiness {

	/**
	 * The task states from which a task never moves again (OpenRegister's Task::TERMINAL_STATES).
	 *
	 * @var array<int, string>
	 */
	public const TERMINAL_TASK_STATES = ['completed', 'terminated', 'disabled'];

	/**
	 * Constructor.
	 *
	 * @param ZaakdossierService $dossier     The documents on the case.
	 * @param EngineTaskInbox    $tasks       The tasks on the case.
	 * @param IUserSession       $userSession The reader, for the task engine.
	 */
	public function __construct(
		private readonly ZaakdossierService $dossier,
		private readonly EngineTaskInbox $tasks,
		private readonly IUserSession $userSession,
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
			['id' => 'ended', 'met' => trim((string)($case[CaseEndingActs::ENDING_FIELD] ?? '')) !== ''],
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
	 * How many of the case's documents are final.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The item.
	 */
	private function documents(string $caseId): array {
		try {
			$documents = ($this->dossier->getDossierForCase(caseId: $caseId)['informatieobjecten'] ?? []);
		} catch (Throwable) {
			return ['id' => 'documents', 'met' => null];
		}

		$final = count(
			array_filter(
				$documents,
				static fn (mixed $document): bool => is_array($document) === true
					&& in_array((string)($document['status'] ?? ''), ['final', 'archived'], true) === true
			)
		);

		return ['id' => 'documents', 'met' => $final === count($documents), 'count' => $final, 'total' => count($documents)];
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
