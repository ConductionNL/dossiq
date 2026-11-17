<?php

/**
 * Contract of CaseRecycleService
 *
 * The public surface of CaseRecycleService that other components call, so they depend
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
 * Contract of CaseRecycleService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseRecycleServiceInterface {

	/**
	 * How many deleted rows one page of the lens holds.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 50;

	/**
	 * The deleted cases this caller may see, newest first.
	 *
	 * SCOPED, NOT LISTED WHOLE. The trash holds every deleted case on the
	 * instance, so an unscoped lens would hand any signed-in user the title
	 * and number of every bezwaar anybody ever deleted. A row is shown to an
	 * administrator, to the handler the case was assigned to, and to whoever
	 * deleted it. That mirrors `CaseAccessGuard::hasCaseMutationAccess()`,
	 * read off the stored payload because the live case is gone.
	 *
	 * @param int $limit How many rows to return.
	 * @param int $offset How many rows to skip.
	 * @param string $forUser The caller's user id; an empty id sees nothing.
	 * @param bool $isAdmin Whether the caller is an administrator.
	 * @param string $search A term the number, title or deleter must hold; empty for all.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the caller's
	 * authority, resolved once by the controller and handed down. Reading it
	 * here instead would put an IGroupManager in a class whose whole job is to
	 * read OpenRegister, and would make the scoping untestable without one.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function deletedCases(
		int $limit = self::PAGE_SIZE,
		int $offset = 0,
		string $forUser = '',
		bool $isAdmin = false,
		string $search = '',
	): array;

	/**
	 * One live case, as an associative array, or null.
	 *
	 * The clocks are read off a case that still exists, so this read goes
	 * through the ordinary object path rather than the deleted one.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The case payload, or null.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function liveCase(string $caseId): ?array;

	/**
	 * Restore a deleted case, and record who did it and when.
	 *
	 * The record is written first and the failure to write one is logged
	 * rather than thrown: a restore that worked must not report itself failed
	 * because its record could not be saved. That is the opposite trade-off to
	 * a destruction, and the difference is that a restore can be undone.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> What was restored, and inside which window.
	 *
	 * @throws RuntimeException `case_not_deleted` when the case is not in the recycle state.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function restore(string $caseId): array;
}//end interface
