<?php

/**
 * Contract of AwardProposal
 *
 * The public surface of AwardProposal that other components call, so they depend
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
 * Contract of AwardProposal.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AwardProposalInterface {

	/**
	 * Propose one admissible bid of the case for the award of its lot.
	 *
	 * @param string $caseId       The case uuid.
	 * @param string $submissionId The bid uuid.
	 *
	 * @return array<string, mixed> `{proposed, released}` or `{refusal}`.
	 *
	 * @throws \RuntimeException When the register cannot be reached or refuses the write.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/specs/dossiq-procurement-tender-management/spec.md#requirement-a-tender-is-worked-end-to-end-on-its-case-page-req-tnd-case-01
	 */
	public function propose(string $caseId, string $submissionId): array;
}//end interface
