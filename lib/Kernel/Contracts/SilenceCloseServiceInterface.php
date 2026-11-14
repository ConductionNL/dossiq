<?php

/**
 * Contract of SilenceCloseService
 *
 * The public surface of SilenceCloseService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of SilenceCloseService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SilenceCloseServiceInterface {

	/**
	 * Close the case, recording that the product did it.
	 *
	 * It goes through {@see CaseEndingActs} rather than writing the status
	 * itself, so an automatic close is the same kind of act as a handler's:
	 * one status record, one result, one journal entry. The only differences
	 * are the entry's `type` and the empty actor, which is the honest name for
	 * nobody.
	 *
	 * The caller's copy of the case is deliberately NOT a parameter. The act
	 * below reloads it, because `abort()` writes to the case first and a stale
	 * copy written back afterwards would undo the very ending this method just
	 * recorded.
	 *
	 * @param string $caseId The case UUID.
	 * @param array{silentDays: int, period: int} $decision What was decided.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-status-machinery/spec.md
	 */
	public function close(string $caseId, array $decision): void;

	/**
	 * What this case's silence period says should happen today.
	 *
	 * Returns the decision rather than acting on it, so the decision can be
	 * driven one case at a time by a test without a register, a job list or a
	 * clock to stub.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 * @param DateTimeImmutable $today The day being evaluated.
	 *
	 * @return array{action: string, silentDays: int, period: int, closesOn: string}
	 *         `action` is `none`, `warn` or `close`.
	 *
	 * @spec openspec/specs/case-status-machinery/spec.md
	 */
	public function decide(array $case, DateTimeImmutable $today): array;

	/**
	 * Tell the applicant the case is about to close.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<string, mixed> $case The loaded case.
	 * @param string $closesOn The date it will close, as Y-m-d.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-status-machinery/spec.md
	 */
	public function warn(string $caseId, array $case, string $closesOn): void;
}//end interface
