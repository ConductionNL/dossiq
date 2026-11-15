<?php

/**
 * Contract of CaseRelationService
 *
 * The public surface of CaseRelationService that other components call, so they depend
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
 * Contract of CaseRelationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseRelationServiceInterface {

	/**
	 * Add a typed peer relation to the case that declares it.
	 *
	 * Guards (all fail closed):
	 *   - `aardRelatie` must be one of {@see self::RELATION_TYPES};
	 *   - no self-relation (`caseId == targetId`);
	 *   - no duplicate `{caseId, aardRelatie}` pair;
	 *   - no overlap with an existing direct hoofdzaak/deelzaak hierarchy link;
	 *   - both cases must resolve (a missing case is refused as `access_denied`
	 *     so the endpoint is not an existence oracle).
	 *
	 * ⚠️ This list used to claim the null-check below also enforced per-object
	 * authorisation, *"because the store resolves through the session's
	 * ObjectService, which applies OpenRegister RBAC — an unreadable case
	 * resolves to null"*. That claim was false and the check was INERT:
	 * `PermissionHandler::hasGroupPermission()` returns `true` for a schema
	 * with no `authorization` block and `enforce_default_closed` defaults
	 * false, and none of dossiq's 85 schemas declares one — so an existing
	 * case never resolved to null for anybody (ConductionNL/.github#372).
	 * Authorisation is now enforced by `CaseAccessGuard` in
	 * `CaseRelationController`, ahead of every call into this service. Do not
	 * re-state the RBAC claim here unless the schemas declare `authorization`
	 * AND a test fails when that declaration is removed.
	 *
	 * @param string $caseId Origin case UUID.
	 * @param string $targetId Target case UUID.
	 * @param string $natureRelationship Relation type.
	 * @param string|null $notes Optional free-text clarification (dossiq-local).
	 *
	 * @return array{ok: bool, reason?: string, detail?: string}
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function addRelation(
		string $caseId,
		string $targetId,
		string $natureRelationship,
		?string $notes = null,
	): array;

	/**
	 * Remove every counterpart entry pointing at a case that is being deleted.
	 *
	 * Invoked from the case-deletion path (next to the deelzaak orphan cleanup)
	 * so no dangling references survive. Scans every case whose `relatedCases`
	 * references the deleted UUID and strips those entries.
	 *
	 * @param string $caseId UUID of the case being deleted.
	 *
	 * @return int Number of counterpart cases updated.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function cleanupForDeletedCase(string $caseId): int;

	/**
	 * List the typed peer relations stored on a case.
	 *
	 * Returns the decoded `relatedCases` array; each entry is
	 * `{caseId, aardRelatie, toelichting?}`. Returns `[]` when the case is
	 * missing/unreadable or carries no relations.
	 *
	 * @param string $caseId Case UUID.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function listRelations(string $caseId): array;

	/**
	 * Remove a typed peer relation, whichever case declared it.
	 *
	 * The typed link is one-sided now, so only the case that holds it needs
	 * stripping. Both cases are still written, because a link created before
	 * this change is mirrored in `relatedCases` on both, and unlinking from one
	 * side only would leave the other still showing it.
	 *
	 * @param string $caseId Origin case UUID.
	 * @param string $targetId Target case UUID.
	 * @param string $natureRelationship Relation type to remove.
	 *
	 * @return array{ok: bool, reason?: string}
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function removeRelation(string $caseId, string $targetId, string $natureRelationship): array;
}//end interface
