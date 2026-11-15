<?php

/**
 * Contract of TaskAttachmentService
 *
 * The public surface of TaskAttachmentService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of TaskAttachmentService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TaskAttachmentServiceInterface {

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
	 * @spec openspec/specs/task-management/spec.md
	 */
	public function bind(string $caseId, string $taskId, array $file, string $uploader): array;

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
	 * @spec openspec/specs/task-management/spec.md
	 */
	public function publish(string $caseId, string $taskId): int;

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
	 * @spec openspec/specs/task-management/spec.md
	 */
	public function release(string $caseId, string $taskId, string $fileId): array;
}//end interface
