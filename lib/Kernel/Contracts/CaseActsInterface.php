<?php

/**
 * Contract of CaseActs
 *
 * The public surface of CaseActs that other components call, so they depend
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
 * Contract of CaseActs.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseActsInterface {

	/**
	 * Abort the case: an intrekking, and no besluit.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why the case was aborted.
	 * @param string $resultTypeId The result type, e.g. Ingetrokken.
	 * @param string $toStatus The terminal status to land on, resolved when empty.
	 *
	 * @return array<string, mixed> What was recorded.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function abort(string $caseId, string $reason, string $resultTypeId, string $toStatus): array;

	/**
	 * Archive a finished case: commit the retention its result type carries.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is being archived.
	 *
	 * @return array<string, mixed> The nomination and action date written.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function archive(string $caseId, string $reason): array;

	/**
	 * Make this case a draft: no term, no list, its author only.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> How the case now reads.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function draft(string $caseId): array;

	/**
	 * Finish the case: it reached its result.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why, recorded on the case.
	 * @param string $resultTypeId The result type the handler picked.
	 * @param string $toStatus The terminal status to land on, resolved when empty.
	 *
	 * @return array<string, mixed> What was recorded.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function finish(string $caseId, string $reason, string $resultTypeId, string $toStatus): array;

	/**
	 * Hold the case until a date.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is not being worked.
	 * @param string $until The date it returns, as Y-m-d.
	 *
	 * @return array<string, mixed> The hold as it was recorded.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function hold(string $caseId, string $reason, string $until): array;

	/**
	 * Promote a draft into a case, binding the term.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The bound term and the kept draft moment.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function promote(string $caseId): array;

	/**
	 * Record which required fields were knowingly left empty.
	 *
	 * @param string $caseId The case UUID.
	 * @param list<string> $fields The field names left empty.
	 *
	 * @return array<string, mixed> How the case now reads.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function recordIncompleteness(string $caseId, array $fields): array;

	/**
	 * Take the case off hold before its date.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is being picked up again.
	 *
	 * @return array<string, mixed> The hold state afterwards.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function releaseHold(string $caseId, string $reason): array;

	/**
	 * Take the case back out of the archive.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is coming back.
	 *
	 * @return array<string, mixed> The archive status it carries afterwards.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function unarchive(string $caseId, string $reason): array;
}//end interface
