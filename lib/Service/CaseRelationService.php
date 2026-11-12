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
use OCA\Dossiq\Service\Relation\SubjectLinkRule;
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
	 * @param SubjectLinkRule $subjectRule A case hangs as the subject under one case only, without a loop.
	 */
	public function __construct(
		private readonly CaseRelationStore $store,
		private readonly CaseRelationCodec $codec,
		private readonly CaseHierarchyOverlapGuard $hierarchyGuard,
		private readonly CaseRelationLabels $labels,
		private readonly SubjectLinkRule $subjectRule,
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

		return array_values(
			$this->withLegacyRows(rows: $this->labels->rowsFor(caseId: $caseId, stored: $stored), stored: $stored)
		);
	}//end listRelations()

	/**
	 * The typed rows plus every stored entry they do not already name.
	 *
	 * Anything written before the case schema declared its relation types
	 * lives only in `relatedCases`, on both cases, under one name. It is
	 * still shown, and it is deliberately NOT given a direction: the old
	 * mirror recorded none, so any direction chosen here would be invented.
	 *
	 * @param array<string, array<string, mixed>> $rows   The typed rows, keyed by target and type.
	 * @param array<int, array<string, mixed>>    $stored The decoded `relatedCases` entries.
	 *
	 * @return array<string, array<string, mixed>> The rows, keyed by target and type.
	 */
	private function withLegacyRows(array $rows, array $stored): array {
		foreach ($stored as $entry) {
			$targetId = (string)($entry['caseId'] ?? '');
			$key      = $targetId.'|'.(string)($entry['aardRelatie'] ?? '');
			if ($targetId === '' || isset($rows[$key]) === true) {
				continue;
			}

			$rows[$key] = array_merge(
				$entry,
				[
					'title'        => $this->titleOf(caseId: $targetId),
					'direction'    => null,
					'label'        => null,
					'inverseLabel' => null,
					'displayLabel' => null,
					'legacy'       => true,
				]
			);
		}

		return $rows;
	}//end withLegacyRows()

	/**
	 * The title of the far case of a legacy relation, or '' when unreadable.
	 *
	 * A legacy entry stores only `{caseId, aardRelatie}`, so without this the
	 * Related cases card showed it as its bare uuid. A case that cannot be
	 * read answers '', and the card leaves the row out rather than print an
	 * id a handler cannot use.
	 *
	 * @param string $caseId The far case uuid.
	 *
	 * @return string The title, or ''.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	private function titleOf(string $caseId): string {
		$case = $this->store->fetchCase(caseUuid: $caseId);

		return trim((string)($case['title'] ?? ''));
	}//end titleOf()

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
		$link = ['from' => $caseId, 'to' => $targetId, 'type' => $natureRelationship];
		$rejection = $this->rejectInvalidRelationInput(link: $link);
		if ($rejection !== null) {
			return $rejection;
		}

		$pair    = $this->readablePair(link: $link);
		$refusal = $this->refuseLink(pair: $pair, link: $link);
		if ($refusal !== null) {
			return $refusal;
		}

		$this->writeLink(origin: $pair['origin'], link: $link + ['notes' => $notes]);

		return ['ok' => true];
	}//end addRelation()

	/**
	 * Both cases of a link, each null when it cannot be read.
	 *
	 * @param array{from: string, to: string, type: string} $link The link.
	 *
	 * @return array{origin: array<string, mixed>|null, target: array<string, mixed>|null} The two cases.
	 */
	private function readablePair(array $link): array {
		return [
			'origin' => $this->store->fetchCase(caseUuid: $link['from']),
			'target' => $this->store->fetchCase(caseUuid: $link['to']),
		];
	}//end readablePair()

	/**
	 * Why this link may not be added between these two cases, or null.
	 *
	 * @param array{origin: array<string, mixed>|null, target: array<string, mixed>|null} $pair The two cases.
	 * @param array{from: string, to: string, type: string}                               $link The link.
	 *
	 * @return array{ok: bool, reason: string, detail?: string}|null The refusal.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	private function refuseLink(array $pair, array $link): ?array {
		// OR-RBAC read access to BOTH cases (fail closed on either miss).
		if (in_array(null, $pair, true) === true) {
			return ['ok' => false, 'reason' => 'access_denied'];
		}

		return $this->hierarchyRefusal(pair: $pair)
			?? $this->duplicateRefusal(pair: $pair, link: $link)
			?? $this->subjectRefusal(pair: $pair, link: $link);
	}//end refuseLink()

	/**
	 * The refusal for a pair the hoofdzaak/deelzaak hierarchy already links, or null.
	 *
	 * @param array{origin: array<string, mixed>, target: array<string, mixed>} $pair The two readable cases.
	 *
	 * @return array{ok: bool, reason: string, detail: string}|null The refusal.
	 */
	private function hierarchyRefusal(array $pair): ?array {
		// Hierarchy-overlap guard — the parent/sub-case link already expresses
		// the relation, so refuse to also peer-link the same pair.
		if ($this->hierarchyGuard->areLinked(caseA: $pair['origin'], caseB: $pair['target']) === true) {
			return [
				'ok' => false,
				'reason' => 'hierarchy_overlap',
				'detail' => 'These cases are already linked through the hoofdzaak/deelzaak hierarchy.',
			];
		}

		return null;
	}//end hierarchyRefusal()

	/**
	 * The refusal for a link of a type either case already declares towards the other, or null.
	 *
	 * @param array{origin: array<string, mixed>, target: array<string, mixed>} $pair The two readable cases.
	 * @param array{from: string, to: string, type: string}                     $link The link.
	 *
	 * @return array{ok: bool, reason: string}|null The refusal.
	 */
	private function duplicateRefusal(array $pair, array $link): ?array {
		// A link now has a near end and a far end, so the same type declared
		// from both cases is two contradictory statements rather than one
		// relation seen twice: A follows B and B follows A cannot both be
		// true. The mirror used to make this collide with the duplicate check
		// by accident; now it is checked on purpose.
		if ($this->holdsLink(holder: $pair['target'], edge: ['caseId' => $link['from'], 'type' => $link['type']]) === true
			|| $this->holdsLink(holder: $pair['origin'], edge: ['caseId' => $link['to'], 'type' => $link['type']]) === true
		) {
			return ['ok' => false, 'reason' => 'duplicate'];
		}

		return null;
	}//end duplicateRefusal()

	/**
	 * The subject rule's refusal for a `subject` link, or null for every other type.
	 *
	 * @param array{origin: array<string, mixed>, target: array<string, mixed>} $pair The two readable cases.
	 * @param array{from: string, to: string, type: string}                     $link The link.
	 *
	 * @return array{ok: bool, reason: string, detail?: string}|null The refusal.
	 */
	private function subjectRefusal(array $pair, array $link): ?array {
		if ($link['type'] === 'subject') {
			return $this->subjectRule->refusal(pair: $pair, link: $link);
		}

		return null;
	}//end subjectRefusal()

	/**
	 * Write a link once, on the case that declared it.
	 *
	 * `relatedCases` is ZGW's relevanteAndereZaken. The waits-on pair is not
	 * a ZGW aardRelatie and lives on the relation primitive only. The link is
	 * written into the property whose relation type names both halves. The far
	 * side is not written at all: it is discovered through OpenRegister's
	 * `/used`, and it reads as the inverse label the schema declares. Writing
	 * the same `aardRelatie` on the counterpart, which is what this did before,
	 * is what made a `vervolg` read as "vervolg" from the case it followed.
	 *
	 * @param array<string, mixed>                                         $origin The case that declares it.
	 * @param array{from: string, to: string, type: string, notes: ?string} $link   The link.
	 *
	 * @return void
	 */
	private function writeLink(array $origin, array $link): void {
		$originRelations = $this->codec->decode(case: $origin);
		if ($this->isPrimitiveOnly(natureRelationship: $link['type']) === false) {
			$originRelations[] = $this->codec->buildEntry(
				caseId: $link['to'],
				natureRelationship: $link['type'],
				notes: $link['notes']
			);
		}

		$this->store->persistRelations(
			case: $origin,
			relations: $originRelations,
			typedLinks: $this->codec->withTypedLink(
				links: $this->codec->typedLinks(case: $origin),
				natureRelationship: $link['type'],
				targetId: $link['to']
			)
		);
	}//end writeLink()

	/**
	 * Whether a case already declares a link of this type to another case,
	 * in its relation list or in the typed property the type names.
	 *
	 * @param array<string, mixed>                $holder The case that would hold the link.
	 * @param array{caseId: string, type: string} $edge   The case the link points at, and its type.
	 *
	 * @return bool True when the link is already there.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	private function holdsLink(array $holder, array $edge): bool {
		if ($this->codec->hasPair(
			relations: $this->codec->decode(case: $holder),
			caseId: $edge['caseId'],
			natureRelationship: $edge['type']
		) === true
		) {
			return true;
		}

		$property = ($this->codec->typedProperty(natureRelationship: $edge['type']) ?? '');

		return in_array($edge['caseId'], ($this->codec->typedLinks(case: $holder)[$property] ?? []), true);
	}//end holdsLink()

	/**
	 * Whether a relation type is carried by the relation primitive alone.
	 *
	 * The waits-on pair was declared on OpenRegister's typed relations
	 * (`blockingCases`, relation type `waitsOn`) and has no ZGW equivalent,
	 * so it is never written into `relatedCases`.
	 *
	 * @param string $natureRelationship Relation type.
	 *
	 * @return bool True when the type is never written into `relatedCases`.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-case-can-wait-on-another-case-req-rcl-10
	 */
	private function isPrimitiveOnly(string $natureRelationship): bool {
		return $natureRelationship === self::RELATION_WAITS_ON;
	}//end isPrimitiveOnly()

	/**
	 * Reject a relation request whose inputs cannot form a valid peer relation.
	 *
	 * Returns the failure array to hand straight back to the caller, or null
	 * when the inputs pass every input-only guard.
	 *
	 * @param array{from: string, to: string, type: string} $link The origin, target and relation type.
	 *
	 * @return array{ok: bool, reason?: string}|null
	 */
	private function rejectInvalidRelationInput(array $link): ?array {
		// The first failing check, in this order, is the answer.
		$failing = [
			'invalid_aard_relatie' => in_array($link['type'], self::RELATION_TYPES, true) === false,
			'missing_case_id' => in_array('', [$link['from'], $link['to']], true),
			'self_relation' => $link['from'] === $link['to'],
		];
		$reason = array_search(true, $failing, true);
		if ($reason === false) {
			return null;
		}

		return ['ok' => false, 'reason' => $reason];
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

		$pair = $this->readablePair(link: ['from' => $caseId, 'to' => $targetId, 'type' => $natureRelationship]);
		if (in_array(null, $pair, true) === true) {
			return ['ok' => false, 'reason' => 'access_denied'];
		}

		$this->unlink(holder: $pair['origin'], edge: ['caseId' => $targetId, 'type' => $natureRelationship]);
		$this->unlink(holder: $pair['target'], edge: ['caseId' => $caseId, 'type' => $natureRelationship]);

		return ['ok' => true];
	}//end removeRelation()

	/**
	 * Strip one link of this type from a case, in its list and its typed property.
	 *
	 * @param array<string, mixed>                $holder The case to strip.
	 * @param array{caseId: string, type: string} $edge   The case the link points at, and its type.
	 *
	 * @return void
	 */
	private function unlink(array $holder, array $edge): void {
		$this->store->persistRelations(
			case: $holder,
			relations: $this->codec->removePair(
				relations: $this->codec->decode(case: $holder),
				caseId: $edge['caseId'],
				natureRelationship: $edge['type']
			),
			typedLinks: $this->codec->withoutTypedLink(
				links: $this->codec->typedLinks(case: $holder),
				natureRelationship: $edge['type'],
				targetId: $edge['caseId']
			)
		);
	}//end unlink()

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

		$updated = array_filter(
			$this->counterpartIdsOf(caseId: $caseId),
			fn (string $counterpartId): bool => $this->stripCounterpart(counterpartId: $counterpartId, caseId: $caseId)
		);

		return count($updated);
	}//end cleanupForDeletedCase()

	/**
	 * Strip every link to a deleted case from one counterpart.
	 *
	 * @param string $counterpartId The counterpart.
	 * @param string $caseId        The case being deleted.
	 *
	 * @return bool True when the counterpart was written.
	 */
	private function stripCounterpart(string $counterpartId, string $caseId): bool {
		$counterpart = $this->store->fetchCase(caseUuid: $counterpartId);
		if ($counterpart === null) {
			return false;
		}

		return $this->stripLinksTo(counterpart: $counterpart, caseId: $caseId);
	}//end stripCounterpart()

	/**
	 * Remove every relation and typed link to a case from a counterpart, saving only when one went.
	 *
	 * @param array<string, mixed> $counterpart The counterpart.
	 * @param string               $caseId      The case being deleted.
	 *
	 * @return bool True when the counterpart was written.
	 */
	private function stripLinksTo(array $counterpart, string $caseId): bool {
		$relations = $this->codec->decode(case: $counterpart);
		$stripped  = $this->codec->removeAllForCase(relations: $relations, caseId: $caseId);
		$links     = $this->codec->typedLinks(case: $counterpart);
		$unlinked  = $this->codec->withoutTypedLink(links: $links, natureRelationship: null, targetId: $caseId);
		if ([count($stripped), $unlinked] === [count($relations), $links]) {
			return false;
		}

		$this->store->persistRelations(case: $counterpart, relations: $stripped, typedLinks: $unlinked);

		return true;
	}//end stripLinksTo()

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
		$ids = array_merge(
			$this->declaredCounterpartIds(caseId: $caseId),
			$this->incomingCounterpartIds(caseId: $caseId)
		);

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
	 * The cases a case's own relation list names.
	 *
	 * @param string $caseId The case being deleted.
	 *
	 * @return array<int, string> The ids, possibly empty or repeated.
	 */
	private function declaredCounterpartIds(string $caseId): array {
		// Even when the case is already gone we still scan counterparts: the
		// relation entries on OTHER cases are what must be cleaned up.
		$deleted = $this->store->fetchCase(caseUuid: $caseId);
		if ($deleted === null) {
			return [];
		}

		$ids = [];
		foreach ($this->codec->decode(case: $deleted) as $relation) {
			$ids[] = (string)($relation['caseId'] ?? '');
		}

		return $ids;
	}//end declaredCounterpartIds()

	/**
	 * The cases that declared a link towards a case, read from `/used`.
	 *
	 * @param string $caseId The case being deleted.
	 *
	 * @return array<int, string> The ids, possibly empty or repeated.
	 */
	private function incomingCounterpartIds(string $caseId): array {
		$ids = [];
		foreach ($this->store->relationRows(caseUuid: $caseId, incoming: true) as $row) {
			$ids[] = (string)($row['id'] ?? ($row['uuid'] ?? ''));
		}

		return $ids;
	}//end incomingCounterpartIds()

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
		$case = $this->caseToNormalise(caseId: $caseId);
		if ($case === null) {
			return;
		}

		$normalised = $this->normalisedRelations(case: $case, caseId: $caseId);
		if ($normalised['changed'] === true) {
			$this->store->persistRelations(
				case: $case,
				relations: $normalised['relations'],
				typedLinks: $normalised['typedLinks']
			);
		}
	}//end normalise()

	/**
	 * The case whose relations are to be normalised, or null when there is none.
	 *
	 * @param string $caseId Case UUID, possibly empty.
	 *
	 * @return array<string, mixed>|null The case.
	 */
	private function caseToNormalise(string $caseId): ?array {
		if ($caseId === '') {
			return null;
		}

		return $this->store->fetchCase(caseUuid: $caseId);
	}//end caseToNormalise()

	/**
	 * The case's relation list and typed links once every list entry is promoted.
	 *
	 * @param array<string, mixed> $case   The case.
	 * @param string               $caseId The case's own id.
	 *
	 * @return array{relations: array<int, array<string, mixed>>, typedLinks: array<string, array<int, string>>, changed: bool}
	 *         What to store, and whether it differs from what the case holds.
	 */
	private function normalisedRelations(array $case, string $caseId): array {
		$relations = $this->codec->decode(case: $case);
		$links     = $this->codec->typedLinks(case: $case);
		$promoted  = $this->promotedLinks(case: $case, caseId: $caseId);
		$kept      = $this->keptRelations(relations: $relations);

		return [
			'relations' => $kept,
			'typedLinks' => $promoted,
			'changed' => ([$promoted, count($kept)] !== [$links, count($relations)]),
		];
	}//end normalisedRelations()

	/**
	 * The list entries that stay in `relatedCases` once promoted.
	 *
	 * @param array<int, array<string, mixed>> $relations The decoded list.
	 *
	 * @return array<int, array<string, mixed>> The entries kept, re-indexed.
	 */
	private function keptRelations(array $relations): array {
		// A waits-on entry written into `relatedCases` before the pair moved
		// onto the primitive is promoted and dropped from the list here.
		return array_values(
			array_filter(
				$relations,
				fn (array $relation): bool => $this->isPrimitiveOnly(
					natureRelationship: (string)($relation['aardRelatie'] ?? '')
				) === false
			)
		);
	}//end keptRelations()

	/**
	 * The case's typed links with every valid entry of its list added.
	 *
	 * @param array<string, mixed> $case   The case.
	 * @param string               $caseId The case's own id, never linked to itself.
	 *
	 * @return array<string, array<int, string>> The typed links.
	 */
	private function promotedLinks(array $case, string $caseId): array {
		$promoted = $this->codec->typedLinks(case: $case);
		foreach ($this->codec->decode(case: $case) as $relation) {
			if ($this->isPromotable(relation: $relation, caseId: $caseId) === false) {
				continue;
			}

			$promoted = $this->codec->withTypedLink(
				links: $promoted,
				natureRelationship: (string)($relation['aardRelatie'] ?? ''),
				targetId: (string)($relation['caseId'] ?? '')
			);
		}

		return $promoted;
	}//end promotedLinks()

	/**
	 * Whether a list entry becomes a typed link: it names another case and a known type.
	 *
	 * @param array<string, mixed> $relation The list entry.
	 * @param string               $caseId   The case's own id, never linked to itself.
	 *
	 * @return bool True when the entry is promoted.
	 */
	private function isPromotable(array $relation, string $caseId): bool {
		$targetId = (string)($relation['caseId'] ?? '');
		$natureRelationship = (string)($relation['aardRelatie'] ?? '');

		return in_array($targetId, ['', $caseId], true) === false
			&& in_array($natureRelationship, self::RELATION_TYPES, true) === true;
	}//end isPromotable()
}//end class
