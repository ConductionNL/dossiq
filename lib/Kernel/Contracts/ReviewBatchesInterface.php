<?php

/**
 * Contract of ReviewBatches
 *
 * The public surface of ReviewBatches that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of ReviewBatches.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReviewBatchesInterface {

	/**
	 * Create a batch: write it, write it on each review, and give its reviewer a task.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $name The batch's name.
	 * @param string $assignee The reviewer, a Nextcloud user.
	 * @param list<string> $documents The documents, as `BatchSelection::select()` answered them.
	 * @param string $userId Who creates the batch.
	 * @param array<string, string> $filter The filter the documents were taken by, recorded on the batch.
	 *
	 * @return array{
	 *     batch: array<string, mixed>,
	 *     taskCreated: bool,
	 *     progress: array{assessed: int, total: int},
	 *     access: array{status: string, granted: int, revoked: int, refused: int}
	 * } The batch, and how the case's reviewer access was reconciled.
	 *
	 * @throws RefusedException When the batch is incomplete, a document is not on the case or in another open batch, or nothing can be stored.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
	 */
	public function create(string $caseId, string $name, string $assignee, array $documents, string $userId, array $filter = []): array;

	/**
	 * The case's batches, each with its progress: assessed of total.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<string, bool> $assessed The assessed documents as keys.
	 *
	 * @return list<array<string, mixed>> The batches.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
	 */
	public function forCase(string $caseId, array $assessed): array;

	/**
	 * The documents among these that are already in an open batch of the case, with that batch's name.
	 *
	 * @param string $caseId The case UUID.
	 * @param list<string> $documents The documents.
	 *
	 * @return array<string, string> The batch name by document.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
	 */
	public function taken(string $caseId, array $documents): array;
}//end interface
