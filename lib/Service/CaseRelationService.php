<?php

/**
 * Dossiq Case Relation (peer / relevanteAndereZaken) Service
 *
 * Typed peer relations between cases, per RGBZ/ZRC `relevanteAndereZaken`.
 * Relations are typed with an `aardRelatie` (`vervolg` | `subject` |
 * `bijdrage` | `samenhang`), guarded against self-relations, duplicates and hierarchy
 * overlap, and require OpenRegister read access to both cases.
 *
 * A link is written ONCE, on the case that declared it, in two places that one
 * save keeps together: the property named by
 * {@see CaseRelationCodec::TYPED_PROPERTIES}, which is a real reference and is
 * therefore what OpenRegister indexes, and `case.relatedCases`, the RGBZ list
 * that carries the clarification. The far side is not written at all. It is
 * read back from OpenRegister's `/used`, where the schema's declared
 * `inverseLabel` names it.
 *
 * That is the change openregister#3764 made possible. Before it, the
 * counterpart was written the same `aardRelatie` as the near side, so a case
 * that followed another read as "vervolg" from the case it followed: one name
 * for two ends, and a reverse panel that could only ever show the near half.
 *
 * This service is the ONLY writer of `relatedCases` and of the typed
 * properties: it keeps them consistent on add, remove and delete-cleanup, and
 * promotes direct field writes (e.g. the ZGW inbound path) into typed links.
 *
 * Hierarchy (hoofdzaak/deelzaak, the `parentCase` field) stays the concern of
 * {@see DeelzaakService}; this service refuses to mirror it as a peer relation.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Relation\CaseHierarchyOverlapGuard;
use OCA\Dossiq\Service\Relation\CaseRelationCodec;
use OCA\Dossiq\Service\Relation\CaseRelationLabels;
use OCA\Dossiq\Service\Relation\CaseRelationStore;

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
	public const RELATION_TYPES = ['vervolg', 'subject', 'bijdrage', 'samenhang', 'waitsOn'];

	/**
	 * A case waits on another case, and that other case blocks it.
	 *
	 * The pair carries a consequence the other four do not: a term that moves
	 * on the case being waited on is offered to the handler of the case
	 * waiting. Named here because the listener that makes the offer asks this
	 * service which links are of this kind, and a literal in two files is a
	 * literal that gets changed in one.
	 *
	 * @var string
	 */
	public const RELATION_WAITS_ON = 'waitsOn';

	/**
	 * The one peer relation that genuinely reads the same from both ends.
	 *
	 * Cases opened by one intake submission belong together without one
	 * leading the other, so the schema declares `samenhang` symmetric and
	 * openregister refuses an inverse label on it. The other three are
	 * directed, and each names what it is called from the far side.
	 *
	 * @var string
	 */
	public const RELATION_SAMENHANG = 'samenhang';

	/**
	 * Constructor.
	 *
	 * @param CaseRelationStore $store OpenRegister reads/writes for case objects.
	 * @param CaseRelationCodec $codec Relation-list encoding and pair operations.
	 * @param CaseHierarchyOverlapGuard $hierarchyGuard Hoofdzaak/deelzaak overlap detection.
	 * @param CaseRelationLabels $labels What each link is called from each side.
	 */
	public function __construct(
		private readonly CaseRelationStore $store,
		private readonly CaseRelationCodec $codec,
		private readonly CaseHierarchyOverlapGuard $hierarchyGuard,
		private readonly CaseRelationLabels $labels,
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

		$stored = $this->codec->decode(case: $case);
		$rows   = $this->labels->rowsFor(caseId: $caseId, stored: $stored);

		// Anything written before the case schema declared its relation types
		// lives only in `relatedCases`, on both cases, under one name. It is
		// still shown, and it is deliberately NOT given a direction: the old
		// mirror recorded none, so any direction chosen here would be invented.
		foreach ($stored as $entry) {
			$targetId = (string)($entry['caseId'] ?? '');
			$type     = (string)($entry['aardRelatie'] ?? '');
			if ($targetId === '' || isset($rows[$targetId.'|'.$type]) === true) {
				continue;
			}

			$rows[$targetId.'|'.$type] = array_merge(
				$entry,
				[
					'direction'    => null,
					'label'        => null,
					'inverseLabel' => null,
					'displayLabel' => null,
					'legacy'       => true,
				]
			);
		}

		return array_values($rows);
	}//end listRelations()

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

		$originRelations = $this->codec->decode(case: $target);
		// A link now has a near end and a far end, so the same type declared
		// from both cases is two contradictory statements rather than one
		// relation seen twice: A follows B and B follows A cannot both be
		// true. The mirror used to make this collide with the duplicate check
		// by accident; now it is checked on purpose.
		if ($this->codec->hasPair(relations: $originRelations, caseId: $caseId, natureRelationship: $natureRelationship) === true
			|| in_array(
				$caseId,
				($this->codec->typedLinks(case: $target)[$this->codec->typedProperty(natureRelationship: $natureRelationship) ?? ''] ?? []),
				true
			) === true
		) {
			return ['ok' => false, 'reason' => 'duplicate'];
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

		// The link is written ONCE, on the case that declared it, into the
		// property whose relation type names both halves. The far side is not
		// written at all: it is discovered through OpenRegister's `/used`, and
		// it reads as the inverse label the schema declares. Writing the same
		// `aardRelatie` on the counterpart, which is what this did before, is
		// what made a `vervolg` read as "vervolg" from the case it followed.
		$this->store->persistRelations(
			case: $origin,
			relations: $originRelations,
			typedLinks: $this->codec->withTypedLink(
				links: $this->codec->typedLinks(case: $origin),
				natureRelationship: $natureRelationship,
				targetId: $targetId
			)
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
		$this->store->persistRelations(
			case: $origin,
			relations: $originRelations,
			typedLinks: $this->codec->withoutTypedLink(
				links: $this->codec->typedLinks(case: $origin),
				natureRelationship: $natureRelationship,
				targetId: $targetId
			)
		);

		$targetRelations = $this->codec->removePair(
			relations: $this->codec->decode(case: $target),
			caseId: $caseId,
			natureRelationship: $natureRelationship
		);
		$this->store->persistRelations(
			case: $target,
			relations: $targetRelations,
			typedLinks: $this->codec->withoutTypedLink(
				links: $this->codec->typedLinks(case: $target),
				natureRelationship: $natureRelationship,
				targetId: $caseId
			)
		);

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

		$updated = 0;
		foreach ($this->counterpartIdsOf(caseId: $caseId) as $counterpartId) {
			$counterpart = $this->store->fetchCase(caseUuid: $counterpartId);
			if ($counterpart === null) {
				continue;
			}

			$relations = $this->codec->decode(case: $counterpart);
			$stripped = $this->codec->removeAllForCase(relations: $relations, caseId: $caseId);

			$links    = $this->codec->typedLinks(case: $counterpart);
			$unlinked = $this->codec->withoutTypedLink(
				links: $links,
				natureRelationship: null,
				targetId: $caseId
			);

			if (count($stripped) !== count($relations) || $unlinked !== $links) {
				$this->store->persistRelations(
					case: $counterpart,
					relations: $stripped,
					typedLinks: $unlinked
				);
				$updated++;
			}
		}//end foreach

		return $updated;
	}//end cleanupForDeletedCase()

	/**
	 * Every case that has to be touched when one case is deleted.
	 *
	 * Two sources, and both are needed. This case's own list names what IT
	 * declared. `/used` names the cases that declared a link TOWARDS it, which
	 * its own list no longer mentions now that the counterpart write is gone.
	 * The mirror used to make that half complete by accident; without the
	 * reverse read a link declared from the far side would survive the case it
	 * points at.
	 *
	 * @param string $caseId The case being deleted.
	 *
	 * @return array<int, string> The counterpart uuids, de-duplicated.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	private function counterpartIdsOf(string $caseId): array {
		$ids = [];

		// Even when the case is already gone we still scan counterparts: the
		// relation entries on OTHER cases are what must be cleaned up.
		$deleted = $this->store->fetchCase(caseUuid: $caseId);
		if ($deleted !== null) {
			foreach ($this->codec->decode(case: $deleted) as $relation) {
				$ids[] = (string)($relation['caseId'] ?? '');
			}
		}

		foreach ($this->store->relationRows(caseUuid: $caseId, incoming: true) as $row) {
			$ids[] = (string)($row['id'] ?? ($row['uuid'] ?? ''));
		}

		return array_values(
			array_unique(
				array_filter(
					$ids,
					static fn (string $ref): bool => ($ref !== '' && $ref !== $caseId)
				)
			)
		);
	}//end counterpartIdsOf()

	/**
	 * Promote a direct write to `relatedCases` into typed links (e.g. ZGW inbound).
	 *
	 * The ZGW mapping layer writes `relatedCases` straight onto the case, which
	 * leaves the link invisible to OpenRegister: the field is one JSON-encoded
	 * string, and nothing scans inside a string for references. This copies each
	 * entry into the property that carries its type, so a relation that arrived
	 * over ZGW reads from both ends exactly like one created here.
	 *
	 * What it no longer does is write the same `aardRelatie` back onto the
	 * counterpart. That mirror was what made a `vervolg` read as "vervolg" from
	 * the case that was followed, and the far side now comes from `/used` with
	 * the inverse label the schema declares.
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

		$relations = $this->codec->decode(case: $case);
		$links     = $this->codec->typedLinks(case: $case);
		$promoted  = $links;

		foreach ($relations as $relation) {
			$targetId = (string)($relation['caseId'] ?? '');
			$natureRelationship = (string)($relation['aardRelatie'] ?? '');
			if ($targetId === '' || $targetId === $caseId
				|| in_array($natureRelationship, self::RELATION_TYPES, true) === false
			) {
				continue;
			}

			$promoted = $this->codec->withTypedLink(
				links: $promoted,
				natureRelationship: $natureRelationship,
				targetId: $targetId
			);
		}//end foreach

		if ($promoted !== $links) {
			$this->store->persistRelations(
				case: $case,
				relations: $relations,
				typedLinks: $promoted
			);
		}
	}//end normalise()
}//end class
