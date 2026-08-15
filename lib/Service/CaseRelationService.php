<?php

/**
 * Procest Case Relation (peer / relevanteAndereZaken) Service
 *
 * Typed peer relations between cases on the existing `case.relatedCases`
 * field, per RGBZ/ZRC `relevanteAndereZaken`. Relations are typed with an
 * `aardRelatie` (`vervolg` | `onderwerp` | `bijdrage`), stored symmetrically
 * (visible from both cases), guarded against self-relations, duplicates and
 * hierarchy overlap, and require OpenRegister read access to both cases.
 *
 * This service is the ONLY writer of `relatedCases` — it keeps both sides
 * consistent on add, remove and delete-cleanup, and normalises direct field
 * writes (e.g. the ZGW inbound path) back to symmetry.
 *
 * Hierarchy (hoofdzaak/deelzaak, the `parentCase` field) stays the concern of
 * {@see DeelzaakService}; this service refuses to mirror it as a peer relation.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\Service\Relation\CaseHierarchyOverlapGuard;
use OCA\Procest\Service\Relation\CaseRelationCodec;
use OCA\Procest\Service\Relation\CaseRelationStore;

/**
 * Service for typed peer relations between cases.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
class CaseRelationService {

	/**
	 * Allowed ZRC relation types (`aardRelatie`).
	 *
	 * @var array<int, string>
	 */
	public const RELATION_TYPES = ['vervolg', 'subject', 'bijdrage'];

	/**
	 * Constructor.
	 *
	 * @param CaseRelationStore $store OpenRegister reads/writes for case objects.
	 * @param CaseRelationCodec $codec Relation-list encoding and pair operations.
	 * @param CaseHierarchyOverlapGuard $hierarchyGuard Hoofdzaak/deelzaak overlap detection.
	 */
	public function __construct(
		private readonly CaseRelationStore $store,
		private readonly CaseRelationCodec $codec,
		private readonly CaseHierarchyOverlapGuard $hierarchyGuard,
	) {
	}//end __construct()

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
	public function listRelations(string $caseId): array {
		$case = $this->store->fetchCase(caseUuid: $caseId);
		if ($case === null) {
			return [];
		}

		return $this->codec->decode(case: $case);
	}//end listRelations()

	/**
	 * Add a typed peer relation symmetrically to both cases.
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
	 * false, and none of procest's 85 schemas declares one — so an existing
	 * case never resolved to null for anybody (ConductionNL/.github#372).
	 * Authorisation is now enforced by `CaseAccessGuard` in
	 * `CaseRelationController`, ahead of every call into this service. Do not
	 * re-state the RBAC claim here unless the schemas declare `authorization`
	 * AND a test fails when that declaration is removed.
	 *
	 * @param string $caseId Origin case UUID.
	 * @param string $targetId Target case UUID.
	 * @param string $natureRelationship Relation type.
	 * @param string|null $notes Optional free-text clarification (procest-local).
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
	): array {
		$rejection = $this->rejectInvalidRelationInput(
			caseId: $caseId,
			targetId: $targetId,
			natureRelationship: $natureRelationship
		);
		if ($rejection !== null) {
			return $rejection;
		}

		// OR-RBAC read access to BOTH cases (fail closed on either miss).
		$origin = $this->store->fetchCase(caseUuid: $caseId);
		$target = $this->store->fetchCase(caseUuid: $targetId);
		if ($origin === null || $target === null) {
			return ['ok' => false, 'reason' => 'access_denied'];
		}

		// Hierarchy-overlap guard — the parent/sub-case link already expresses
		// the relation, so refuse to also peer-link the same pair.
		if ($this->hierarchyGuard->areLinked(caseA: $origin, caseB: $target) === true) {
			return [
				'ok' => false,
				'reason' => 'hierarchy_overlap',
				'detail' => 'These cases are already linked through the hoofdzaak/deelzaak hierarchy.',
			];
		}

		$originRelations = $this->codec->decode(case: $origin);
		if ($this->codec->hasPair(relations: $originRelations, caseId: $targetId, natureRelationship: $natureRelationship) === true) {
			return ['ok' => false, 'reason' => 'duplicate'];
		}

		$originRelations[] = $this->codec->buildEntry(
			caseId: $targetId,
			natureRelationship: $natureRelationship,
			notes: $notes
		);
		$this->store->persistRelations(case: $origin, relations: $originRelations);

		// Symmetric counterpart — same type names the link, the UI renders
		// direction-aware labels.
		$this->addInverseRelation(
			target: $target,
			caseId: $caseId,
			natureRelationship: $natureRelationship,
			notes: $notes
		);

		return ['ok' => true];
	}//end addRelation()

	/**
	 * Reject a relation request whose inputs cannot form a valid peer relation.
	 *
	 * Returns the failure array to hand straight back to the caller, or null
	 * when the inputs pass every input-only guard.
	 *
	 * @param string $caseId Origin case UUID.
	 * @param string $targetId Target case UUID.
	 * @param string $natureRelationship Relation type.
	 *
	 * @return array{ok: bool, reason?: string}|null
	 */
	private function rejectInvalidRelationInput(string $caseId, string $targetId, string $natureRelationship): ?array {
		if (in_array($natureRelationship, self::RELATION_TYPES, true) === false) {
			return ['ok' => false, 'reason' => 'invalid_aard_relatie'];
		}

		if ($caseId === '' || $targetId === '') {
			return ['ok' => false, 'reason' => 'missing_case_id'];
		}

		if ($caseId === $targetId) {
			return ['ok' => false, 'reason' => 'self_relation'];
		}

		return null;
	}//end rejectInvalidRelationInput()

	/**
	 * Persist the symmetric counterpart entry on the target case, unless it is
	 * already present.
	 *
	 * @param array<string, mixed> $target Target case object.
	 * @param string $caseId Origin case UUID (the entry's reference).
	 * @param string $natureRelationship Relation type.
	 * @param string|null $notes Optional free-text clarification.
	 *
	 * @return void
	 */
	private function addInverseRelation(
		array $target,
		string $caseId,
		string $natureRelationship,
		?string $notes,
	): void {
		$targetRelations = $this->codec->decode(case: $target);
		if ($this->codec->hasPair(relations: $targetRelations, caseId: $caseId, natureRelationship: $natureRelationship) === false) {
			$targetRelations[] = $this->codec->buildEntry(
				caseId: $caseId,
				natureRelationship: $natureRelationship,
				notes: $notes
			);
			$this->store->persistRelations(case: $target, relations: $targetRelations);
		}
	}//end addInverseRelation()

	/**
	 * Remove a typed peer relation from BOTH cases.
	 *
	 * @param string $caseId Origin case UUID.
	 * @param string $targetId Target case UUID.
	 * @param string $natureRelationship Relation type to remove.
	 *
	 * @return array{ok: bool, reason?: string}
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function removeRelation(string $caseId, string $targetId, string $natureRelationship): array {
		if ($caseId === '' || $targetId === '') {
			return ['ok' => false, 'reason' => 'missing_case_id'];
		}

		$origin = $this->store->fetchCase(caseUuid: $caseId);
		$target = $this->store->fetchCase(caseUuid: $targetId);
		if ($origin === null || $target === null) {
			return ['ok' => false, 'reason' => 'access_denied'];
		}

		$originRelations = $this->codec->removePair(
			relations: $this->codec->decode(case: $origin),
			caseId: $targetId,
			natureRelationship: $natureRelationship
		);
		$this->store->persistRelations(case: $origin, relations: $originRelations);

		$targetRelations = $this->codec->removePair(
			relations: $this->codec->decode(case: $target),
			caseId: $caseId,
			natureRelationship: $natureRelationship
		);
		$this->store->persistRelations(case: $target, relations: $targetRelations);

		return ['ok' => true];
	}//end removeRelation()

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
	public function cleanupForDeletedCase(string $caseId): int {
		if ($caseId === '') {
			return 0;
		}

		$deleted = $this->store->fetchCase(caseUuid: $caseId);
		// Even when the case is already gone we still scan counterparts: the
		// relation entries on OTHER cases are what must be cleaned up.
		$counterpartIds = [];
		if ($deleted !== null) {
			foreach ($this->codec->decode(case: $deleted) as $relation) {
				$ref = (string)($relation['caseId'] ?? '');
				if ($ref !== '' && in_array($ref, $counterpartIds, true) === false) {
					$counterpartIds[] = $ref;
				}
			}
		}

		$updated = 0;
		foreach ($counterpartIds as $counterpartId) {
			$counterpart = $this->store->fetchCase(caseUuid: $counterpartId);
			if ($counterpart === null) {
				continue;
			}

			$relations = $this->codec->decode(case: $counterpart);
			$stripped = $this->codec->removeAllForCase(relations: $relations, caseId: $caseId);

			if (count($stripped) !== count($relations)) {
				$this->store->persistRelations(case: $counterpart, relations: $stripped);
				$updated++;
			}
		}//end foreach

		return $updated;
	}//end cleanupForDeletedCase()

	/**
	 * Restore symmetry after a direct write to `relatedCases` (e.g. ZGW inbound).
	 *
	 * For each relation on the given case, ensures the counterpart case carries
	 * the matching inverse entry. Used by the ZGW inbound path so guards and
	 * symmetry hold even when the field was written directly by the mapping
	 * layer rather than through {@see self::addRelation()}.
	 *
	 * @param string $caseId Case UUID whose relations were written directly.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function normalise(string $caseId): void {
		if ($caseId === '') {
			return;
		}

		$case = $this->store->fetchCase(caseUuid: $caseId);
		if ($case === null) {
			return;
		}

		foreach ($this->codec->decode(case: $case) as $relation) {
			$targetId = (string)($relation['caseId'] ?? '');
			$natureRelationship = (string)($relation['aardRelatie'] ?? '');
			if ($targetId === '' || $targetId === $caseId
				|| in_array($natureRelationship, self::RELATION_TYPES, true) === false
			) {
				continue;
			}

			$target = $this->store->fetchCase(caseUuid: $targetId);
			if ($target === null) {
				continue;
			}

			$targetRelations = $this->codec->decode(case: $target);
			if ($this->codec->hasPair(relations: $targetRelations, caseId: $caseId, natureRelationship: $natureRelationship) === false) {
				$targetRelations[] = ['caseId' => $caseId, 'aardRelatie' => $natureRelationship];
				$this->store->persistRelations(case: $target, relations: $targetRelations);
			}
		}//end foreach
	}//end normalise()
}//end class
