<?php

/**
 * Contract of CaseReviewerAccess
 *
 * The public surface of CaseReviewerAccess that other components call, so they depend
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
 * Contract of CaseReviewerAccess.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseReviewerAccessInterface {

	/**
	 * Whether a grant can reach this user at all.
	 *
	 * OpenRegister keeps a record's schema as the ceiling of a grant, and the
	 * dossiq register is closed to everybody outside the staff group
	 * (case-access-control REQ-ACC-11). A person outside it who is named as a
	 * reviewer gets the grant and still reads nothing; an administrator reads
	 * everything regardless. Asked before somebody is named, so the refusal
	 * comes with a sentence instead of an empty screen.
	 *
	 * @param string $userId The user about to be named as a reader.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reader-is-somebody-the-register-admits-req-cra-009
	 */
	public function isAdmittedReader(string $userId): bool;

	/**
	 * The refusal of a change of hands by someone who does not supervise, or null when it may go ahead.
	 *
	 * Only a case whose case type keeps records private, and that has such
	 * records, is guarded: the assignee reads every one of them, so handing the
	 * case on hands those records on. A supervisor or an administrator may.
	 *
	 * @param array<string, mixed> $case   The case as it stood before the change, with its `id`
	 *                                     (a case without one has no records to guard).
	 * @param string               $userId The caller.
	 *
	 * @return array{error: string, message: string}|null
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-with-private-records-changes-hands-only-through-a-supervisor-req-cra-005
	 */
	public function reassignRefusal(array $case, string $userId): ?array;

	/**
	 * Make the grants of every declared record of the case match the people on it.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{status: string, granted: int, revoked: int, refused: int}
	 *         `status` is `reconciled`, `not-declared` (the case type keeps nothing private)
	 *         or `unavailable` (no per-object sharing in this OpenRegister; nothing written).
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
	 */
	public function reconcile(string $caseId): array;

	/**
	 * Reconcile the case when a writer created declared records; nothing when it created none.
	 *
	 * The record is already stored when this runs, so a reconcile that cannot
	 * run never undoes the write: it is logged and answered as `failed`, with
	 * the record left without its grants, which is the closed side. An
	 * administrator's reconcile picks it up (REQ-CRA-004).
	 *
	 * @param string $caseId  The case uuid.
	 * @param int    $created How many records the writer created.
	 *
	 * @return array{status: string, granted: int, revoked: int, refused: int}
	 *         `status` is `nothing-created`, `failed`, or what reconcile() answers.
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
	 */
	public function reconcileAfterCreate(string $caseId, int $created): array;

	/**
	 * Every record of one kind on a case, read regardless of the caller's own grants.
	 *
	 * For a rule that holds for the case whoever asks (a document sits in one
	 * batch, every rule of the case applies). A listing shown to a person
	 * reads under that person's access instead and never calls this.
	 *
	 * @param string $schemaKey The app setting that names the record's schema.
	 * @param string $caseField The field on the record that holds the case.
	 * @param string $caseId    The case uuid.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-type-says-per-record-who-on-the-case-reads-it-req-cra-008
	 */
	public function recordsOf(string $schemaKey, string $caseField, string $caseId): array;
}//end interface
