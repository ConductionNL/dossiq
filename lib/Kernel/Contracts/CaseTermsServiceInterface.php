<?php

/**
 * Contract of CaseTermsService
 *
 * The public surface of CaseTermsService that other components call, so they depend
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
 * Contract of CaseTermsService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTermsServiceInterface {

	/**
	 * Bind everything the case type declares beside the statutory term.
	 *
	 * Called after the statutory term is bound, so a case type declaring a
	 * fixed closing date has its statutory end moved onto that date and a case
	 * type declaring none is left alone. The planned end and the internal
	 * target are their own instances, never fields on the case.
	 *
	 * A clock that is declared nowhere is not bound. That is not a silent
	 * failure: `termsForCase()` answers with the clocks that exist, and a case
	 * showing one clock is a case whose type declares one.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $caseTypeId The case type UUID.
	 * @param DateTimeImmutable|null $start When the clocks start (default now).
	 * @param string $plannedStart The planned start date, `Y-m-d`, when the case carries one.
	 * @param array<string, mixed>|null $plannedDefinition The `planned` term definition that
	 *        resolved for the case, as {@see \OCA\Dossiq\Service\Term\TermResolution} answers
	 *        it. Used only when the case type declares no `plannedLeadTime` of its own.
	 *
	 * @return array<int, array<string, mixed>> The instances this call created or moved.
	 *
	 * @spec openspec/specs/termijn-binding/spec.md
	 * @spec openspec/specs/termijn-binding/spec.md
	 */
	public function bindForCase(
		string $caseId,
		string $caseTypeId,
		?DateTimeImmutable $start = null,
		string $plannedStart = '',
		?array $plannedDefinition = null,
	): array;

	/**
	 * The clocks a citizen-facing surface may render (REQ-TERM-063).
	 *
	 * @param string $caseId The case UUID.
	 * @param DateTimeImmutable|null $now The moment to judge against.
	 *
	 * @return array<int, array<string, mixed>> The statutory term, and nothing else.
	 *
	 * @spec openspec/specs/termijn-binding/spec.md
	 */
	public function citizenTermsFor(string $caseId, ?DateTimeImmutable $now = null): array;

	/**
	 * Everything the case page needs about the clocks, in one read.
	 *
	 * The case names its own type and its own phase, so a caller holding a case
	 * id does not have to find either first. This is what the Terms panel reads
	 * and what a list column will read, so the two cannot disagree about a
	 * number neither of them stores.
	 *
	 * @param string $caseId The case UUID.
	 * @param DateTimeImmutable|null $now The moment to judge against (default now).
	 *
	 * @return array{case: string, terms: array<int, array<string, mixed>>,
	 *               progress: array<string, mixed>}
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	public function overviewFor(string $caseId, ?DateTimeImmutable $now = null): array;
}//end interface
