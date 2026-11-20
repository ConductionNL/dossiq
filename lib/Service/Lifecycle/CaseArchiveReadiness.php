<?php

/**
 * Whether a case is ready to be archived, item by item, read off its state.
 *
 * The archive dialog draws "Klaar om te archiveren" from this answer. Every
 * item is a fact about the case as it is stored: whether it ended, whether a
 * result is on it, how many decisions it carries and when the latest was
 * taken, whether it was published, how many of its documents are final and
 * how many of its tasks are still open. Nothing is assumed: a
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
 * @spec openspec/specs/case-management/spec.md
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
 * @spec openspec/specs/case-management/spec.md
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
	 * @return array<int, array{id: string, met: bool|null, count?: int, total?: int, detail?: string, date?: string, channel?: string}>
	 *         One item per fact; `met` is null when the fact could not be read.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function checklist(string $caseId, array $case): array {
		return [
			['id' => 'ended', 'met' => $this->endings->isEnded(case: $case)],
			[
				'id' => 'result',
				'met' => trim((string)($case['result'] ?? '')) !== '',
				'detail' => (string)($case['resultPublicLabel'] ?? ''),
			],
			$this->decision(case: $case),
			$this->publication(case: $case),
			$this->documents(caseId: $caseId),
			$this->openTasks(caseId: $caseId),
		];
	}//end checklist()

	/**
	 * How many decisions the case carries, with the date of the latest one when its record can be read.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return array<string, mixed> The item.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function decision(array $case): array {
		$decisions = $case['decisions'] ?? [];
		$ids = [];
		if (is_array($decisions) === true) {
			$ids = array_values(array_filter(array_map('strval', $decisions)));
		}

		$item = ['id' => 'decision', 'met' => $ids !== [], 'count' => count($ids)];
		$date = $this->latestDecisionDate(ids: $ids);
		if ($date !== '') {
			$item['date'] = $date;
		}

		return $item;
	}//end decision()

	/**
	 * The latest decision date of these decisions; a read that fails leaves the date out.
	 *
	 * @param array<int, string> $ids The decision ids.
	 *
	 * @return string The ISO date, or empty.
	 */
	private function latestDecisionDate(array $ids): string {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('decision_schema');
		if ($ids === [] || $objectService === null || $register === '' || $schema === '') {
			return '';
		}

		try {
			$decisions = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_ids' => $ids, '_limit' => count($ids)],
			);
		} catch (Throwable) {
			return '';
		}

		$dates = array_filter(array_map(static fn (array $decision): string => (string)($decision['decisionDate'] ?? ''), $decisions));
		if ($dates === []) {
			return '';
		}

		return max($dates);
	}//end latestDecisionDate()

	/**
	 * Whether the case's decision was published, with when and on which channel.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return array<string, mixed> The item.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function publication(array $case): array {
		$date = (string)($case['publishedAt'] ?? '');
		if ($date === '') {
			return ['id' => 'publication', 'met' => false];
		}

		$publications = $case['publications'] ?? [];
		$latest = [];
		if (is_array($publications) === true && $publications !== []) {
			$latest = (array)end($publications);
		}

		return ['id' => 'publication', 'met' => true, 'date' => $date, 'channel' => (string)($latest['channel'] ?? '')];
	}//end publication()

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
		$store = $this->documentStore();
		if ($store === null) {
			return ['id' => 'documents', 'met' => null];
		}

		try {
			$ids = $this->linkedDocumentIds(store: $store, caseId: $caseId);
			$documents = $this->documentsWithIds(store: $store, ids: $ids);
		} catch (Throwable) {
			return ['id' => 'documents', 'met' => null];
		}

		$final = self::finalCount(documents: $documents);

		return ['id' => 'documents', 'met' => $final === count($ids), 'count' => $final, 'total' => count($ids)];
	}//end documents()

	/**
	 * Where the dossier documents are stored, or null when it is not configured.
	 *
	 * @return DossierDocumentStore|null The store.
	 */
	private function documentStore(): ?DossierDocumentStore {
		$store = new DossierDocumentStore();
		$store->objectService = $this->settingsService->getObjectService();
		$store->register = $this->settingsService->getConfigValue('register');
		$store->joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');
		$store->infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
		if ($store->isComplete() === false) {
			return null;
		}

		return $store;
	}//end documentStore()

	/**
	 * The ids of the documents linked to a case, each once.
	 *
	 * @param DossierDocumentStore $store  The store.
	 * @param string               $caseId The case UUID.
	 *
	 * @return array<int, string> The document ids.
	 */
	private function linkedDocumentIds(DossierDocumentStore $store, string $caseId): array {
		$joins = $this->searchObjectsAsArrays(
			objectService: $store->objectService,
			register: $store->register,
			schema: $store->joinSchema,
			filters: ['case' => $caseId, '_limit' => 500],
		);

		return array_values(array_unique(array_filter(array_map(static fn (array $join): string => (string)($join['informatieobject'] ?? ''), $joins))));
	}//end linkedDocumentIds()

	/**
	 * The documents with these ids, in one read; none without ids.
	 *
	 * @param DossierDocumentStore $store The store.
	 * @param array<int, string>   $ids   The document ids.
	 *
	 * @return array<int, array<string, mixed>> The documents.
	 */
	private function documentsWithIds(DossierDocumentStore $store, array $ids): array {
		if ($ids === []) {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $store->objectService,
			register: $store->register,
			schema: $store->infoSchema,
			filters: ['_ids' => $ids, '_limit' => count($ids)],
		);
	}//end documentsWithIds()

	/**
	 * How many documents are final or archived.
	 *
	 * @param array<int, array<string, mixed>> $documents The documents.
	 *
	 * @return int The count.
	 */
	private static function finalCount(array $documents): int {
		return count(
			array_filter(
				$documents,
				static fn (array $document): bool => in_array((string)($document['status'] ?? ''), ['final', 'archived'], true) === true
			)
		);
	}//end finalCount()

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
