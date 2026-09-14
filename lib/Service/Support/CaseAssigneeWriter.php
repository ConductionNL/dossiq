<?php

/**
 * Dossiq: reassign ONE case, with its audit entry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * The single-case half of a reassignment, with nothing looping around it.
 *
 * The loop used to live beside this write, in two services that each walked a
 * list. It is OpenRegister's now: the job walks, this writes one case, and the
 * audit entry is stamped exactly where it always was so it cannot drift
 * (D-1).
 *
 * 🔴 `reassignedFrom` is read from the case's OWN assignee, never from a batch
 * value. A hand-picked selection does not guarantee one previous handler, and
 * a batch-level `from` would write an audit trail naming the wrong person for
 * most of the rows.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
class CaseAssigneeWriter {

	use WritesReassignments;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register/schema configuration.
	 * @param LoggerInterface $logger          The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Move one case to another handler.
	 *
	 * @param string               $caseId  The case uuid.
	 * @param array<string, mixed> $case    The stored case, as the job read it.
	 * @param string               $toUser  The receiving handler.
	 * @param string               $actorId Who ordered it.
	 * @param string               $batchId The id shared by every case in this act.
	 *
	 * @return bool Whether the write succeeded.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function reassignOne(
		string $caseId,
		array $case,
		string $toUser,
		string $actorId,
		string $batchId,
	): bool {
		[$objectService, $register] = $this->context();
		$schema = (string)$this->settingsService->getConfigValue('case_schema');

		$batch = new ReassignmentBatch(
			fromUser: trim((string)($case['assignee'] ?? '')),
			toUser: $toUser,
			actorId: $actorId,
			batchId: $batchId,
			now: (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
		);

		return $this->reassignItem(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $caseId,
			item: $case,
			batch: $batch,
		);
	}//end reassignOne()

	/**
	 * A fresh batch id, for a caller that is about to start one act.
	 *
	 * @return string The batch id.
	 */
	public function newBatchId(): string {
		return $this->generateBatchId();
	}//end newBatchId()
}//end class
