<?php

/**
 * A file uploaded inside a task form belongs to the work until the work is done.
 *
 * Before the task completes the file is evidence for a piece of work in
 * progress: a draft verslag, a photo somebody is still checking. After it, the
 * same file is a document on the case and belongs in the archive. So it is
 * held against the task while the task is open, is removable while it is open,
 * and becomes a `caseDocument` recording the task that produced it when the
 * task completes.
 *
 * WHERE THE HOLD LIVES, AND WHY NOT ON THE TASK
 * ---------------------------------------------
 * The task record is OpenRegister's and dossiq owns none of it. The engine has
 * an `evidence` column, but it is writable only through `create`, `import` and
 * `complete`: there is no seam that adds a file to an OPEN task, and none that
 * takes one off again. Read against openregister on 2026-09-14, and it is the
 * half this change hands to openregister rather than shadowing.
 *
 * So the hold lives on the CASE, in `case.taskAttachments`, which is dossiq's
 * own object and dossiq's own bookkeeping of work in progress. It carries no
 * task state: no status, no assignee, no number and no lock, which is exactly
 * what `remove-casetask` deleted a schema to stop. It says only which file is
 * waiting on which task.
 *
 * 🔑 A HELD FILE IS NOT A CASE DOCUMENT, AND NO READER HAD TO LEARN THAT. The
 * case's documents are its `caseDocument` objects, and one is created at
 * completion and not before. A reader that filtered held documents out would
 * be a filter every reader has to grow and one of them eventually forgets.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Task
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Task;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Holds a file against an open task and publishes it to the case on completion.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
class TaskAttachmentService {

	use SearchesObjects;

	/**
	 * The case property holding the files that are waiting on a task.
	 *
	 * @var string
	 */
	public const HOLD = 'taskAttachments';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The register/schema configuration and the object service.
	 * @param LoggerInterface $logger   The logger.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The files held against one open task.
	 *
	 * @param array<string, mixed> $case   The case.
	 * @param string               $taskId The task.
	 *
	 * @return array<int, array<string, mixed>> The held files, in upload order.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	public function heldFor(array $case, string $taskId): array {
		$wanted = trim($taskId);

		return array_values(
			array_filter(
				self::allHeld(case: $case),
				static fn (array $held): bool => trim((string)($held['task'] ?? '')) === $wanted
			)
		);
	}//end heldFor()

	/**
	 * Hold one file against an open task.
	 *
	 * @param string               $caseId   The case.
	 * @param string               $taskId   The task the file waits on.
	 * @param array<string, mixed> $file     The file: `file`, `title` and, optionally, `link`.
	 * @param string               $uploader Who uploaded it.
	 *
	 * @return array<int, array<string, mixed>> The files now held against that task.
	 *
	 * @throws RuntimeException When the case cannot be read or written.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	public function bind(string $caseId, string $taskId, array $file, string $uploader): array {
		$fileId = trim((string)($file['file'] ?? ''));
		if (trim($taskId) === '' || $fileId === '') {
			throw new RuntimeException('task_attachment_incomplete');
		}

		$case = $this->readCase(caseId: $caseId);
		$held = self::allHeld(case: $case);
		$held[] = [
			'task' => trim($taskId),
			'file' => $fileId,
			'title' => trim((string)($file['title'] ?? $fileId)),
			'link' => trim((string)($file['link'] ?? '')),
			'uploadedBy' => $uploader,
			'uploadedAt' => (new DateTimeImmutable())->format('c'),
		];

		$this->writeHold(caseId: $caseId, held: $held);

		return $this->heldFor(case: [self::HOLD => $held], taskId: $taskId);
	}//end bind()

	/**
	 * Take a file off an open task.
	 *
	 * @param string $caseId The case.
	 * @param string $taskId The task.
	 * @param string $fileId The file.
	 *
	 * @return array<int, array<string, mixed>> The files still held against that task.
	 *
	 * @throws RuntimeException When the case cannot be read or written.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	public function release(string $caseId, string $taskId, string $fileId): array {
		$case = $this->readCase(caseId: $caseId);
		$kept = array_values(
			array_filter(
				self::allHeld(case: $case),
				static fn (array $held): bool => (
					trim((string)($held['task'] ?? '')) !== trim($taskId)
					|| trim((string)($held['file'] ?? '')) !== trim($fileId)
				)
			)
		);

		$this->writeHold(caseId: $caseId, held: $kept);

		return $this->heldFor(case: [self::HOLD => $kept], taskId: $taskId);
	}//end release()

	/**
	 * Publish every file held against a completed task to the case.
	 *
	 * Each becomes a `caseDocument` carrying `sourceTask`, which is what keeps
	 * the trail back to the work that produced it, and the hold is cleared so
	 * the same file is not published twice if the event is delivered again.
	 *
	 * @param string $caseId The case.
	 * @param string $taskId The completed task.
	 *
	 * @return integer How many files were published.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	public function publish(string $caseId, string $taskId): int {
		try {
			$case = $this->readCase(caseId: $caseId);
		} catch (RuntimeException $e) {
			$this->logger->error(
				'Dossiq: the case of a completed task could not be read, so its files stay held',
				['case' => $caseId, 'task' => $taskId, 'error' => $e->getMessage()]
			);

			return 0;
		}

		$held = self::allHeld(case: $case);
		$publishing = $this->heldFor(case: $case, taskId: $taskId);
		if ($publishing === []) {
			return 0;
		}

		$published = 0;
		foreach ($publishing as $file) {
			if ($this->asCaseDocument(caseId: $caseId, taskId: $taskId, file: $file) === true) {
				$published++;
			}
		}

		// Only what was actually published leaves the hold. A file whose
		// document write failed stays held rather than disappearing from both
		// places, which is the one outcome a person cannot recover from.
		$publishedFiles = array_map(
			static fn (array $file): string => trim((string)($file['file'] ?? '')),
			array_slice($publishing, 0, $published)
		);
		$this->writeHold(
			caseId: $caseId,
			held: array_values(
				array_filter(
					$held,
					static fn (array $entry): bool => (
						trim((string)($entry['task'] ?? '')) !== trim($taskId)
						|| in_array(trim((string)($entry['file'] ?? '')), $publishedFiles, true) === false
					)
				)
			)
		);

		return $published;
	}//end publish()

	/**
	 * Write one held file to the case as a document of its own.
	 *
	 * @param string               $caseId The case.
	 * @param string               $taskId The task that produced it.
	 * @param array<string, mixed> $file   The held file.
	 *
	 * @return boolean Whether it was written.
	 */
	private function asCaseDocument(string $caseId, string $taskId, array $file): bool {
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue(key: 'register');
		$schema = $this->settings->getConfigValue(key: 'case_document_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			$this->logger->error(
				'Dossiq: no case document schema is configured, so a completed task cannot publish its file',
				['case' => $caseId, 'task' => $taskId]
			);

			return false;
		}

		try {
			$this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: [
					'case' => $caseId,
					'document' => trim((string)($file['file'] ?? '')),
					'title' => trim((string)($file['title'] ?? '')),
					'registrationDate' => (new DateTimeImmutable())->format('c'),
					'sourceTask' => $taskId,
				],
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a completed task could not publish its file to the case',
				['case' => $caseId, 'task' => $taskId, 'error' => $e->getMessage()]
			);

			return false;
		}//end try
	}//end asCaseDocument()

	/**
	 * Every held file on a case, whatever task it waits on.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, array<string, mixed>> The held files.
	 */
	private static function allHeld(array $case): array {
		$raw = ($case[self::HOLD] ?? []);
		if (is_string($raw) === true) {
			// A store that round-trips an array property through a text column
			// hands back the JSON. Reading only the array shape is how a hold
			// silently becomes an empty list and a file is lost.
			$decoded = json_decode($raw, true);
			$raw = [];
			if (is_array($decoded) === true) {
				$raw = $decoded;
			}
		}

		if (is_array($raw) === false) {
			return [];
		}

		return array_values(array_filter($raw, static fn (mixed $entry): bool => is_array($entry) === true));
	}//end allHeld()

	/**
	 * Read the case, or refuse by name.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RuntimeException When it cannot be read.
	 */
	private function readCase(string $caseId): array {
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue(key: 'register');
		$schema = $this->settings->getConfigValue(key: 'case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			throw new RuntimeException('storage_unavailable');
		}

		$case = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: trim($caseId)
		);
		if ($case === null) {
			throw new RuntimeException('case_not_found');
		}

		return $case;
	}//end readCase()

	/**
	 * Write the hold back onto the case, and nothing else.
	 *
	 * @param string                           $caseId The case.
	 * @param array<int, array<string, mixed>> $held   The whole hold.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the case cannot be written.
	 */
	private function writeHold(string $caseId, array $held): void {
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue(key: 'register');
		$schema = $this->settings->getConfigValue(key: 'case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			throw new RuntimeException('storage_unavailable');
		}

		$this->patchObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: trim($caseId),
			changes: [self::HOLD => array_values($held)],
		);
	}//end writeHold()
}//end class
