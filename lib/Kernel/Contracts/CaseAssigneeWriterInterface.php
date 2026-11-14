<?php

/**
 * Contract of CaseAssigneeWriter
 *
 * The public surface of CaseAssigneeWriter that other components call, so they depend
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

/**
 * Contract of CaseAssigneeWriter.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseAssigneeWriterInterface {

	/**
	 * A fresh batch id, for a caller that is about to start one act.
	 *
	 * @return string The batch id.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function newBatchId(): string;

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
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function reassignOne(
		string $caseId,
		array $case,
		string $toUser,
		string $actorId,
		string $batchId,
	): bool;
}//end interface
